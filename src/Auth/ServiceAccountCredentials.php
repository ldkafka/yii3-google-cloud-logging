<?php

declare(strict_types=1);

namespace Ldkafka\GoogleCloudLogging\Auth;

use Ldkafka\GoogleCloudLogging\Exception\GoogleCloudException;
use Ldkafka\GoogleCloudLogging\Http\HttpClientInterface;
use Psr\SimpleCache\CacheInterface;
use Throwable;

use function base64_encode;
use function file_exists;
use function file_get_contents;
use function getenv;
use function hash;
use function http_build_query;
use function implode;
use function is_array;
use function is_readable;
use function is_string;
use function json_decode;
use function json_encode;
use function openssl_pkey_get_private;
use function openssl_sign;
use function rtrim;
use function sprintf;
use function strtr;
use function time;

/**
 * OAuth2 access tokens for a Google service account, from its JSON key, without the Google
 * client libraries: a short-lived RS256 JWT is exchanged at the token endpoint.
 *
 * Tokens are cached in memory and, when a PSR-16 cache is given, across processes (so a
 * request-per-process runtime such as PHP-FPM does not exchange a JWT on every request). The cache
 * key is derived from the key material, so a replaced or rotated key never reuses a token that was
 * issued to the previous one. The key file is read lazily, on first use, so a missing file does
 * not break container construction.
 *
 * @see https://developers.google.com/identity/protocols/oauth2/service-account
 */
final class ServiceAccountCredentials
{
    public const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';

    public const SCOPE_LOGGING_READ = 'https://www.googleapis.com/auth/logging.read';
    public const SCOPE_LOGGING_WRITE = 'https://www.googleapis.com/auth/logging.write';
    public const SCOPE_LOGGING_ADMIN = 'https://www.googleapis.com/auth/logging.admin';
    public const SCOPE_CLOUD_PLATFORM_READ_ONLY = 'https://www.googleapis.com/auth/cloud-platform.read-only';

    /** Refresh this many seconds before Google's stated expiry. */
    private const EXPIRY_MARGIN = 120;

    /** @var array{client_email: string, private_key: string, project_id?: string, private_key_id?: string}|null */
    private ?array $key = null;

    private string $scope;

    private ?string $accessToken = null;

    private int $expiresAt = 0;

    /**
     * @param string|array<string, mixed> $keyFileOrArray Path to the JSON key file, or the decoded key.
     * @param HttpClientInterface $http Transport for the token exchange.
     * @param string|list<string> $scopes OAuth scope(s) to request.
     * @param CacheInterface|null $cache Optional PSR-16 cache shared across processes.
     */
    public function __construct(
        private string|array $keyFileOrArray,
        private HttpClientInterface $http,
        string|array $scopes = self::SCOPE_LOGGING_READ,
        private ?CacheInterface $cache = null,
    ) {
        $this->scope = implode(' ', (array) $scopes);
        if (is_array($keyFileOrArray)) {
            $this->key = self::validateKey($keyFileOrArray);
        }
    }

    /**
     * Credentials from the `GOOGLE_APPLICATION_CREDENTIALS` environment variable (a key file path).
     *
     * @param string|list<string> $scopes
     */
    public static function fromEnvironment(HttpClientInterface $http, string|array $scopes = self::SCOPE_LOGGING_READ, ?CacheInterface $cache = null): self
    {
        $path = getenv('GOOGLE_APPLICATION_CREDENTIALS');

        return new self(is_string($path) ? $path : '', $http, $scopes, $cache);
    }

    /**
     * Whether key material is available (a readable file, or an inline key).
     */
    public function isConfigured(): bool
    {
        if ($this->key !== null) {
            return true;
        }

        return is_string($this->keyFileOrArray) && $this->keyFileOrArray !== ''
            && file_exists($this->keyFileOrArray) && is_readable($this->keyFileOrArray);
    }

    /**
     * Path of the key file, or empty when the key was given inline.
     */
    public function getKeyFile(): string
    {
        return is_string($this->keyFileOrArray) ? $this->keyFileOrArray : '';
    }

    /**
     * Requested scope(s), space separated.
     */
    public function getScope(): string
    {
        return $this->scope;
    }

    /**
     * Project id recorded in the key, or null when absent.
     *
     * @throws GoogleCloudException When the key cannot be loaded.
     */
    public function getProjectId(): ?string
    {
        $key = $this->loadKey();

        return isset($key['project_id']) && is_string($key['project_id']) ? $key['project_id'] : null;
    }

    /**
     * Service-account e-mail from the key.
     *
     * @throws GoogleCloudException When the key cannot be loaded.
     */
    public function getClientEmail(): string
    {
        return $this->loadKey()['client_email'];
    }

    /**
     * A valid bearer token, fetched or refreshed as needed.
     *
     * @throws GoogleCloudException When the key is unusable or Google rejects the exchange.
     */
    public function getAccessToken(): string
    {
        $now = time();
        if ($this->accessToken !== null && $this->expiresAt - self::EXPIRY_MARGIN > $now) {
            return $this->accessToken;
        }

        $cacheKey = $this->cacheKey();
        if ($this->cache !== null) {
            try {
                $cached = $this->cache->get($cacheKey);
            } catch (Throwable) {
                $cached = null;
            }
            if (is_array($cached) && isset($cached['token'], $cached['expires_at'])
                && (int) $cached['expires_at'] - self::EXPIRY_MARGIN > $now) {
                $this->accessToken = (string) $cached['token'];
                $this->expiresAt = (int) $cached['expires_at'];

                return $this->accessToken;
            }
        }

        [$token, $expiresIn] = $this->exchange();
        $this->accessToken = $token;
        $this->expiresAt = $now + $expiresIn;

        if ($this->cache !== null) {
            try {
                $this->cache->set($cacheKey, ['token' => $token, 'expires_at' => $this->expiresAt], $expiresIn);
            } catch (Throwable) {
                // Caching is an optimisation only.
            }
        }

        return $token;
    }

    /**
     * Build the signed JWT assertion for the given time (now by default).
     *
     * @throws GoogleCloudException
     */
    public function createAssertion(?int $now = null): string
    {
        $key = $this->loadKey();
        $now ??= time();

        $header = self::base64Url((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claims = self::base64Url((string) json_encode([
            'iss' => $key['client_email'],
            'scope' => $this->scope,
            'aud' => self::TOKEN_ENDPOINT,
            'iat' => $now,
            'exp' => $now + 3600,
        ]));
        $input = $header . '.' . $claims;

        $privateKey = openssl_pkey_get_private($key['private_key']);
        if ($privateKey === false) {
            throw new GoogleCloudException('The service-account private key could not be parsed.');
        }
        $signature = '';
        if (!openssl_sign($input, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new GoogleCloudException('Signing the service-account assertion failed.');
        }

        return $input . '.' . self::base64Url($signature);
    }

    /**
     * @return array{0: string, 1: int} Access token and its lifetime in seconds.
     * @throws GoogleCloudException
     */
    private function exchange(): array
    {
        $response = $this->http->request(
            'POST',
            self::TOKEN_ENDPOINT,
            ['Content-Type' => 'application/x-www-form-urlencoded'],
            http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $this->createAssertion(),
            ])
        );

        $data = json_decode($response['body'], true);
        if ($response['status'] !== 200 || !is_array($data) || !isset($data['access_token'])) {
            $detail = is_array($data) ? ($data['error_description'] ?? $data['error'] ?? '') : '';
            throw new GoogleCloudException(sprintf(
                'Google token exchange failed (HTTP %d)%s',
                $response['status'],
                is_string($detail) && $detail !== '' ? ': ' . $detail : ''
            ));
        }

        return [(string) $data['access_token'], (int) ($data['expires_in'] ?? 3600)];
    }

    /**
     * @return array{client_email: string, private_key: string, project_id?: string, private_key_id?: string}
     * @throws GoogleCloudException
     */
    private function loadKey(): array
    {
        if ($this->key !== null) {
            return $this->key;
        }
        if (!$this->isConfigured()) {
            throw new GoogleCloudException(sprintf(
                'Google service-account key file not found or not readable: %s',
                $this->getKeyFile() === '' ? '(no path configured)' : $this->getKeyFile()
            ));
        }

        $data = json_decode((string) file_get_contents($this->getKeyFile()), true);
        if (!is_array($data)) {
            throw new GoogleCloudException('The service-account key file is not valid JSON.');
        }

        return $this->key = self::validateKey($data);
    }

    /**
     * @param array<string, mixed> $data
     * @return array{client_email: string, private_key: string, project_id?: string, private_key_id?: string}
     * @throws GoogleCloudException
     */
    private static function validateKey(array $data): array
    {
        if (!isset($data['client_email'], $data['private_key']) || !is_string($data['client_email']) || !is_string($data['private_key'])) {
            throw new GoogleCloudException('The service-account key is not a valid Google JSON key (client_email/private_key missing).');
        }

        /** @var array{client_email: string, private_key: string, project_id?: string, private_key_id?: string} $data */
        return $data;
    }

    private function cacheKey(): string
    {
        $key = $this->loadKey();
        $identity = $key['client_email'] . '|' . (string) ($key['private_key_id'] ?? '') . '|' . hash('sha256', $key['private_key']);

        return 'gcl-sa-token-' . hash('sha256', $identity . '|' . $this->scope);
    }

    private static function base64Url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}

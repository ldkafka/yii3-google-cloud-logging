<?php

declare(strict_types=1);

namespace Ldkafka\GoogleCloudLogging\Tests\Unit;

use Ldkafka\GoogleCloudLogging\Auth\ServiceAccountCredentials;
use Ldkafka\GoogleCloudLogging\Exception\GoogleCloudException;
use Ldkafka\GoogleCloudLogging\Tests\Support\FakeHttpClient;
use Ldkafka\GoogleCloudLogging\Tests\Support\GoogleKeyFixture;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;

use function base64_decode;
use function explode;
use function json_decode;
use function openssl_verify;
use function parse_str;
use function str_repeat;
use function strlen;
use function strtr;

final class ServiceAccountCredentialsTest extends TestCase
{
    private GoogleKeyFixture $key;

    protected function setUp(): void
    {
        $this->key = new GoogleKeyFixture();
    }

    public function testAssertionCarriesTheExpectedClaimsAndAValidSignature(): void
    {
        $credentials = new ServiceAccountCredentials($this->key->path, new FakeHttpClient(), [ServiceAccountCredentials::SCOPE_LOGGING_READ, ServiceAccountCredentials::SCOPE_LOGGING_WRITE]);

        $jwt = $credentials->createAssertion(1_800_000_000);
        [$header, $claims, $signature] = explode('.', $jwt);

        self::assertSame(['alg' => 'RS256', 'typ' => 'JWT'], json_decode(self::b64($header), true));
        self::assertSame([
            'iss' => 'logs-reader@example-project.iam.gserviceaccount.com',
            'scope' => ServiceAccountCredentials::SCOPE_LOGGING_READ . ' ' . ServiceAccountCredentials::SCOPE_LOGGING_WRITE,
            'aud' => ServiceAccountCredentials::TOKEN_ENDPOINT,
            'iat' => 1_800_000_000,
            'exp' => 1_800_003_600,
        ], json_decode(self::b64($claims), true));
        self::assertSame(1, openssl_verify($header . '.' . $claims, self::b64($signature), $this->key->publicKeyPem, OPENSSL_ALGO_SHA256));
        self::assertSame('example-project', $credentials->getProjectId());
        self::assertSame('logs-reader@example-project.iam.gserviceaccount.com', $credentials->getClientEmail());
    }

    public function testInlineKeyArrayIsAccepted(): void
    {
        $key = json_decode((string) file_get_contents($this->key->path), true);
        $credentials = new ServiceAccountCredentials($key, (new FakeHttpClient())->queueToken('inline'));

        self::assertTrue($credentials->isConfigured());
        self::assertSame('', $credentials->getKeyFile());
        self::assertSame('inline', $credentials->getAccessToken());
    }

    public function testTokenIsExchangedOnceAndReusedUntilExpiry(): void
    {
        $http = (new FakeHttpClient())->queueToken('tok-1');
        $credentials = new ServiceAccountCredentials($this->key->path, $http);

        self::assertSame('tok-1', $credentials->getAccessToken());
        self::assertSame('tok-1', $credentials->getAccessToken());
        self::assertCount(1, $http->requests);

        $request = $http->requests[0];
        self::assertSame('POST', $request['method']);
        self::assertSame(ServiceAccountCredentials::TOKEN_ENDPOINT, $request['url']);
        parse_str((string) $request['body'], $form);
        self::assertSame('urn:ietf:params:oauth:grant-type:jwt-bearer', $form['grant_type']);
        self::assertCount(3, explode('.', (string) $form['assertion']));
    }

    public function testCacheIsSharedAndBoundToTheKeyIdentity(): void
    {
        $cache = new ArrayCache();
        $http = (new FakeHttpClient())->queueToken('tok-first')->queueToken('tok-second');

        $first = new ServiceAccountCredentials($this->key->path, $http, ServiceAccountCredentials::SCOPE_LOGGING_READ, $cache);
        self::assertSame('tok-first', $first->getAccessToken());

        $again = new ServiceAccountCredentials($this->key->path, $http, ServiceAccountCredentials::SCOPE_LOGGING_READ, $cache);
        self::assertSame('tok-first', $again->getAccessToken(), 'a new instance with the same key reuses the cached token');
        self::assertCount(1, $http->requests);

        $other = new GoogleKeyFixture('other@example-project.iam.gserviceaccount.com');
        $replaced = new ServiceAccountCredentials($other->path, $http, ServiceAccountCredentials::SCOPE_LOGGING_READ, $cache);
        self::assertSame('tok-second', $replaced->getAccessToken(), 'a different key never reuses the token');

        $differentScope = new ServiceAccountCredentials($this->key->path, $http->queueToken('tok-write'), ServiceAccountCredentials::SCOPE_LOGGING_WRITE, $cache);
        self::assertSame('tok-write', $differentScope->getAccessToken(), 'a different scope gets its own token');
    }

    public function testRejectedExchangeIsReportedWithoutLeakingTheKey(): void
    {
        $http = (new FakeHttpClient())->queue(400, ['error' => 'invalid_grant', 'error_description' => 'Invalid JWT Signature.']);
        $credentials = new ServiceAccountCredentials($this->key->path, $http);

        try {
            $credentials->getAccessToken();
            self::fail('expected exception');
        } catch (GoogleCloudException $e) {
            self::assertStringContainsString('HTTP 400', $e->getMessage());
            self::assertStringContainsString('Invalid JWT Signature', $e->getMessage());
            self::assertStringNotContainsString('PRIVATE KEY', $e->getMessage());
        }
    }

    public function testMissingKeyFileIsAClearError(): void
    {
        $credentials = new ServiceAccountCredentials('/nowhere/key.json', new FakeHttpClient());

        self::assertFalse($credentials->isConfigured());
        $this->expectException(GoogleCloudException::class);
        $this->expectExceptionMessage('/nowhere/key.json');
        $credentials->getAccessToken();
    }

    public function testInvalidKeyIsAClearError(): void
    {
        $this->expectException(GoogleCloudException::class);
        $this->expectExceptionMessage('client_email/private_key missing');
        new ServiceAccountCredentials(['type' => 'service_account'], new FakeHttpClient());
    }

    public function testFromEnvironmentReadsTheStandardVariable(): void
    {
        putenv('GOOGLE_APPLICATION_CREDENTIALS=' . $this->key->path);
        try {
            $credentials = ServiceAccountCredentials::fromEnvironment(new FakeHttpClient());
            self::assertSame($this->key->path, $credentials->getKeyFile());
            self::assertTrue($credentials->isConfigured());
        } finally {
            putenv('GOOGLE_APPLICATION_CREDENTIALS');
        }
    }

    private static function b64(string $data): string
    {
        return (string) base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4));
    }
}

/**
 * Minimal PSR-16 cache for the tests.
 */
final class ArrayCache implements CacheInterface
{
    /** @var array<string, mixed> */
    private array $data = [];

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        $this->data[$key] = $value;

        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->data[$key]);

        return true;
    }

    public function clear(): bool
    {
        $this->data = [];

        return true;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $out = [];
        foreach ($keys as $k) {
            $out[$k] = $this->get($k, $default);
        }

        return $out;
    }

    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
    {
        foreach ($values as $k => $v) {
            $this->set($k, $v);
        }

        return true;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $k) {
            $this->delete($k);
        }

        return true;
    }

    public function has(string $key): bool
    {
        return isset($this->data[$key]);
    }
}

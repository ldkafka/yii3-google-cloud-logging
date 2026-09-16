<?php

declare(strict_types=1);

namespace Ldkafka\GoogleCloudLogging\Tests\Support;

use function file_put_contents;
use function json_encode;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * Writes a service-account style JSON key file using a fixed, throwaway RSA key pair.
 *
 * The pair is embedded rather than generated because openssl_pkey_new() needs an OpenSSL config
 * file that Windows PHP builds usually lack. It is a test-only key: never used anywhere else.
 */
final class GoogleKeyFixture
{
    public const PRIVATE_KEY = <<<'PEM'
-----BEGIN PRIVATE KEY-----
MIIEvQIBADANBgkqhkiG9w0BAQEFAASCBKcwggSjAgEAAoIBAQC8/zQqu6yT8hhs
vHQGtN5eIhvjrr1Pe8A7ntQAjarHMzrQQLWRkgGTysiix3lBDu90aZ3TfKypty86
ACpTfaX1GeIzpjRHBC7vfi/o581d1AWP6Q/abjgfU6403U2CF+ZVUptyGKuGR4bb
1KNqXd3x11BBD//Sz2qiptBQUFxXs83uuBk/1q5gujSd17+PswhCoa4O4Su/tMNz
q3Btu0jed/1qplhsRZzMPXo8jrobeqdr2sloFoD7mfMVv6d0qSXpCi1jPLyoiW/9
rohXG3buyOwDUnexlJkgP3g35QIy5ArcdJoaPllgjXVkIssoHQFPPW7kkanDbggC
R2+ydvHlAgMBAAECggEAJfH/IU1I2vNSYBJ+IRKTSscCXnYo4BpygAXlfq7yyMfK
WSGE0tNDqc6e9b+i4qMDBJZn75wqdnCm9Lgvx0+E0G7/8Wq/ODroyYDGUbaojtwQ
udILMsnKTs/YPBjqhOIThrHtL70wQud9dgl9Pc/WzcVzAX0a2dJ3EGz5igZ4Y8sH
WXJ6AjX0UhiPR2iuhWDO4GwrwIlCReHVjAN81ho+cPV7Zw8WbA2H/Ot6nKsgruAb
oVzkxdkBkLWWR5EvTH6ESrNS2PAyeMokizDQQgTkFy1oGL7JPqcjcot2q9Y685iN
DTvPvqHJjbWh/wNFYG223ANT25B+LrDq5QQ+RNPXyQKBgQDrM0CVl8NMFFNoszXe
pEdkH07Jr6VkQw0TN6kxyQtgEdMt0/5ywC91/SBrZz9S1OEFj6Xak7+97tKBwCVq
0oi1S4+dK75FBrGT0pMC86hZPr6jazynse4dlmw5nf3W1rxK9CmcHh2/gwveqVFy
FUC1si7yLWxfQDV/KKLm15ez7wKBgQDNtfHc/AJKmam8n/YppPbmsSLzER46wpPG
2FX3U38QizIhkJ1wpH/pq4UKAVz/CAPGBPncwo+Brw6rt+dhwuTZzwVUyyS/mYEP
n2Nc3X5ID6ftz2aD+eJJstv525t4hnZCxYcrYt0CALK9bzANKa4y6O5FU6RDy/bc
FWOQ44wTawKBgEfhYQKW4BPXPmqIIpWJhVv/CXgwGw7aQxu1bhsOA1D4AZ9G48O0
Io0fsBHC+yJYdvDZJun3L6lfXKxUydqsvyURE7IIFV1JH2o697z2NGQZ/e85rc7e
XRRjzW2KcHKBLAiIOFNDDPpjlXQWMRL5lc4xx5Ex+qXdnLvg8nA0QWO3AoGBAJjQ
maztNRKQFmy+dBK5roTvgCQLSmaiVz83RJ1n1JPIo+QVVy/vs+o1da5aFuiJ3qvC
1I7vpcXT8tUT1/pi2rkHNlGoW1NOSHb/k8PP8ti7cKeUE/bksfrHuOxi/JrLYJz3
uhM77Sxosl9RcuPEW9kL+r1bhkKrCWazKPTgZRWjAoGAXH9g8mhH4UFZGWMD0MaC
rxWI/7ZFpdjaPo878hCX+yt76VZdJxIYueer0UT5cTH/0BsLrVPdMnhd3NAXF/T+
AlTEoIJ+XdwK9W1qVBCHmCkPQEPMtwKetNKZ041yAymaU7s47vpCd2Q8A/+6bSaU
R0AVnjbh3hwUEAi5Fjapzp0=
-----END PRIVATE KEY-----
PEM;

    public const PUBLIC_KEY = <<<'PEM'
-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAvP80Krusk/IYbLx0BrTe
XiIb4669T3vAO57UAI2qxzM60EC1kZIBk8rIosd5QQ7vdGmd03ysqbcvOgAqU32l
9RniM6Y0RwQu734v6OfNXdQFj+kP2m44H1OuNN1NghfmVVKbchirhkeG29Sjal3d
8ddQQQ//0s9qoqbQUFBcV7PN7rgZP9auYLo0nde/j7MIQqGuDuErv7TDc6twbbtI
3nf9aqZYbEWczD16PI66G3qna9rJaBaA+5nzFb+ndKkl6QotYzy8qIlv/a6IVxt2
7sjsA1J3sZSZID94N+UCMuQK3HSaGj5ZYI11ZCLLKB0BTz1u5JGpw24IAkdvsnbx
5QIDAQAB
-----END PUBLIC KEY-----
PEM;

    public string $path;
    public string $publicKeyPem = self::PUBLIC_KEY;

    public function __construct(string $email = 'logs-reader@example-project.iam.gserviceaccount.com', string $projectId = 'example-project')
    {
        $this->path = (string) tempnam(sys_get_temp_dir(), 'gkey');
        file_put_contents($this->path, (string) json_encode([
            'type' => 'service_account',
            'project_id' => $projectId,
            'private_key_id' => 'test',
            'private_key' => self::PRIVATE_KEY . "\n",
            'client_email' => $email,
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ]));
    }

    public function __destruct()
    {
        @unlink($this->path);
    }
}

<?php

declare(strict_types=1);

namespace Crab\S3;

/**
 * Signs requests to S3 with AWS Signature Version 4, which every service
 * that speaks S3's API checks: as headers on a request the disk sends, or
 * in the query string of a URL handed to someone else.
 *
 * Paths are given already encoded, one part at a time; the body is not
 * hashed but declared unsigned, so a large upload is read only once.
 */
final readonly class Signature
{
    public const UNSIGNED = 'UNSIGNED-PAYLOAD';

    /**
     * @param string|null $token The session token of temporary credentials.
     */
    public function __construct(
        private string $key,
        #[\SensitiveParameter]
        private string $secret,
        private string $region,
        #[\SensitiveParameter]
        private ?string $token = null,
    ) {}

    /**
     * The headers to send with a request: its own, with the date, the
     * body's hash, the session token and the Authorization that signs them.
     *
     * @param string $host The host, with ":port" when the URL names one.
     * @param string $path The encoded path, starting with "/".
     * @param array<string, string> $query Not encoded.
     * @param array<string, string> $headers Each signed.
     * @return array<string, string>
     */
    public function headers(
        string $method,
        string $host,
        string $path,
        array $query,
        array $headers,
        \DateTimeImmutable $now,
        string $payload = self::UNSIGNED,
    ): array {
        $now = $now->setTimezone(new \DateTimeZone('UTC'));
        $headers['x-amz-date'] = $now->format('Ymd\THis\Z');
        $headers['x-amz-content-sha256'] = $payload;
        if ($this->token !== null) {
            $headers['x-amz-security-token'] = $this->token;
        }
        $signed = ['host' => $host];
        foreach ($headers as $name => $value) {
            $signed[strtolower($name)] = trim((string) preg_replace('/\s+/', ' ', $value));
        }
        ksort($signed, SORT_STRING);
        $scope = $this->scope($now);
        $names = implode(';', array_keys($signed));
        $signature = $this->signature($now, $method, $path, $query, $signed, $payload);
        $headers['Authorization'] = "AWS4-HMAC-SHA256 Credential={$this->key}/{$scope}, SignedHeaders={$names}, Signature={$signature}";
        return $headers;
    }

    /**
     * The query string of a URL that may be used until it expires, without
     * credentials of its own.
     *
     * @param array<string, string> $query The URL's own, not encoded.
     * @param array<string, string> $headers Any the user of the URL must send as they are, such as content-type.
     */
    public function query(
        string $method,
        string $host,
        string $path,
        array $query,
        int $expires,
        \DateTimeImmutable $now,
        array $headers = [],
    ): string {
        $now = $now->setTimezone(new \DateTimeZone('UTC'));
        $signed = ['host' => $host];
        foreach ($headers as $name => $value) {
            $signed[strtolower($name)] = trim($value);
        }
        ksort($signed, SORT_STRING);
        $query += [
            'X-Amz-Algorithm' => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential' => "{$this->key}/{$this->scope($now)}",
            'X-Amz-Date' => $now->format('Ymd\THis\Z'),
            'X-Amz-Expires' => (string) $expires,
            'X-Amz-SignedHeaders' => implode(';', array_keys($signed)),
        ];
        if ($this->token !== null) {
            $query['X-Amz-Security-Token'] = $this->token;
        }
        $query['X-Amz-Signature'] = $this->signature($now, $method, $path, $query, $signed, self::UNSIGNED);
        return self::encoded($query);
    }

    /**
     * A query string as S3 reads it: names and values encoded as RFC 3986
     * says, in order of name.
     *
     * @param array<string, string> $query
     */
    public static function encoded(array $query): string
    {
        $pairs = [];
        foreach ($query as $name => $value) {
            $pairs[rawurlencode((string) $name)] = rawurlencode($value);
        }
        ksort($pairs, SORT_STRING);
        $parts = [];
        foreach ($pairs as $name => $value) {
            $parts[] = "{$name}={$value}";
        }
        return implode('&', $parts);
    }

    /**
     * @param array<string, string> $query
     * @param array<string, string> $headers Lowercase names, in order.
     */
    private function signature(
        \DateTimeImmutable $now,
        string $method,
        string $path,
        array $query,
        array $headers,
        string $payload,
    ): string {
        $canonical = implode("\n", [
            $method,
            $path,
            self::encoded($query),
            implode('', array_map(
                static fn(string $name, string $value): string => "{$name}:{$value}\n",
                array_keys($headers),
                $headers,
            )),
            implode(';', array_keys($headers)),
            $payload,
        ]);
        $text = implode("\n", [
            'AWS4-HMAC-SHA256',
            $now->format('Ymd\THis\Z'),
            $this->scope($now),
            hash('sha256', $canonical),
        ]);
        $key = hash_hmac('sha256', $now->format('Ymd'), 'AWS4' . $this->secret, true);
        foreach ([$this->region, 's3', 'aws4_request'] as $part) {
            $key = hash_hmac('sha256', $part, $key, true);
        }
        return hash_hmac('sha256', $text, $key);
    }

    private function scope(\DateTimeImmutable $now): string
    {
        return $now->format('Ymd') . "/{$this->region}/s3/aws4_request";
    }
}

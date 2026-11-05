<?php

declare(strict_types=1);

namespace Crab\S3;

use Crab\Contracts\HttpClient\Client;
use Crab\Contracts\Storage\Disk;
use Crab\Contracts\Storage\File;
use Crab\Contracts\Storage\FileNotFound;
use Crab\Contracts\Storage\Path;
use Crab\Contracts\Storage\StorageException;
use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;

/**
 * A bucket on S3, or on any service that speaks its API: Cloudflare R2,
 * MinIO, Backblaze B2, DigitalOcean Spaces and others. Each request goes
 * through Crab's HTTP client, so while one waits for its answer the
 * worker serves other requests.
 *
 * A file is written with one request, which S3 makes whole or not at all,
 * up to 5 GB. Bytes are read back as they were written: answers are never
 * decompressed on the way.
 */
final class S3Disk implements Disk
{
    /**
     * The most one request may write.
     */
    private const MOST = 5 * (1024 ** 3);

    /**
     * The longest a signed URL may last, which S3 allows: seven days.
     */
    private const LONGEST = 604_800;

    private readonly string $scheme;
    private readonly string $host;
    private readonly string $basePath;
    private readonly string $prefix;

    /**
     * @param string $endpoint The service's address, such as https://s3.eu-west-1.amazonaws.com.
     * @param bool $pathStyle Whether the bucket is named in the path, as MinIO needs, and not in the host.
     * @param string $prefix Put before every path, to keep several disks in one bucket.
     * @param string|null $url Where the bucket is served from to anyone, for url(); the bucket's own address when null.
     */
    public function __construct(
        private readonly Client $http,
        private readonly Signature $signature,
        private readonly string $bucket,
        string $endpoint,
        private readonly bool $pathStyle,
        private readonly StreamFactoryInterface $streams,
        private readonly ClockInterface $clock,
        string $prefix = '',
        private readonly ?string $url = null,
    ) {
        $parts = parse_url($endpoint);
        $scheme = is_array($parts) && isset($parts['scheme']) ? strtolower((string) $parts['scheme']) : '';
        if (!is_array($parts) || !isset($parts['host']) || !in_array($scheme, ['http', 'https'], true)) {
            throw new \InvalidArgumentException("\"{$endpoint}\" is not an http or https address of an S3 service.");
        }
        $port = $parts['port'] ?? null;
        $host = strtolower($parts['host']);
        if ($port !== null && !($scheme === 'http' && $port === 80 || $scheme === 'https' && $port === 443)) {
            $host .= ":{$port}";
        }
        $this->scheme = $scheme;
        $this->host = $pathStyle ? $host : "{$bucket}.{$host}";
        $basePath = rtrim($parts['path'] ?? '', '/');
        $this->basePath = $basePath === '' ? '' : '/' . ltrim($basePath, '/');
        $prefix = trim($prefix, '/');
        $this->prefix = $prefix === '' ? '' : Path::file($prefix) . '/';
    }

    public function write(string $path, string|StreamInterface $contents, ?string $type = null): void
    {
        $target = $this->object($path);
        if ($contents instanceof StreamInterface && ($contents->getSize() === null || !$contents->isSeekable())) {
            // S3 must be told the length first, and a request it fails is tried again from the start.
            $spooled = $this->streams->createStreamFromResource(fopen('php://temp', 'w+b'));
            while (!$contents->eof()) {
                $spooled->write($contents->read(65_536));
            }
            $spooled->rewind();
            $contents = $spooled;
        } elseif ($contents instanceof StreamInterface && $contents->isSeekable()) {
            $contents->rewind();
        }
        $size = is_string($contents) ? strlen($contents) : (int) $contents->getSize();
        if ($size > self::MOST) {
            throw new StorageException("\"{$path}\" is {$size} bytes, and one upload to S3 holds at most 5 GB.");
        }
        $response = $this->send(
            'PUT',
            $target,
            headers: $type === null ? [] : ['Content-Type' => $type],
            body: $contents,
        );
        if ($response->getStatusCode() !== 200) {
            $this->fail($response, 'PUT', $path);
        }
    }

    public function read(string $path): StreamInterface
    {
        // A file is read whole, however large: whoever reads it chose it.
        $response = $this->send('GET', $this->object($path), maxBytes: PHP_INT_MAX);
        if ($response->getStatusCode() === 404) {
            throw new FileNotFound($path);
        }
        if ($response->getStatusCode() !== 200) {
            $this->fail($response, 'GET', $path);
        }
        $body = $response->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }
        return $body;
    }

    public function contents(string $path): string
    {
        return (string) $this->read($path);
    }

    public function exists(string $path): bool
    {
        return $this->file($path) !== null;
    }

    public function file(string $path): ?File
    {
        $path = Path::file($path);
        $response = $this->send('HEAD', $this->object($path));
        if ($response->getStatusCode() === 404) {
            return null;
        }
        if ($response->getStatusCode() !== 200) {
            $this->fail($response, 'HEAD', $path);
        }
        $type = $response->getHeaderLine('Content-Type');
        return new File(
            $path,
            (int) $response->getHeaderLine('Content-Length'),
            $this->modified($response->getHeaderLine('Last-Modified'), 'HEAD', $path),
            $type === '' ? null : $type,
        );
    }

    public function delete(string $path): void
    {
        $response = $this->send('DELETE', $this->object($path));
        if (!in_array($response->getStatusCode(), [200, 204, 404], true)) {
            $this->fail($response, 'DELETE', $path);
        }
    }

    public function copy(string $from, string $to): void
    {
        $source = '/' . rawurlencode($this->bucket) . self::encoded($this->prefix . Path::file($from));
        $response = $this->send('PUT', $this->object($to), headers: ['x-amz-copy-source' => $source]);
        $body = (string) $response->getBody();
        if ($response->getStatusCode() === 404) {
            throw new FileNotFound($from);
        }
        // A copy can fail after S3 has answered 200, with the error in the body.
        $error = self::xml($body);
        if ($response->getStatusCode() !== 200 || $error !== false && $error->getName() === 'Error') {
            $this->fail($response, 'COPY', $from, $body);
        }
    }

    public function move(string $from, string $to): void
    {
        $this->copy($from, $to);
        if (Path::file($from) !== Path::file($to)) {
            $this->delete($from);
        }
    }

    public function files(string $prefix = ''): iterable
    {
        $query = ['list-type' => '2', 'prefix' => $this->prefix . Path::prefix($prefix)];
        $bucket = $this->basePath . ($this->pathStyle ? '/' . rawurlencode($this->bucket) : '/');
        do {
            $response = $this->send('GET', $bucket, $query);
            $body = (string) $response->getBody();
            if ($response->getStatusCode() !== 200) {
                $this->fail($response, 'LIST', $prefix, $body);
            }
            $list = self::xml($body);
            if ($list === false) {
                throw new StorageException("S3 answered the listing of \"{$prefix}\" with something that is not XML.");
            }
            if ($list->getName() === 'Error') {
                $this->fail($response, 'LIST', $prefix, $body);
            }
            if ($list->getName() !== 'ListBucketResult') {
                throw new StorageException("S3 answered the listing of \"{$prefix}\" with unexpected XML.");
            }
            foreach (self::xmlNodes($list, 'Contents') as $object) {
                $key = self::xmlText($object, 'Key');
                yield new File(
                    substr($key, strlen($this->prefix)),
                    (int) self::xmlText($object, 'Size'),
                    $this->modified(self::xmlText($object, 'LastModified'), 'LIST', $key),
                );
            }
            $query['continuation-token'] = self::xmlText($list, 'NextContinuationToken');
        } while (self::xmlText($list, 'IsTruncated') === 'true' && $query['continuation-token'] !== '');
    }

    public function url(string $path): string
    {
        $key = self::encoded($this->prefix . Path::file($path));
        return $this->url === null
            ? "{$this->scheme}://{$this->host}{$this->object($path)}"
            : rtrim($this->url, '/') . $key;
    }

    public function temporaryUrl(string $path, \DateTimeInterface $expires): string
    {
        return $this->signed('GET', $path, $expires, []);
    }

    public function temporaryUploadUrl(string $path, \DateTimeInterface $expires, ?string $type = null): string
    {
        return $this->signed('PUT', $path, $expires, $type === null ? [] : ['content-type' => $type]);
    }

    /**
     * @param array<string, string> $headers
     */
    private function signed(string $method, string $path, \DateTimeInterface $expires, array $headers): string
    {
        $now = $this->clock->now();
        $seconds = $expires->getTimestamp() - $now->getTimestamp();
        if ($seconds < 1 || $seconds > self::LONGEST) {
            throw new \InvalidArgumentException(
                "A signed URL lasts from one second to seven days; this one would last {$seconds} seconds.",
            );
        }
        $object = $this->object($path);
        $query = $this->signature->query($method, $this->host, $object, [], $seconds, $now, $headers);
        return "{$this->scheme}://{$this->host}{$object}?{$query}";
    }

    /**
     * The encoded path of a file's request.
     */
    private function object(string $path): string
    {
        $key = self::encoded($this->prefix . Path::file($path));
        return $this->basePath . ($this->pathStyle ? '/' . rawurlencode($this->bucket) . $key : $key);
    }

    /**
     * A key as part of a URL: "/", then each part encoded as S3 signs it.
     */
    private static function encoded(string $key): string
    {
        return '/' . implode('/', array_map(rawurlencode(...), explode('/', $key)));
    }

    /**
     * @param array<string, string> $query
     * @param array<string, string> $headers
     * @param int|null $maxBytes The most bytes of body the answer may have; null for the client's own limit.
     */
    private function send(
        string $method,
        string $path,
        array $query = [],
        array $headers = [],
        string|StreamInterface|null $body = null,
        ?int $maxBytes = null,
    ): ResponseInterface {
        $headers = $this->signature->headers($method, $this->host, $path, $query, $headers, $this->clock->now());
        $url = "{$this->scheme}://{$this->host}{$path}" . ($query === [] ? '' : '?' . Signature::encoded($query));
        try {
            // The bytes as they are kept, not unpacked on the way, even those kept with a Content-Encoding.
            return $this->http->request(
                $method,
                $url,
                headers: $headers,
                body: $body,
                maxBytes: $maxBytes,
                decode: false,
            );
        } catch (ClientExceptionInterface $error) {
            throw new StorageException(
                "S3 could not be reached for {$method} {$path}: {$error->getMessage()}",
                0,
                $error,
            );
        }
    }

    private function fail(ResponseInterface $response, string $action, string $path, ?string $body = null): never
    {
        $body ??= (string) $response->getBody();
        $error = self::xml($body);
        $said = $error === false
            ? ''
            : trim(self::xmlText($error, 'Code') . ': ' . self::xmlText($error, 'Message'), ': ');
        throw new StorageException(
            "S3 refused {$action} \"{$path}\" in the bucket \"{$this->bucket}\": "
            . $response->getStatusCode()
            . ($said === '' ? '' : " {$said}")
            . '.',
        );
    }

    private function modified(string $value, string $action, string $path): \DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable($value === '' ? '@0' : $value);
        } catch (\Exception $error) {
            throw new StorageException(
                "S3 answered {$action} \"{$path}\" in the bucket \"{$this->bucket}\" with an invalid Last-Modified date.",
                previous: $error,
            );
        }
    }

    private static function xml(string $body): \SimpleXMLElement|false
    {
        return @simplexml_load_string($body, options: LIBXML_NONET);
    }

    private static function xmlText(\SimpleXMLElement $xml, string $name): string
    {
        $value = (string) $xml->{$name};
        if ($value !== '') {
            return $value;
        }
        foreach ($xml->getDocNamespaces(true) as $namespace) {
            $value = (string) $xml->children($namespace)->{$name};
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }

    /**
     * @return list<\SimpleXMLElement>
     */
    private static function xmlNodes(\SimpleXMLElement $xml, string $name): array
    {
        $nodes = [];
        foreach ($xml->{$name} as $node) {
            $nodes[] = $node;
        }
        if ($nodes !== []) {
            return $nodes;
        }
        foreach ($xml->getDocNamespaces(true) as $namespace) {
            foreach ($xml->children($namespace)->{$name} as $node) {
                $nodes[] = $node;
            }
            if ($nodes !== []) {
                return $nodes;
            }
        }
        return [];
    }
}

<?php

declare(strict_types=1);

namespace Crab\S3\Tests;

use Crab\Clock\SystemClock;
use Crab\Contracts\Storage\Disk;
use Crab\Contracts\Storage\File;
use Crab\Contracts\Storage\FileNotFound;
use Crab\Contracts\Storage\InvalidPath;
use Crab\Contracts\Storage\StorageException;
use Crab\Http\Factory;
use Crab\Http\Stream;
use Crab\HttpClient\Client;
use Crab\S3\S3Disk;
use Crab\S3\Signature;
use PHPUnit\Framework\TestCase;

/**
 * Against a real service that speaks S3's API, such as MinIO, named by
 * CRAB_TEST_S3_ENDPOINT with CRAB_TEST_S3_KEY and CRAB_TEST_S3_SECRET:
 *
 *     docker run -d -p 9010:9000 -e MINIO_ROOT_USER=crabtest -e MINIO_ROOT_PASSWORD=crabtestsecret minio/minio server /data
 *     CRAB_TEST_S3_ENDPOINT=http://127.0.0.1:9010 CRAB_TEST_S3_KEY=crabtest CRAB_TEST_S3_SECRET=crabtestsecret vendor/bin/phpunit
 */
final class S3DiskTest extends TestCase
{
    private string $bucket;

    protected function setUp(): void
    {
        foreach (['CRAB_TEST_S3_ENDPOINT', 'CRAB_TEST_S3_KEY', 'CRAB_TEST_S3_SECRET'] as $variable) {
            if (getenv($variable) !== false && getenv($variable) !== '') {
                continue;
            }
            self::markTestSkipped(
                'Set CRAB_TEST_S3_ENDPOINT, CRAB_TEST_S3_KEY and CRAB_TEST_S3_SECRET to test against a service.',
            );
        }
        $this->bucket = 'crab-' . bin2hex(random_bytes(6));
        $response = $this->request('PUT', '/' . $this->bucket);
        self::assertSame(200, $response->getStatusCode(), 'The test bucket was not made: ' . $response->getBody());
    }

    protected function tearDown(): void
    {
        if (!isset($this->bucket)) {
            return;
        }
        foreach ($this->disk()->files() as $file) {
            $this->disk()->delete($file->path);
        }
        $this->request('DELETE', '/' . $this->bucket);
    }

    public function testAFileIsWrittenReadReplacedAndDescribed(): void
    {
        $disk = $this->disk();
        self::assertFalse($disk->exists('avatars/7.png'));
        self::assertNull($disk->file('avatars/7.png'));

        $disk->write('avatars/7.png', 'first', 'image/png');
        self::assertSame('first', $disk->contents('avatars/7.png'));
        $disk->write('avatars/7.png', new Stream(str_repeat('b', 2_000_000)), 'image/png');

        self::assertSame(2_000_000, strlen((string) $disk->read('avatars/7.png')));
        $file = $disk->file('avatars/7.png');
        self::assertInstanceOf(File::class, $file);
        self::assertSame(['avatars/7.png', 2_000_000, 'image/png'], [$file->path, $file->size, $file->type]);
        self::assertEqualsWithDelta(time(), $file->modified->getTimestamp(), 60);

        // Bytes come back as they went in, whatever they are and however the path is spelt.
        $bytes = random_bytes(4096) . "\0\r\n";
        $disk->write('odd names/q1 & q2+%/ü ~$.bin', $bytes);
        self::assertSame($bytes, $disk->contents('odd names/q1 & q2+%/ü ~$.bin'));
        // A stream that cannot say its length is written too.
        $unsized = new Factory()->createStreamFromResource(popen('printf unsized', 'r'));
        self::assertNull($unsized->getSize());
        $disk->write('unsized.txt', $unsized);
        self::assertSame('unsized', $disk->contents('unsized.txt'));
    }

    public function testFilesAreCopiedMovedDeletedAndListed(): void
    {
        $disk = $this->disk();
        foreach (['b/2.txt', 'a/1.txt', 'a/sub/3.txt', 'ab.txt'] as $path) {
            $disk->write($path, $path);
        }
        $disk->copy('a/1.txt', 'c/copy.txt');
        $disk->move('b/2.txt', 'c/moved.txt');
        $disk->delete('ab.txt');
        $disk->delete('never-there.txt');

        self::assertSame(['a/1.txt', 'a/sub/3.txt', 'c/copy.txt', 'c/moved.txt'], self::paths($disk->files()));
        self::assertSame(['a/1.txt', 'a/sub/3.txt'], self::paths($disk->files('a/')));
        self::assertSame([7, 11], array_map(static fn(File $file): int => $file->size, [...$disk->files('a/')]));
        self::assertSame('b/2.txt', $disk->contents('c/moved.txt'));

        $this->expectException(FileNotFound::class);
        $disk->copy('b/2.txt', 'd.txt');
    }

    public function testDisksWithPrefixesShareABucketWithoutSeeingEachOther(): void
    {
        $avatars = $this->disk('avatars');
        $reports = $this->disk('reports/');
        $avatars->write('7.png', 'avatar');
        $reports->write('q1.csv', 'report');

        self::assertSame(['7.png'], self::paths($avatars->files()));
        self::assertSame(['q1.csv'], self::paths($reports->files()));
        self::assertSame(['avatars/7.png', 'reports/q1.csv'], self::paths($this->disk()->files()));
        self::assertStringEndsWith("/{$this->bucket}/avatars/7.png", $avatars->url('7.png'));
    }

    public function testSignedUrlsDownloadAndUploadWithoutCredentials(): void
    {
        $disk = $this->disk();
        $disk->write('private/report.csv', 'a,b');
        $url = $disk->temporaryUrl('private/report.csv', new \DateTimeImmutable('+5 minutes'));

        self::assertSame('a,b', (string) file_get_contents($url));
        self::assertFalse(@file_get_contents($disk->url('private/report.csv')), 'The bucket is not public.');

        $upload = $disk->temporaryUploadUrl('incoming/photo.jpg', new \DateTimeImmutable('+5 minutes'), 'image/jpeg');
        $context = stream_context_create(['http' => [
            'method' => 'PUT',
            'header' => "Content-Type: image/jpeg\r\n",
            'content' => 'jpeg bytes',
        ]]);
        file_get_contents($upload, false, $context);
        self::assertSame(['jpeg bytes', 'image/jpeg'], [
            $disk->contents('incoming/photo.jpg'),
            $disk->file('incoming/photo.jpg')?->type,
        ]);
    }

    public function testWhatTheServiceRefusesIsSaid(): void
    {
        $wrong = new S3Disk(
            new Client(),
            new Signature((string) getenv('CRAB_TEST_S3_KEY'), 'not the secret', 'us-east-1'),
            $this->bucket,
            (string) getenv('CRAB_TEST_S3_ENDPOINT'),
            true,
            new Factory(),
            new SystemClock(),
        );
        try {
            $wrong->write('a.txt', 'x');
            self::fail('A wrong secret wrote a file.');
        } catch (StorageException $refused) {
            self::assertStringContainsString(
                "S3 refused PUT \"a.txt\" in the bucket \"{$this->bucket}\": 403 SignatureDoesNotMatch",
                $refused->getMessage(),
            );
        }
        $this->expectException(InvalidPath::class);
        $this->disk()->write('../a.txt', 'x');
    }

    private function disk(string $prefix = ''): Disk
    {
        return new S3Disk(
            new Client(),
            $this->signature(),
            $this->bucket,
            (string) getenv('CRAB_TEST_S3_ENDPOINT'),
            true,
            new Factory(),
            new SystemClock(),
            $prefix,
        );
    }

    private function signature(): Signature
    {
        return new Signature((string) getenv('CRAB_TEST_S3_KEY'), (string) getenv('CRAB_TEST_S3_SECRET'), 'us-east-1');
    }

    private function request(string $method, string $path): \Psr\Http\Message\ResponseInterface
    {
        $endpoint = (string) getenv('CRAB_TEST_S3_ENDPOINT');
        $host = (string) parse_url($endpoint, PHP_URL_HOST);
        $scheme = strtolower((string) parse_url($endpoint, PHP_URL_SCHEME));
        $port = parse_url($endpoint, PHP_URL_PORT);
        if ($port !== null && !($scheme === 'http' && $port === 80 || $scheme === 'https' && $port === 443)) {
            $host .= ":{$port}";
        }
        $basePath = rtrim((string) parse_url($endpoint, PHP_URL_PATH), '/');
        $headers = $this->signature()->headers($method, $host, $basePath . $path, [], [], new \DateTimeImmutable());
        return new Client()->request($method, $endpoint . $path, headers: $headers);
    }

    /**
     * @param iterable<File> $files
     * @return list<string>
     */
    private static function paths(iterable $files): array
    {
        $paths = [];
        foreach ($files as $file) {
            $paths[] = $file->path;
        }
        return $paths;
    }
}

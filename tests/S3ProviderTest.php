<?php

declare(strict_types=1);

namespace Crab\S3\Tests;

use Crab\App;
use Crab\Config\Config;
use Crab\Contracts\HttpClient\Client;
use Crab\Contracts\Storage\Storage;
use Crab\Contracts\Storage\StorageException;
use Crab\HttpClient\FakeClient;
use Crab\S3\S3Disk;
use Crab\S3\S3Provider;
use Crab\Storage\StorageManager;
use Crab\Storage\StorageProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * The driver as an application configures it, with requests answered in
 * advance: what the service says that a local one rarely does.
 */
final class S3ProviderTest extends TestCase
{
    private const DISK = ['driver' => 's3', 'key' => 'AKID', 'secret' => 'secret', 'bucket' => 'photos'];

    public function testAnAwsBucketIsNamedInTheHostOfItsRegion(): void
    {
        $http = new FakeClient(['PUT https://photos.s3.eu-west-1.amazonaws.com/avatars/7.png' => '']);
        $disk = $this->disk(['region' => 'eu-west-1'], $http);

        $disk->write('avatars/7.png', 'png', 'image/png');
        $request = $http->sent()[0];
        self::assertSame('image/png', $request->getHeaderLine('Content-Type'));
        self::assertStringStartsWith('AWS4-HMAC-SHA256 Credential=AKID/', $request->getHeaderLine('Authorization'));
        self::assertStringContainsString('/eu-west-1/s3/aws4_request', $request->getHeaderLine('Authorization'));
        self::assertSame('https://photos.s3.eu-west-1.amazonaws.com/avatars/7.png', $disk->url('avatars/7.png'));
    }

    public function testAFileIsReadAsItIsKeptAndWholeHoweverLarge(): void
    {
        $packed = gzencode(str_repeat('photo ', 100));
        $http = new FakeClient([
            'GET https://photos.s3.eu-west-1.amazonaws.com/packed.txt' => FakeClient::response(200, $packed, [
                'Content-Encoding' => 'gzip',
            ]),
        ], maxBytes: 16);
        $disk = $this->disk(['region' => 'eu-west-1'], $http);

        self::assertSame($packed, $disk->contents('packed.txt'));
    }

    public function testAnotherServiceIsNamedByItsEndpointWithTheBucketInThePath(): void
    {
        $disk = $this->disk([
            'region' => 'auto',
            'endpoint' => 'https://account.r2.cloudflarestorage.com',
            'url' => 'https://cdn.example.com/',
            'prefix' => 'app',
        ]);

        self::assertSame('https://cdn.example.com/app/a%20b.png', $disk->url('a b.png'));
        $signed = $disk->temporaryUrl('a b.png', new \DateTimeImmutable('+10 minutes'));
        self::assertStringStartsWith('https://account.r2.cloudflarestorage.com/photos/app/a%20b.png?', $signed);
        self::assertStringContainsString('%2Fauto%2Fs3%2Faws4_request', $signed);
        self::assertStringContainsString('X-Amz-Expires=600', $signed);
    }

    public function testAListingIsReadAPageAtATime(): void
    {
        $page = static fn(array $keys, ?string $next): string => (
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<ListBucketResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'
            . implode('', array_map(
                static fn(string $key): string => "<Contents><Key>{$key}</Key><LastModified>2026-10-25T10:00:00.000Z</LastModified><Size>3</Size></Contents>",
                $keys,
            ))
            . '<IsTruncated>'
            . ($next === null ? 'false' : 'true')
            . '</IsTruncated>'
            . ($next === null ? '' : "<NextContinuationToken>{$next}</NextContinuationToken>")
            . '</ListBucketResult>'
        );
        $http = new FakeClient([
            'GET https://photos.s3.us-east-1.amazonaws.com/*' => static fn(RequestInterface $request): mixed => (
                str_contains($request->getUri()->getQuery(), 'continuation-token=page%2B2')
                    ? FakeClient::response(200, $page(['app/c.txt'], null))
                    : FakeClient::response(200, $page(['app/a.txt', 'app/b.txt'], 'page+2'))
            ),
        ]);
        $files = [...$this->disk(['prefix' => 'app/'], $http)->files()];

        self::assertSame(['a.txt', 'b.txt', 'c.txt'], array_map(static fn($file): string => $file->path, $files));
        self::assertSame('2026-10-25T10:00:00+00:00', $files[0]->modified->format(DATE_ATOM));
        self::assertCount(2, $http->sent());
        self::assertStringContainsString('prefix=app%2F', $http->sent()[0]->getUri()->getQuery());
    }

    public function testACopyThatFailsAfterTheServiceAnswered200IsAFailure(): void
    {
        $http = new FakeClient([
            'PUT *' => FakeClient::response(
                200,
                '<Error><Code>InternalError</Code><Message>Try again.</Message></Error>',
            ),
        ]);
        $this->expectExceptionMessage('S3 refused COPY "a.txt" in the bucket "photos": 200 InternalError: Try again.');
        $this->disk([], $http)->copy('a.txt', 'b.txt');
    }

    public function testAServiceThatCannotBeReachedIsAStorageFailure(): void
    {
        $http = new FakeClient([
            '*' => static fn(RequestInterface $request): never => throw new \Crab\HttpClient\NetworkException(
                'Connection refused',
                $request,
            ),
        ]);
        try {
            $this->disk([], $http)->contents('a.txt');
            self::fail('An unreachable service was not a failure.');
        } catch (StorageException $failure) {
            self::assertStringStartsWith('S3 could not be reached for GET /a.txt: ', $failure->getMessage());
        }
    }

    public function testASignedUrlLastsFromASecondToSevenDays(): void
    {
        $this->expectExceptionMessage('A signed URL lasts from one second to seven days');
        $this->disk()->temporaryUrl('a.txt', new \DateTimeImmutable('+8 days'));
    }

    public function testSettingsTheDriverNeedsOrDoesNotKnowAreSaid(): void
    {
        foreach ([
            [['bucket' => null], 'The disk "cloud" needs its "bucket", which S3 asks for.'],
            [['buckt' => 'x'], 'The disk "cloud" has settings its driver does not know: "buckt"'],
            [['path_style' => 'yes'], 'The disk "cloud" has a "path_style" that is not true or false.'],
            [['timeout' => 0], 'The disk "cloud" has a "timeout" that is not a number of seconds above zero.'],
        ] as [$settings, $said]) {
            try {
                $this->disk($settings);
                self::fail("Settings were taken that should have been refused: {$said}");
            } catch (\LogicException $refused) {
                self::assertStringStartsWith($said, $refused->getMessage());
            }
        }
    }

    public function testItIsRegisteredAfterTheFrameworksStorage(): void
    {
        $this->expectExceptionMessage('register it after the framework\'s providers');
        new App()->register(new S3Provider());
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function disk(array $settings = [], ?FakeClient $http = null): S3Disk
    {
        $app = new App(new Config(['storage' => ['disks' => ['cloud' => [...self::DISK, ...$settings]]]]));
        $app->register(new StorageProvider());
        $app->register(new S3Provider());
        $app->container->instance(Client::class, $http ?? new FakeClient());
        self::assertSame([], $app->container->get(StorageManager::class)->problems());
        $disk = $app->container->get(Storage::class)->disk('cloud');
        self::assertInstanceOf(S3Disk::class, $disk);
        return $disk;
    }
}

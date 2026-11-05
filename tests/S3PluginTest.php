<?php

declare(strict_types=1);

namespace Crab\S3\Tests;

use Crab\App;
use Crab\Config\Config;
use Crab\Contracts\HttpClient\Client;
use Crab\Contracts\HttpClient\Response;
use Crab\Contracts\Storage\Storage;
use Crab\Contracts\Storage\StorageException;
use Crab\Http\Factory;
use Crab\HttpClient\FakeClient;
use Crab\S3\S3Disk;
use Crab\S3\S3Plugin;
use Crab\Storage\StorageManager;
use Crab\Storage\StoragePlugin;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamInterface;

/**
 * The driver as an application configures it, with requests answered in
 * advance: what the service says that a local one rarely does.
 */
final class S3PluginTest extends TestCase
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

    public function testAPathInTheEndpointIsKeptInRequestsAndUrls(): void
    {
        $http = new FakeClient(['PUT http://s3.example.test/api/photos/a%20b.txt' => '']);
        $disk = $this->disk(['endpoint' => 'http://S3.EXAMPLE.TEST/api/'], $http);

        $disk->write('a b.txt', 'contents');

        self::assertSame('http://s3.example.test/api/photos/a%20b.txt', $disk->url('a b.txt'));
        self::assertSame('/api/photos/a%20b.txt', $http->sent()[0]->getUri()->getPath());
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

    public function testAMoveDoesNotDeleteTheSourceWhenACopyErrorHasAnXmlNamespace(): void
    {
        $http = new FakeClient([
            'PUT *' => FakeClient::response(
                200,
                '<s3:Error xmlns:s3="http://s3.amazonaws.com/doc/2006-03-01/"><s3:Code>InternalError</s3:Code><s3:Message>Try again.</s3:Message></s3:Error>',
            ),
            'DELETE *' => 204,
        ]);
        $this->expectExceptionMessage('S3 refused COPY "a.txt" in the bucket "photos": 200 InternalError: Try again.');

        try {
            $this->disk([], $http)->move('a.txt', 'b.txt');
        } finally {
            self::assertCount(0, $http->sent('DELETE *'));
        }
    }

    public function testAListingErrorInA200BodyIsAStorageFailure(): void
    {
        $http = new FakeClient([
            'GET *' => FakeClient::response(
                200,
                '<Error xmlns="http://s3.amazonaws.com/doc/2006-03-01/"><Code>InternalError</Code><Message>Try again.</Message></Error>',
            ),
        ]);
        $this->expectExceptionMessage('S3 refused LIST "" in the bucket "photos": 200 InternalError: Try again.');

        [...$this->disk([], $http)->files()];
    }

    public function testMalformedFileMetadataIsAStorageFailure(): void
    {
        $http = new FakeClient([
            'HEAD *' => FakeClient::response(200, '', ['Content-Length' => '0', 'Last-Modified' => 'not a date']),
        ]);
        $this->expectExceptionMessage(
            'S3 answered HEAD "a.txt" in the bucket "photos" with an invalid Last-Modified date.',
        );

        $this->disk([], $http)->file('a.txt');
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
            [['timeout' => NAN], 'The disk "cloud" has a "timeout" that is not a number of seconds above zero.'],
            [['timeout' => INF], 'The disk "cloud" has a "timeout" that is not a number of seconds above zero.'],
        ] as [$settings, $said]) {
            try {
                $this->disk($settings);
                self::fail("Settings were taken that should have been refused: {$said}");
            } catch (\LogicException $refused) {
                self::assertStringStartsWith($said, $refused->getMessage());
            }
        }
    }

    public function testAStreamIsSentFromItsBeginning(): void
    {
        $http = new RecordingClient();
        $source = new Factory()->createStreamFromResource(popen('printf unsized', 'r'));
        $this->disk([], $http)->write('unsized.txt', $source);

        self::assertSame(0, $http->state->bodyPosition);
    }

    public function testItIsRegisteredAfterTheFrameworksStorage(): void
    {
        $this->expectExceptionMessage('register it after the framework\'s plugins');
        new App()->register(new S3Plugin());
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function disk(array $settings = [], ?Client $http = null): S3Disk
    {
        $app = new App(new Config(['storage' => ['disks' => ['cloud' => [...self::DISK, ...$settings]]]]));
        $app->register(new StoragePlugin());
        $app->register(new S3Plugin());
        $app->container->instance(Client::class, $http ?? new FakeClient());
        self::assertSame([], $app->container->get(StorageManager::class)->problems());
        $disk = $app->container->get(Storage::class)->disk('cloud');
        self::assertInstanceOf(S3Disk::class, $disk);
        return $disk;
    }
}

final class RecordingClient extends \Crab\HttpClient\Client
{
    public \stdClass $state;

    public function __construct()
    {
        parent::__construct();
        $this->state = new \stdClass();
    }

    public function request(
        string $method,
        string $url,
        array $query = [],
        array $headers = [],
        mixed $json = null,
        array $form = [],
        array $files = [],
        string|StreamInterface|null $body = null,
        ?\Crab\Engine\Async\Cancellation $cancellation = null,
        string|StreamInterface|null $sink = null,
        ?int $maxBytes = null,
        bool $decode = true,
    ): Response {
        $this->state->bodyPosition = $body instanceof StreamInterface ? $body->tell() : null;
        return FakeClient::response();
    }
}

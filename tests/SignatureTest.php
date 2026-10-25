<?php

declare(strict_types=1);

namespace Crab\S3\Tests;

use Crab\S3\Signature;
use PHPUnit\Framework\TestCase;

/**
 * The examples of AWS's own documentation for Signature Version 4 with
 * S3, signed with its example credentials.
 */
final class SignatureTest extends TestCase
{
    private const EMPTY = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';
    private const HOST = 'examplebucket.s3.amazonaws.com';

    public function testAGetWithARange(): void
    {
        self::assertSame(
            'f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41',
            self::signatureOf(
                self::signature()
                    ->headers(
                        'GET',
                        self::HOST,
                        '/test.txt',
                        [],
                        [
                            'Range' => 'bytes=0-9',
                        ],
                        self::now(),
                        self::EMPTY,
                    ),
            ),
        );
    }

    public function testAPutWithAnEncodedPath(): void
    {
        $headers = self::signature()
            ->headers(
                'PUT',
                self::HOST,
                '/test%24file.text',
                [],
                ['Date' => 'Fri, 24 May 2013 00:00:00 GMT', 'x-amz-storage-class' => 'REDUCED_REDUNDANCY'],
                self::now(),
                '44ce7dd67c959e0d3524ffac1771dfbba87d2b6b4b4e99e42034a8b803f8b072',
            );
        self::assertSame(
            '98ad721746da40c64f1a55b78f14c238d841ea1380cd77a1b5971af0ece108bd',
            self::signatureOf($headers),
        );
        self::assertStringContainsString(
            'SignedHeaders=date;host;x-amz-content-sha256;x-amz-date;x-amz-storage-class,',
            $headers['Authorization'],
        );
    }

    public function testQueries(): void
    {
        self::assertSame(
            'fea454ca298b7da1c68078a5d1bdbfbbe0d65c699e0f91ac7a200a0136783543',
            self::signatureOf(
                self::signature()->headers('GET', self::HOST, '/', ['lifecycle' => ''], [], self::now(), self::EMPTY),
            ),
        );
        self::assertSame(
            '34b48302e7b5fa45bde8084f4b7868a86f0a534bc59db6670ed5711ef69dc6f7',
            self::signatureOf(
                self::signature()
                    ->headers(
                        'GET',
                        self::HOST,
                        '/',
                        [
                            'prefix' => 'J',
                            'max-keys' => '2',
                        ],
                        [],
                        self::now(),
                        self::EMPTY,
                    ),
            ),
        );
    }

    public function testAPresignedUrl(): void
    {
        $query = self::signature()->query('GET', self::HOST, '/test.txt', [], 86_400, self::now());
        parse_str($query, $parts);

        self::assertSame('aeeed9bbccd4d02ee5c0109b86d86835f995330da4c265957d157751f604d404', $parts['X-Amz-Signature']);
        self::assertSame('AKIAIOSFODNN7EXAMPLE/20130524/us-east-1/s3/aws4_request', $parts['X-Amz-Credential']);
        self::assertStringContainsString(
            'X-Amz-Credential=AKIAIOSFODNN7EXAMPLE%2F20130524%2Fus-east-1%2Fs3%2Faws4_request',
            $query,
        );
    }

    public function testASessionTokenIsSignedAndSent(): void
    {
        $signature = new Signature('AKIAIOSFODNN7EXAMPLE', 'secret', 'auto', 'session-token');
        $headers = $signature->headers('GET', self::HOST, '/a', [], [], self::now());

        self::assertSame(['session-token', Signature::UNSIGNED], [
            $headers['x-amz-security-token'],
            $headers['x-amz-content-sha256'],
        ]);
        self::assertStringContainsString(';x-amz-security-token,', $headers['Authorization']);
        self::assertStringContainsString('X-Amz-Security-Token=session-token', $signature->query(
            'GET',
            self::HOST,
            '/a',
            [],
            60,
            self::now(),
        ));
    }

    private static function signature(): Signature
    {
        return new Signature('AKIAIOSFODNN7EXAMPLE', 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY', 'us-east-1');
    }

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2013-05-24T00:00:00Z');
    }

    /**
     * @param array<string, string> $headers
     */
    private static function signatureOf(array $headers): string
    {
        return substr($headers['Authorization'], strrpos($headers['Authorization'], '=') + 1);
    }
}

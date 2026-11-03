<?php

declare(strict_types=1);

namespace Crab\S3;

use Crab\Contracts\Container\Container;
use Crab\Contracts\HttpClient\Client;
use Crab\Contracts\Plugin;
use Crab\Contracts\Storage\Drivers;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Adds the "s3" driver to storage.disks. Register it in app.php after the
 * framework's plugins:
 *
 *     ->withPlugins([S3Plugin::class])
 *
 * A disk's settings:
 *
 *     key, secret   the access key's id and secret
 *     bucket        the bucket's name
 *     region        where the bucket is; "us-east-1" unless set, and "auto" for Cloudflare R2
 *     endpoint      the service's address, for one other than AWS; https://s3.{region}.amazonaws.com unless set
 *     path_style    whether the bucket is named in the path, as MinIO needs; true when an endpoint is set
 *     prefix        put before every path, to keep several disks in one bucket; none unless set
 *     url           where the bucket is served from to anyone, for url(); the bucket's own address unless set
 *     token         the session token of temporary credentials
 *     timeout       seconds a request may take; 60
 */
final class S3Plugin implements Plugin
{
    private const SETTINGS = [
        'key',
        'secret',
        'bucket',
        'region',
        'endpoint',
        'path_style',
        'prefix',
        'url',
        'token',
        'timeout',
    ];

    public function register(Container $container): void
    {
        if (!$container->has(Drivers::class)) {
            throw new \LogicException(
                'S3Plugin adds a driver to storage: register it after the framework\'s plugins, which include StoragePlugin.',
            );
        }
        $container->get(Drivers::class)->add('s3', self::SETTINGS, static function (array $settings, string $name) use (
            $container,
        ): S3Disk {
            foreach (['key', 'secret', 'bucket'] as $needed) {
                if (!is_string($settings[$needed] ?? null) || $settings[$needed] === '') {
                    throw new \LogicException("The disk \"{$name}\" needs its \"{$needed}\", which S3 asks for.");
                }
            }
            $region = self::text($name, $settings, 'region') ?? 'us-east-1';
            $endpoint = self::text($name, $settings, 'endpoint');
            $pathStyle = $settings['path_style'] ?? $endpoint !== null;
            $timeout = $settings['timeout'] ?? 60;
            if (!is_bool($pathStyle)) {
                throw new \LogicException("The disk \"{$name}\" has a \"path_style\" that is not true or false.");
            }
            if (!is_int($timeout) && !is_float($timeout) || $timeout <= 0) {
                throw new \LogicException(
                    "The disk \"{$name}\" has a \"timeout\" that is not a number of seconds above zero.",
                );
            }
            return new S3Disk(
                $container->get(Client::class)->timeout((float) $timeout),
                new Signature(
                    (string) $settings['key'],
                    (string) $settings['secret'],
                    $region,
                    self::text($name, $settings, 'token'),
                ),
                (string) $settings['bucket'],
                $endpoint ?? "https://s3.{$region}.amazonaws.com",
                $pathStyle,
                $container->get(StreamFactoryInterface::class),
                $container->get(ClockInterface::class),
                self::text($name, $settings, 'prefix') ?? '',
                self::text($name, $settings, 'url'),
            );
        });
    }

    /**
     * @param array<string, mixed> $settings
     */
    private static function text(string $disk, array $settings, string $key): ?string
    {
        $value = $settings[$key] ?? null;
        if ($value !== null && !is_string($value)) {
            throw new \LogicException("The disk \"{$disk}\" has a \"{$key}\" that is not text.");
        }
        return $value === '' ? null : $value;
    }
}

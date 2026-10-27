# Crab S3

S3 for the [Crab framework](../framework): a disk for [file storage](../framework/docs/storage.md) on Amazon S3, Cloudflare R2, MinIO, Backblaze B2, DigitalOcean Spaces or any service that speaks S3's API. Each request goes through Crab's own HTTP client, so while an upload or a download waits for the service, the worker serves other requests. It has no dependency besides the framework: no AWS SDK.

Use it when files must outlive one machine, when several servers share them, or when clients should download and upload large files straight from the bucket. For files kept on one machine, the framework's `local` disk is enough.

## Installation

```sh
composer require crabphp/s3
```

Register the provider after the framework's, which include the storage it adds a driver to:

```php
// app.php
use App\Providers\AppProvider;
use Crab\S3\S3Provider;

->withProviders([
    S3Provider::class,
    AppProvider::class,
])
```

Then describe the bucket as a disk:

```php
// config/storage.php
return [
    'default' => 'uploads',
    'disks' => [
        'uploads' => [
            'driver' => 's3',
            'key' => env('S3_KEY'),
            'secret' => env('S3_SECRET'),
            'region' => 'eu-west-1',
            'bucket' => 'example-uploads',
        ],
    ],
];
```

Nothing else changes: code written against `Crab\Contracts\Storage\Disk` works on this disk as it does on a local one.

## Settings

| Setting | Default | |
| --- | --- | --- |
| `key`, `secret` | | The access key's id and secret |
| `bucket` | | The bucket's name |
| `region` | `us-east-1` | Where the bucket is; `auto` for Cloudflare R2 |
| `endpoint` | `https://s3.{region}.amazonaws.com` | The service's address, for one other than AWS |
| `path_style` | true when `endpoint` is set | Whether the bucket is named in the path rather than the host; MinIO needs it |
| `prefix` | none | Put before every path, so several disks can share one bucket |
| `url` | the bucket's own address | Where the bucket is served from to anyone, such as a CDN, for `url()` |
| `token` | none | The session token of temporary credentials |
| `timeout` | `60` | Seconds a request may take |

A setting the driver does not know, or a missing key, secret or bucket, stops the disk with its name before any request is sent.

```php
// Cloudflare R2
'r2' => [
    'driver' => 's3',
    'key' => env('R2_KEY'),
    'secret' => env('R2_SECRET'),
    'region' => 'auto',
    'endpoint' => 'https://' . env('R2_ACCOUNT') . '.r2.cloudflarestorage.com',
    'bucket' => 'example-uploads',
],

// MinIO
'minio' => [
    'driver' => 's3',
    'key' => 'minioadmin',
    'secret' => 'minioadmin',
    'endpoint' => 'http://127.0.0.1:9000',
    'bucket' => 'example-uploads',
],
```

## How it behaves

- **Writing.** A file is written with one request, which the service makes whole or not at all, up to 5 GB. A stream that cannot say its length is copied to a temporary file first, because S3 must be told the length before the body.
- **Reading.** `read()` returns once the whole file has arrived, in memory up to 1 MiB and in a temporary file beyond. Bytes are never decompressed on the way, and `http_client.max_bytes` does not apply: the file is read whatever its size. For a large download, hand the client a `temporaryUrl()`.
- **Signed URLs.** `temporaryUrl()` and `temporaryUploadUrl()` are signed here, with no request to the service, and last from one second to seven days. An upload URL given a media type requires the client to send it as its `Content-Type`.
- **Copying.** `copy()` and `move()` copy inside the service, without downloading the file. A copy the service reports as failed after answering 200 is a failure.
- **Listing.** `files()` reads the bucket a page of 1,000 at a time as you iterate.
- **Signing.** Requests are signed with AWS Signature Version 4; the body is declared unsigned, so a large upload is read once. Use `https` endpoints, as every hosted service does.
- **Failures.** A request the service refuses throws `Crab\Contracts\Storage\StorageException` with its status and S3's error code and message; a missing file throws `FileNotFound`; a service that cannot be reached throws `StorageException` with the network error.

## Testing

The tests run against a real service when `CRAB_TEST_S3_ENDPOINT`, `CRAB_TEST_S3_KEY` and `CRAB_TEST_S3_SECRET` are set, and are skipped otherwise:

```sh
docker run -d -p 9010:9000 -e MINIO_ROOT_USER=crabtest -e MINIO_ROOT_PASSWORD=crabtestsecret minio/minio server /data
CRAB_TEST_S3_ENDPOINT=http://127.0.0.1:9010 CRAB_TEST_S3_KEY=crabtest CRAB_TEST_S3_SECRET=crabtestsecret composer test
```

In an application's tests, use the framework's `memory` disk instead; see [File storage](../framework/docs/storage.md#testing).

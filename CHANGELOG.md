# Changelog

Crab S3 follows [semantic versioning](https://semver.org). Before 1.0, a minor version may break what an application writes; the entry says what to change.

## Unreleased

- **S3 responses are checked before a move removes its source:** namespaced or unconfirmed copy responses, malformed listings and invalid file metadata now become storage failures.
- **Endpoint paths are preserved:** an S3 service behind a reverse proxy may include a path in `endpoint`; schemes, hosts and default ports are normalized before signing.
- **Timeouts from environment variables work as numbers:** finite numeric strings are accepted, while zero, `NAN` and `INF` are refused.
- **The test dependency avoids a PHPUnit code-execution advisory:** development installs require PHPUnit 13.4.4 or newer.

## 0.1.0 — 2026-11-05

- **First release,** for `crabphp/framework` 0.6: s3 for the Crab framework: a disk on Amazon S3, Cloudflare R2, MinIO or any service that speaks S3's API, that waits without holding a worker.

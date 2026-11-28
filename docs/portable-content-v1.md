# Portable content installation

The existing Unpacker Tools screen accepts available Gateway packages or uploaded ZIPs. It retains the schema 1 Core-only Pattern archive workflow and recognizes the selection-scoped schema 2 format on the portable-content feature branch.

## Site preparation

Install the Unpacker and, for selections that declare custom components, the independently built SyndaMorph Runtime. The import checks each required component version before writing posts or assets. Core-only selections can be installed on a clean site with Unpacker alone.

For remote Gateway connections, use a public HTTPS origin. For a customer-only loopback Gateway on a local WordPress site, explicitly set the deployment values in `wp-config.php`.

```php
define( 'WP_ENVIRONMENT_TYPE', 'local' );
define( 'SMUP_LOCAL_GATEWAY_ORIGIN', 'http://127.0.0.1:8087' );
```

Enter the same origin and the issued customer credential in Tools → Unpacker POC. The local exception permits only the configured literal IPv4 loopback address and port. Requests use only the client protocol paths and reject redirects.

## What remains installed

Pages are unpublished WordPress drafts. Patterns are native unsynced Patterns. Images are WordPress attachments. Resolved font and style dependencies remain in WordPress storage. The standalone Runtime renders the custom blocks and the isolated production canvas once Unpacker is removed.

For a saved Pattern, the Runtime supplies local font faces to inserted copies. The destination theme continues to control layout for native pages. Production-canvas pages use their own verified stylesheet.

Reimporting the same item, including one that was later offered in a larger selection, reuses the installed record and preserves user edits. Exact file and version integrity are checked before installation. If a new item fails to install, Unpacker removes new records and files from that attempt.

## Verification

```text
php tests/import-test.php
php tests/gateway-client-test.php
php tests/portable-package-test.php
php tests/updater-test.php
```

The portable archive test requires PHP ZipArchive. See the [producer's portable content contract](https://github.com/gnznovus/SyndaMorph/blob/feat/secure-gateway-leasing-v1/docs/portable-content-v1.md) for the package format, Runtime build, and local Playground acceptance runner.

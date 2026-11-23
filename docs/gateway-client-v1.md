# Optional Secure Gateway client — V1 integration

The standalone SyndaMorph Unpacker POC can now connect to a customer's private Secure Gateway without changing the independent Core-pattern import/archive contract.

## Client behavior

1. In **Tools → Unpacker POC**, enter a **public HTTPS** Gateway URL and the **one customer-level** `smup_...` API key.
2. Press **Connect to Gateway**. WordPress sends a server-side request to `GET /v1/client/me` before saving anything.
3. The API key is encrypted using AES-256-GCM with WordPress salts, stored in WordPress options with autoload disabled, and never rendered back to the browser.
4. Authorized packages are discovered through `GET /v1/packages`. The list shows only packages with currently valid leases.
5. Press **Download & Unpack** for a published version. WordPress asks for a 60-second grant, downloads the ZIP, validates size/SHA-256 against the published version and grant, then uses the **existing** strict ZIP parser and `smup_import()`. No new importer or publishing pathway is introduced.
6. Disconnect or revoke the key/lease. Already imported `wp_block` patterns and physical version archives remain completely independent.

Current POC remains restricted to self-contained ZIPs with exactly `manifest.json` and `pattern.blocks.html`, valid checksums, and supported **Core Gutenberg text/layout blocks**. A package containing arbitrary scripts, assets, custom blocks, or multiple pattern files is still rejected intentionally. This is not yet the general-purpose production artifact contract.

## Remote Playground connection

A remote WordPress Playground **cannot** reach the private Docker hostname `gateway:3200`. A temporary HTTPS tunnel must point to the opt-in Gateway **customer-only proxy**, never the Gateway container directly.

See SyndaMorph `docs/secure-gateway-leasing-v1.md` for the `gateway-tunnel` Compose profile and optional `cloudflared` example. The customer-only proxy returns 404 for `/admin/v1/`. Use disposable test keys and shut down the tunnel when finished.

**Do not put the administrative Gateway token into the Unpacker.** It uses only a customer's API key.

## Limitations and test gate

- No automatic artifact update: package discovery and installation are manual.
- The Gateway admin interface can register packages and leases but does not yet upload/publish ZIP bytes. A test package must be provisioned and published separately before the full import path can be tested.
- Gateway server enforces ownership and lease expiration/revocation. Unpacker does not enforce lease state locally or disable existing content.
- Encrypted options rely on stable WordPress salts, protected database backups, and secure administrator sessions. Revoking keys is still required if the server is compromised.
- HTTPS URLs are validated by WordPress HTTP transport; loopback, private IPs, invalid certificates, redirects, and arbitrary paths are not supported by the remote client.
- The POC caps downloaded ZIPs at 3 MiB. Larger Gateway artifacts remain unavailable until the general-purpose importer is built.
- Unit/smoke tests cover endpoint validation, encryption, discovery, and rejection of mismatched grants and denied leases. **Live remote Playground validation still requires a reachable HTTPS Gateway and a published compatible ZIP**.

## Checks

```bash
php tests/import-test.php
php tests/gateway-client-test.php
php tests/updater-test.php
```

The existing GitHub native plugin updater remains completely separate from Gateway credentials, package leases, and downloads.

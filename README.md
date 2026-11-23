# SyndaMorph Unpacker

Standalone client-side WordPress plugin for importing SyndaMorph artifact packages as native Gutenberg patterns and saving persistent, versioned local archives.

**This is the independence POC.** Core text/layout blocks only; no API key, inference, or dependency on SyndaMorph. Published patterns are stored as native WordPress `wp_block` posts; archives are in `wp-content/uploads/syndamorph-unpacker-archives/` and survive plugin deactivation/removal (subject to site storage retention).

## Install and test

Install a built ZIP containing `syndamorph-unpacker-poc/`, activate the plugin, then open **Tools → Unpacker POC**. Import the bundled mock or upload a compatible package ZIP. Deactivate/delete the plugin and verify the pattern remains editable and archived files remain under uploads.

See [plugin documentation](syndamorph-unpacker-poc/README.md) and [updater test procedure](syndamorph-unpacker-poc/UPDATER-TEST.md).

## Development

```sh
php tests/import-test.php
php tests/updater-test.php
python3 scripts/package_release.py . dist/syndamorph-unpacker-poc.zip 0.1.1
```

The updater requires a **published stable GitHub Release** with the exact asset `syndamorph-unpacker-poc.zip`. A source archive is not an update package. The release workflow creates a draft and never publishes without approval.

This repository owns the Unpacker implementation. SyndaMorph's repo owns the production backend and source conversion tools, not this client installation plugin.

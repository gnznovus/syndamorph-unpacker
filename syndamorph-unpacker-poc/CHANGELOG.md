# Changelog

## [0.1.4] - 2026-11-23

- Add optional Secure Gateway customer connection using encrypted client-level API keys.
- Discover private leased packages and download authorized versions with one-time grants.
- Verify downloaded ZIP integrity before importing through the existing Core Gutenberg pipeline.
- Preserve standalone imports, versioned archives and native GitHub updater independence.


## [0.1.3] - 2026-11-23

- Ensure WordPress recognizes automatic-update support even before the first published release or during a GitHub outage.
- Reduce failed release metadata caching from 15 minutes to 60 seconds.
- Preserve Core-block imports and local version archives.

## [0.1.2] - 2026-11-23

- Release-channel acceptance build for WordPress native updater detection.
- Added visible v0.1.2 marker on the Unpacker POC screen.
- Core pattern import and local physical archive behavior unchanged.

## [0.1.1] - 2026-11-23

- Added token-free GitHub Releases updater through the native WordPress plugin update system.
- Added update metadata validation, version comparison and plugin information dialog.
- No changes to mock import, published Gutenberg patterns or versioned physical archives.

## [0.1.0] - 2026-11-23

- Initial standalone WordPress Core pattern unpacker and archive independence POC.

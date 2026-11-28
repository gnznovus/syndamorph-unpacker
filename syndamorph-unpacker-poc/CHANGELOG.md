# Changelog

## [0.1.6-rc.1] - 2026-11-28

- Install selected Gutenberg pages as unpublished drafts and Patterns as unsynced WordPress records.
- Verify leased package bytes, remap local media and typography, check Runtime compatibility, and roll back failed installs.
- Add an opt-in, pinned loopback Gateway origin for local WordPress sites.
- Verify BKKGO Draft 66 in WordPress Playground through Gutenberg save and reload, Unpacker removal, and an offline reopen.

## [0.1.5] - 2026-11-23

- Add a 15-minute WP-Cron heartbeat to refresh the latest published GitHub release metadata on active sites.
- Add a manual GitHub update check that clears both plugin release metadata and WordPress update caches.
- Display installed/latest versions, last successful or failed check, and an update link within the Unpacker admin page.
- Reduce successful release-metadata cache lifetime from six hours to five minutes; preserve native WordPress update installation and the published-release gate.


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

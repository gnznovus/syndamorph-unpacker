# Unpacker POC updater test

Updater source is modeled after SyndaMorph's token-free GitHub Releases updater.

**Release channel:** `https://github.com/gnznovus/syndamorph-unpacker` (separate repository).
Until a newer public, published release exists, the WordPress updater cannot
retrieve a live update. The self-contained PHP tests use mocked GitHub API metadata.

## How to enable live end-to-end testing

1. Use this standalone repository `gnznovus/syndamorph-unpacker`.
2. Verify the fixed plugin folder is `syndamorph-unpacker-poc` with main file
   `syndamorph-unpacker-poc.php`.
3. Prepare a v0.1.2 commit in this repository, bump its plugin header/changelog,
   and tag the matching stable commit `v0.1.2` (or later).
4. Publish a non-draft, non-prerelease GitHub Release for the tag and attach a ZIP
   **named exactly `syndamorph-unpacker-poc.zip`**. The ZIP root folder must be
   `syndamorph-unpacker-poc/`, and its main plugin header version must match the tag.
5. Install the earlier `v0.1.1` POC ZIP in WordPress Playground, then visit
   **Dashboard → Updates → Check Again** or **Plugins → Check for updates**.
6. Confirm WordPress offers the newer Unpacker release. Click **Update now**,
   verify the version changes, and re-open the import screen.
7. Confirm preexisting `wp_block` patterns and uploaded physical versions remain
   untouched after the update. Test another reimport to create a further archive version.
8. Optionally enable WordPress's native **Enable auto-updates** toggle for this plugin.

The updater intentionally refuses GitHub source archives and requires the explicitly
named published release ZIP. It does not automatically publish releases or install a
candidate from a development branch.

## Local smoke check

Run: `php tests/updater-test.php`

The test covers accepted and rejected release metadata, tokenless GitHub metadata
requests, version comparisons, plugin slug/filename scope, and the information dialog.
The existing independence test remains: `php tests/import-test.php`.

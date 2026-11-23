# Unpacker POC updater test

**Release channel:** https://github.com/gnznovus/syndamorph-unpacker

The updater is token-free and uses WordPress's native plugin-update API. It only accepts a newer published, stable GitHub Release carrying the exact asset `syndamorph-unpacker-poc.zip`.

## Prepare a release — automated GitHub Actions

1. Bump the version in `syndamorph-unpacker-poc/syndamorph-unpacker-poc.php` and add a matching **top** entry in `CHANGELOG.md`.
2. Push the commit to `main`. **GitHub Actions triggers automatically**; there is no need to manually run a workflow, create a tag, or upload a ZIP.
3. On a new version, the workflow validates the version and changelog, runs PHP syntax/import/updater tests, packages the plugin, verifies the ZIP checksum, creates the tag, and opens an **unpublished release draft**. Other pushes run the existing CI workflow but skip new release preparation when the version is already tagged or released.
4. Review the draft release and click **Publish release** when approved. The workflow does not publish or deploy automatically.

For an interrupted draft-generation run, use **Actions → Prepare Unpacker Release Draft → Run workflow** on the exact tagged commit; an existing tag is never silently moved. Manual retries on later commits with that version will fail closed.

No manual tag or ZIP upload is required. An existing release is never overwritten.

## WordPress acceptance test

1. Install v0.1.1 in WordPress Playground.
2. After v0.1.2 is **published**, visit **Dashboard → Updates → Check Again**.
3. Confirm the native update notice, update to v0.1.2, then verify the version marker in **Tools → Unpacker POC**.
4. Verify imported `wp_block` patterns and physical archives under `wp-content/uploads/syndamorph-unpacker-archives/` are preserved.
5. Import the mock package again and verify another numbered snapshot is created.

The PHP smoke tests can be run directly: `php tests/import-test.php` and `php tests/updater-test.php`.

### Limitations

This workflow does not auto-bump source version numbers: a release must correspond to reviewed, committed source. WordPress update discovery can be cached; a published release may require **Check Again** or waiting for the cache to expire. No published release means no update offer.

# Unpacker POC updater test

**Release channel:** https://github.com/gnznovus/syndamorph-unpacker

The updater uses the native WordPress plugin-update API. It offers newer published stable GitHub Releases that carry the exact asset `syndamorph-unpacker-poc.zip`.

## Configure access to the private release channel

Install the first build with private-release support (v0.1.4 or later) using **Plugins → Add New Plugin → Upload Plugin**. If prompted, select **Replace current with uploaded** and confirm the plugin is active. Earlier versions make anonymous GitHub requests, so they require this one-time manual update.

Create a fine-grained GitHub token restricted to `gnznovus/syndamorph-unpacker` with **Contents: Read-only**. Supply it through server-side configuration and define `SMUP_GITHUB_TOKEN` in `wp-config.php` before WordPress loads. For a host that provides the credential as a PHP environment variable, use:

```php
define( 'SMUP_GITHUB_TOKEN', getenv( 'SMUP_GITHUB_TOKEN' ) ?: '' );
define( 'SMUP_UPDATE_DIAGNOSTICS', true );
```

Keep the credential in local or server-side secret storage. The GitHub API accepts anonymous access for public releases. [GitHub release asset permissions](https://docs.github.com/en/rest/releases/assets#get-a-release-asset)

## Prepare a release — automated GitHub Actions

1. Bump the version in `syndamorph-unpacker-poc/syndamorph-unpacker-poc.php` and add a matching **top** entry in `CHANGELOG.md`.
2. Push the commit to `main`. **GitHub Actions triggers automatically**; there is no need to manually run a workflow, create a tag, or upload a ZIP.
3. On a new version, the workflow validates the version and changelog, runs PHP syntax/import/updater tests, packages the plugin, verifies the ZIP checksum, creates the tag, and opens an **unpublished release draft**. Other pushes run the existing CI workflow but skip new release preparation when the version is already tagged or released.
4. Review the draft release and click **Publish release** when approved. The workflow does not publish or deploy automatically.

For an interrupted draft-generation run, use **Actions → Prepare Unpacker Release Draft → Run workflow** on the exact tagged commit; an existing tag is never silently moved. Manual retries on later commits with that version will fail closed.

No manual tag or ZIP upload is required. An existing release is never overwritten.

## WordPress Playground acceptance test

1. Start a disposable local Playground with outbound HTTPS. For example, run `npx @wp-playground/cli server --wp=6.8 --php=8.1 --login`. Apply the configuration above to that site's WordPress runtime. With Playground CLI, a local secret directory can be mounted outside the document root with `--mount-dir /absolute/local/private-dir /tmp/unpacker-private`. In the disposable site's `wp-config.php`, read the token using `trim( file_get_contents( '/tmp/unpacker-private/github-token' ) )`.
2. Install and activate a test ZIP built from the fixed source. To exercise an upgrade while v0.1.3 remains the latest published release, set only the `Version` header to `0.1.2` in a disposable copy before packaging. For an acceptance test between future releases, use the older fixed build and a newer published stable release.
3. Open **Tools → Unpacker POC** and import the built-in mock twice. Record the pattern ID, its content, and the filenames and SHA-256 hashes under `wp-content/uploads/syndamorph-unpacker-archives/pattern-{ID}/`.
4. Open **Plugins** and click **Check for updates** by **Deactivate**. Expect GitHub HTTP 200, a newer version, valid ZIP metadata, and a WordPress `response` entry with a package. Confirm **Enable auto-updates** appears when the site's settings permit it.
5. Open **Dashboard → Updates**. Select the Unpacker and run **Update Plugins**, or use **update now** on the Plugins screen. Check the installed version, active status, and pattern ID, content, and archive hashes from step 3.
6. Remove `SMUP_UPDATE_DIAGNOSTICS` from `wp-config.php` after testing. The row action is opt-in.

The PHP smoke tests can be run directly: `php tests/import-test.php` and `php tests/updater-test.php`.

### Limitations

**Check for updates** bypasses the plugin's release cache and forces WordPress to recheck. WordPress must reach its own update API before it invokes the hostname hook. The diagnostic reports both requests so a Playground networking problem is visible separately from GitHub access. [WordPress update check implementation](https://developer.wordpress.org/reference/functions/wp_update_plugins/)

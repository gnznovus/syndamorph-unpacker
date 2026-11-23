# Unpacker updater diagnosis

## Root cause

`gnznovus/syndamorph-unpacker` is a private repository. Versions 0.1.1–0.1.3 contacted the release API without authentication and used the public browser ZIP URL as the package. Anonymous requests returned HTTP 404 for both the latest-release API and the v0.1.2 and v0.1.3 ZIP URLs. Authorized requests returned HTTP 200 for both release records and reported published stable releases with an uploaded `syndamorph-unpacker-poc.zip`. The latest release was v0.1.3. Its asset API returned an HTTP 302 to the signed download host, which served the ZIP.

Fresh checks in local WordPress Playground 6.8.13 with PHP 8.1.34 used the expected basename `syndamorph-unpacker-poc/syndamorph-unpacker-poc.php` and the `https://github.com/gnznovus/syndamorph-unpacker` Update URI. WordPress.org returned HTTP 200 and core ran `update_plugins_github.com` in each case.

| Installed build | GitHub API | Core `response` | Core `no_update` | Native auto-updates control |
| --- | --- | --- | --- | --- |
| v0.1.1 source from `331b1f5`, anonymous | 404 | Absent | Absent | Hidden |
| Published v0.1.3 ZIP, anonymous | 404 | Absent | v0.1.3, no package | Visible |
| Fixed source with temporary v0.1.2 header, authenticated | 200 | v0.1.3, package present | Absent | Visible |

The v0.1.1 updater returned `false` on lookup failure. WordPress had neither a `response` nor a `no_update` entry and marked the row as unsupported. v0.1.3 returned fallback `no_update` metadata, so the toggle appeared even though GitHub still returned 404. Refreshing either cache reproduced the same access failure. [WordPress update check](https://developer.wordpress.org/reference/functions/wp_update_plugins/) · [WordPress plugin list table](https://developer.wordpress.org/reference/classes/wp_plugins_list_table/prepare_items/)

## Correction and live verification

The updater accepts a read credential from `wp-config.php` and sends it only to the repository's GitHub release API and validated asset endpoint. The adapter follows the signed ZIP redirect without the credential. WordPress still writes the update transient and performs ZIP extraction and installation. The opt-in **Check for updates** action performs a fresh lookup and shows both request statuses and the native updater state. A request without its nonce returned HTTP 403. [GitHub release asset API](https://docs.github.com/en/rest/releases/assets#get-a-release-asset)

Playground's **Dashboard → Updates** listed the v0.1.3 offer, and the Plugins row showed **Enable auto-updates**. The native Plugins updater installed the live GitHub release and kept the plugin active. The installed updater file matched the v0.1.3 release ZIP's SHA-256 for that file, `2b4ea89ce1c70ec4cab11de41670389a6544ca536e48aed192f2d799924b9bfb`. The same published `wp_block` ID and content hash and all 11 archive file hashes, including both numbered versions, matched before and after the upgrade.

The test used a disposable copy of the fixed code with an older version header to exercise the current published v0.1.3 asset. Existing v0.1.1–v0.1.3 installations need a one-time manual replacement with the fixed build. No new release was published. [Configuration and manual Playground verification](syndamorph-unpacker-poc/UPDATER-TEST.md)

# SyndaMorph Client Unpacker — Independence POC

This is an **independent WordPress plugin**. It has no dependency on SyndaMorph, Purr, any provider, or any API key. It is deliberately limited to self-contained **WordPress Core text/layout blocks**.

## Exact Playground test

1. In your disposable WordPress Playground, go to **Plugins → Add New → Upload Plugin** and install `syndamorph-unpacker-poc.zip`. Activate it.
2. Open **Tools → Unpacker POC**. Click **Import built-in mock package** (or upload `syndamorph-mock-package.zip` using **Unpack ZIP**).
3. Confirm **Unpacked successfully** and use **Open in Gutenberg** to inspect the pattern named **Independence POC — Core Layout**.
4. Optional versioning test: import the same package a second time; the row should report **2 archived versions**, not a second duplicate pattern.
5. Go to **Plugins** and **Deactivate** the unpacker. This is the core acceptance test.
6. In WordPress's **Patterns** screen (often **Appearance → Editor → Patterns**, depending on the active theme), locate **Independence POC — Core Layout** under **Unpacker POC** or **My Patterns**. Open it, edit a paragraph, save it and insert it into a page. Check the front end.
7. Confirm physical snapshots still exist at `wp-content/uploads/syndamorph-unpacker-archives/pattern-{ID}/versions/v000001/` (and `v000002/` if you imported twice).

**Expected:** The pattern is a native `wp_block` post. It remains after the Unpacker is deactivated and requires no Unpacker PHP/JS at render time. The backup files remain because they're outside `wp-content/plugins`.

**Caution:** WordPress Playground browser instances may use ephemeral storage. This test is about plugin deactivation, not retention across a reset of the entire Playground environment. To inspect physical files, use Playground's file browser/export tools or a local WP instance.

## ZIP package format

Two files at ZIP root, no directories:

- `manifest.json`: artifact schema, title, slug, SHA-256, `plugin_required=false`, `runtime=wordpress-core`, empty dependencies/assets.
- `pattern.blocks.html`: Gutenberg block markup.

No executable scripts, external assets, custom blocks, or plugin-specific namespaces are accepted in this POC. The mock package is bundled with the plugin and also delivered as a separate ZIP for the upload route.

## Backup policy

The plugin does **not** register custom blocks, custom render filters or uninstall cleanup. Version files under uploads are snapshots, not runtime dependencies. Re-imports append immutable numbered versions. Editing a saved pattern directly in Gutenberg does **not yet** write another archive version; that belongs to the full unpacker design.

On Nginx, explicitly deny web access to the archive folder if the deployment can serve it. `.htaccess` and `web.config` guards are included for Apache/IIS. Keep off-site backups for actual disaster recovery. This POC does not guarantee appearance across arbitrary theme changes.

## Validation status

PHP syntax and an isolated smoke test of built-in import, Core allowlist, re-import, SHA-256 and archiving are included in development validation. **Live Playground deactivation and editor verification must be performed by the tester.**

## Native GitHub updater

The updater uses WordPress's native `Update URI` header and
`update_plugins_github.com` hook. It accepts published stable releases from
`gnznovus/syndamorph-unpacker` with the exact asset `syndamorph-unpacker-poc.zip`.
This private repository requires a read credential for updater requests.
Importing packages and accessing saved patterns and archives work independently
of GitHub. See [UPDATER-TEST.md](UPDATER-TEST.md) for setup and verification.

<?php
/**
 * Plugin Name: SyndaMorph Client Unpacker — Independence POC
 * Description: Standalone proof that Core Gutenberg patterns and physical version archives survive unpacker deactivation.
 * Version: 0.1.5
 * Update URI: https://github.com/gnznovus/syndamorph-unpacker
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Text Domain: syndamorph-unpacker-poc
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once plugin_dir_path( __FILE__ ) . 'includes/updater.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/gateway-client.php';

/** The published output is a WordPress-owned wp_block; no render hooks are registered. */
const SMUP_MAX_ZIP_BYTES = 3145728;
const SMUP_MAX_CONTENT_BYTES = 2097152;

function smup_error( string $message, int $status = 400 ): WP_Error {
    return new WP_Error( 'smup_poc', $message, array( 'status' => $status ) );
}

function smup_archive_root(): string {
    $uploads = wp_upload_dir( null, false );
    return trailingslashit( $uploads['basedir'] ) . 'syndamorph-unpacker-archives';
}

function smup_archive_dir( int $post_id ): string {
    return trailingslashit( smup_archive_root() ) . 'pattern-' . $post_id;
}

/** In this deliberately narrow POC, every referenced block must be native Core. */
function smup_validate_blocks( string $content ) {
    if ( '' === trim( $content ) || strlen( $content ) > SMUP_MAX_CONTENT_BYTES || ! has_blocks( $content ) ) {
        return smup_error( 'Package content must contain Gutenberg blocks and be 2 MB or smaller.' );
    }
    $allowed = array_fill_keys( array(
        'core/group', 'core/columns', 'core/column', 'core/heading', 'core/paragraph',
        'core/separator', 'core/spacer', 'core/buttons', 'core/button', 'core/list',
        'core/list-item', 'core/quote',
    ), true );
    $walk = static function ( array $blocks ) use ( &$walk, $allowed ): bool {
        foreach ( $blocks as $block ) {
            $name = $block['blockName'] ?? null;
            if ( null === $name ) {
                if ( '' !== trim( (string) ( $block['innerHTML'] ?? '' ) ) ) { return false; }
                continue;
            }
            if ( ! isset( $allowed[ $name ] ) || ! WP_Block_Type_Registry::get_instance()->is_registered( $name ) ) { return false; }
            if ( ! $walk( $block['innerBlocks'] ?? array() ) ) { return false; }
        }
        return true;
    };
    if ( ! $walk( parse_blocks( $content ) ) ) {
        return smup_error( 'POC only accepts registered Core text/layout blocks; no custom, HTML or embed blocks.' );
    }
    // POC excludes external assets/runtime references, not just custom block names.
    if ( preg_match( '~<\s*/?\s*(?:script|style|iframe|object|embed|form|img|svg|video|audio|link)\b|\bon[a-z]+\s*=|\b(?:src|srcset|href)\s*=|\b(?:https?://|javascript:|data:|url\s*\(|@import)\b|/plugins/|/wp-json/~i', $content ) ) {
        return smup_error( 'POC does not allow executable content, links, remote dependencies or plugin assets.' );
    }
    return true;
}

function smup_read_zip_file( string $filename ) {
    if ( ! is_file( $filename ) || (int) filesize( $filename ) > SMUP_MAX_ZIP_BYTES || (int) filesize( $filename ) <= 0 ) {
        return smup_error( 'Package ZIP exceeds the allowed size or is missing.' );
    }
    if ( ! class_exists( 'ZipArchive' ) ) { return smup_error( 'PHP ZipArchive is unavailable.' ); }
    $zip = new ZipArchive();
    if ( true !== $zip->open( $filename, ZipArchive::RDONLY ) ) { return smup_error( 'The ZIP cannot be opened.' ); }
    try {
        if ( 2 !== $zip->numFiles ) { return smup_error( 'POC ZIP must contain exactly manifest.json and pattern.blocks.html.' ); }
        $expected = array( 'manifest.json' => true, 'pattern.blocks.html' => true );
        for ( $i = 0; $i < $zip->numFiles; $i++ ) {
            $entry = $zip->statIndex( $i );
            $name = $entry['name'] ?? '';
            if ( ! isset( $expected[ $name ] ) || (int) ( $entry['size'] ?? 0 ) > SMUP_MAX_CONTENT_BYTES ||
                (int) ( $entry['comp_size'] ?? 0 ) > SMUP_MAX_ZIP_BYTES ) {
                return smup_error( 'Unexpected or oversized ZIP entry.' );
            }
            unset( $expected[ $name ] );
        }
        if ( $expected ) { return smup_error( 'Package entries missing.' ); }
        $manifest_text = $zip->getFromName( 'manifest.json' );
        $content = $zip->getFromName( 'pattern.blocks.html' );
    } finally { $zip->close(); }
    return smup_parse_package( $manifest_text, $content );
}

function smup_read_package( ?array $upload = null ) {
    if ( null === $upload ) {
        $base = plugin_dir_path( __FILE__ ) . 'sample-package/';
        return smup_parse_package( @file_get_contents( $base . 'manifest.json' ), @file_get_contents( $base . 'pattern.blocks.html' ) );
    }
    if ( UPLOAD_ERR_OK !== ( $upload['error'] ?? -1 ) || ! is_uploaded_file( $upload['tmp_name'] ?? '' ) ||
        (int) ( $upload['size'] ?? 0 ) > SMUP_MAX_ZIP_BYTES ||
        ! preg_match( '/\\.zip$/i', (string) ( $upload['name'] ?? '' ) ) ) {
        return smup_error( 'Upload a valid package ZIP up to 3 MB.' );
    }
    return smup_read_zip_file( $upload['tmp_name'] );
}

function smup_parse_package( $manifest_text, $content ) {
    $manifest = is_string( $manifest_text ) ? json_decode( $manifest_text, true ) : null;
    if ( ! is_array( $manifest ) || ! is_string( $content ) ||
        1 !== ( $manifest['schema'] ?? null ) ||
        'wordpress-core-pattern' !== ( $manifest['type'] ?? null ) ||
        'wordpress-core' !== ( $manifest['runtime'] ?? null ) ||
        false !== ( $manifest['plugin_required'] ?? null ) ||
        'pattern.blocks.html' !== ( $manifest['content'] ?? null ) ||
        ! empty( $manifest['dependencies'] ) || ! empty( $manifest['assets'] ) ||
        ! is_string( $manifest['title'] ?? null ) || ! is_string( $manifest['slug'] ?? null ) ||
        ! preg_match( '/^[a-z0-9][a-z0-9-]{0,70}$/', $manifest['slug'] ) ||
        ! is_string( $manifest['content_sha256'] ?? null ) ||
        ! preg_match( '/^[a-f0-9]{64}$/', $manifest['content_sha256'] ) ||
        ! hash_equals( $manifest['content_sha256'], hash( 'sha256', $content ) ) ) {
        return smup_error( 'Package metadata or content checksum is invalid.' );
    }
    $valid = smup_validate_blocks( $content );
    if ( is_wp_error( $valid ) ) { return $valid; }
    $title = sanitize_text_field( $manifest['title'] );
    if ( '' === $title ) { return smup_error( 'Package title cannot be blank.' ); }
    return array( 'title' => $title, 'slug' => $manifest['slug'], 'content' => $content );
}

/** Snapshot bytes outside wp-content/plugins. Never mutate earlier versions. */
function smup_snapshot( int $post_id, array $package ) {
    $root = smup_archive_root();
    $dir = smup_archive_dir( $post_id );
    $versions = $dir . '/versions';
    if ( ! wp_mkdir_p( $versions ) ) { return smup_error( 'Cannot create local archive folder.', 500 ); }
    // Defense in depth for Apache/IIS; configure nginx directory denial separately.
    foreach ( array( $root, $dir, $versions ) as $folder ) {
        foreach ( array(
            'index.php' => '<?php // No directory listing.',
            '.htaccess' => "Require all denied\nDeny from all\n",
            'web.config' => '<?xml version="1.0"?><configuration><system.webServer><security><requestFiltering><hiddenSegments><add segment="syndamorph-unpacker-archives"/></hiddenSegments></requestFiltering></security></system.webServer></configuration>',
        ) as $name => $body ) {
            if ( ! file_exists( $folder . '/' . $name ) && false === @file_put_contents( $folder . '/' . $name, $body, LOCK_EX ) ) {
                return smup_error( 'Cannot protect the archive folder.', 500 );
            }
        }
    }
    $handle = @fopen( $dir . '/.version.lock', 'c' );
    if ( ! $handle || ! flock( $handle, LOCK_EX ) ) {
        if ( $handle ) { fclose( $handle ); }
        return smup_error( 'Could not acquire archive lock.', 500 );
    }
    try {
        $next = 1;
        foreach ( glob( $versions . '/v[0-9][0-9][0-9][0-9][0-9][0-9]' ) ?: array() as $name ) {
            $next = max( $next, (int) substr( basename( $name ), 1 ) + 1 );
        }
        $version = sprintf( 'v%06d', $next );
        $dest = $versions . '/' . $version;
        if ( ! @mkdir( $dest, 0750 ) ) { return smup_error( 'Could not reserve version folder.', 500 ); }
        $metadata = array(
            'schema' => 1, 'pattern_id' => $post_id, 'version' => $version,
            'title' => $package['title'], 'slug' => $package['slug'],
            'created_utc' => gmdate( 'c' ),
            'content_sha256' => hash( 'sha256', $package['content'] ),
            'runtime' => 'wordpress-core', 'plugin_required' => false, 'assets' => array(),
        );
        $json = wp_json_encode( $metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
        $length = @file_put_contents( $dest . '/pattern.blocks.html', $package['content'], LOCK_EX );
        $mlength = $json ? @file_put_contents( $dest . '/manifest.json', $json, LOCK_EX ) : false;
        if ( strlen( $package['content'] ) !== $length || ! is_string( $json ) || strlen( $json ) !== $mlength ) {
            @unlink( $dest . '/pattern.blocks.html' ); @unlink( $dest . '/manifest.json' ); @rmdir( $dest );
            return smup_error( 'Snapshot was not written completely.', 500 );
        }
        return $metadata;
    } finally {
        flock( $handle, LOCK_UN );
        fclose( $handle );
    }
}

/** Each import reuses only the exact POC-owned slug and adds a new version. */
function smup_import( array $package ) {
    $slug = $package['slug'];
    $existing = get_posts( array(
        'post_type' => 'wp_block', 'post_status' => 'any', 'numberposts' => 1,
        'meta_key' => '_smup_poc_slug', 'meta_value' => $slug,
    ) );
    $id = $existing ? (int) $existing[0]->ID : 0;
    if ( $id && ! current_user_can( 'edit_post', $id ) ) { return smup_error( 'Cannot update this pattern.', 403 ); }
    $previous = $id ? get_post( $id ) : null;
    $post = array(
        'post_type' => 'wp_block', 'post_status' => 'publish',
        'post_title' => $package['title'], 'post_content' => $package['content'],
    );
    if ( $id ) { $post['ID'] = $id; }
    $saved = wp_insert_post( wp_slash( $post ), true );
    if ( is_wp_error( $saved ) || ! $saved ) { return smup_error( 'Could not publish WordPress pattern.', 500 ); }
    $snapshot = smup_snapshot( (int) $saved, $package );
    if ( is_wp_error( $snapshot ) ) {
        if ( $previous ) {
            wp_update_post( wp_slash( array(
                'ID' => $saved, 'post_title' => $previous->post_title,
                'post_content' => $previous->post_content,
            ) ) );
        } else { wp_delete_post( $saved, true ); }
        return $snapshot;
    }
    update_post_meta( $saved, 'wp_pattern_sync_status', 'unsynced' );
    update_post_meta( $saved, '_smup_poc_slug', $slug );
    $category = term_exists( 'smup-poc', 'wp_pattern_category' );
    if ( ! $category ) { $category = wp_insert_term( 'Unpacker POC', 'wp_pattern_category', array( 'slug' => 'smup-poc' ) ); }
    if ( ! is_wp_error( $category ) ) {
        wp_set_object_terms( $saved, array( is_array( $category ) ? (int) $category['term_id'] : (int) $category ), 'wp_pattern_category' );
    }
    return array( 'id' => (int) $saved, 'version' => $snapshot['version'], 'sha256' => $snapshot['content_sha256'] );
}

add_action( 'admin_menu', static function (): void {
    add_management_page( 'Unpacker Independence POC', 'Unpacker POC', 'manage_options', 'smup-poc', 'smup_render_page' );
} );

function smup_render_page(): void {
    if ( ! current_user_can( 'manage_options' ) ) { return; }
    $notice = null;
    $remote_packages = null;
    if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_POST['smup_action'] ) ) {
        check_admin_referer( 'smup_import', 'smup_nonce' );
        $kind = sanitize_key( wp_unslash( $_POST['smup_action'] ) );
        if ( 'connect' === $kind ) {
            $notice = smup_gateway_connect(
                isset( $_POST['gateway_url'] ) && is_string( $_POST['gateway_url'] ) ? wp_unslash( $_POST['gateway_url'] ) : '',
                isset( $_POST['gateway_key'] ) && is_string( $_POST['gateway_key'] ) ? wp_unslash( $_POST['gateway_key'] ) : ''
            );
        } elseif ( 'disconnect' === $kind ) {
            delete_option( 'smup_gateway_connection' );
            $notice = array( 'message' => 'Gateway connection disconnected. Existing patterns and archives are untouched.' );
        } elseif ( 'refresh' === $kind ) {
            $remote_packages = smup_gateway_packages();
            $notice = is_wp_error( $remote_packages ) ? $remote_packages : array( 'message' => 'Leased package list refreshed.' );
        } elseif ( 'gateway_import' === $kind ) {
            $notice = smup_gateway_import(
                isset( $_POST['gateway_package'] ) && is_string( $_POST['gateway_package'] ) ? sanitize_text_field( wp_unslash( $_POST['gateway_package'] ) ) : '',
                isset( $_POST['gateway_version'] ) && is_string( $_POST['gateway_version'] ) ? sanitize_text_field( wp_unslash( $_POST['gateway_version'] ) ) : ''
            );
        } else {
            $package = 'sample' === $kind ? smup_read_package() :
                ( 'upload' === $kind ? smup_read_package( $_FILES['package_zip'] ?? array() ) : smup_error( 'Invalid action.' ) );
            $notice = is_wp_error( $package ) ? $package : smup_import( $package );
        }
    }
    $connection = get_option( 'smup_gateway_connection', array() );
    $connected = is_array( $connection ) && (bool) smup_gateway_config();
    if ( $connected && null === $remote_packages ) { $remote_packages = smup_gateway_packages(); }
    $all = get_posts( array( 'post_type' => 'wp_block', 'post_status' => 'publish',
        'numberposts' => 20, 'meta_key' => '_smup_poc_slug', 'orderby' => 'ID', 'order' => 'DESC' ) );
    ?>
    <div class="wrap" style="max-width:980px">
      <h1>Client Unpacker — Independence POC <small style="font-size:0.55em">Gateway integration preview</small></h1>
      <p>Standalone imports remain available without a Gateway. Connected delivery is optional; installed Core patterns never require online access.</p>
      <?php smup_render_update_status(); ?>
      <?php if ( is_wp_error( $notice ) ) : ?>
        <div class="notice notice-error"><p><?php echo esc_html( $notice->get_error_message() ); ?></p></div>
      <?php elseif ( is_array( $notice ) ) : ?>
        <div class="notice notice-success"><p><?php if ( isset( $notice['id'], $notice['version'] ) ) : ?>Unpacked successfully: Pattern #<?php echo (int) $notice['id']; ?> · <?php echo esc_html( $notice['version'] ); ?>.<?php else : ?><?php echo esc_html( $notice['message'] ?? ( isset( $notice['name'] ) ? 'Connected to ' . $notice['name'] : 'Operation completed.' ) ); ?><?php endif; ?></p></div>
      <?php endif; ?>
      <div style="background:white;border:1px solid #ccd0d4;border-radius:8px;padding:20px;margin:20px 0">
        <h2 style="margin-top:0">Gateway connection · Optional</h2>
        <?php if ( ! $connected ) : ?>
          <p>Connect using the HTTPS Gateway URL and the single API key issued to your customer. Keys are verified before storage and encrypted at rest.</p>
          <form method="post" autocomplete="off">
            <?php wp_nonce_field( 'smup_import', 'smup_nonce' ); ?>
            <input type="hidden" name="smup_action" value="connect">
            <p><label for="smup-gateway-url">Gateway HTTPS URL</label><br><input type="url" class="regular-text" id="smup-gateway-url" name="gateway_url" placeholder="https://gateway.example.com" required></p>
            <p><label for="smup-gateway-key">Customer API key</label><br><input type="password" class="regular-text" id="smup-gateway-key" name="gateway_key" autocomplete="new-password" required></p>
            <?php submit_button( 'Connect to Gateway', 'primary', 'submit', false ); ?>
          </form>
        <?php else : ?>
          <p><strong>Connected customer:</strong> <?php echo esc_html( (string) ( $connection['client_name'] ?? 'Unknown' ) ); ?></p>
          <p><strong>Gateway:</strong> <code><?php echo esc_html( (string) ( $connection['url'] ?? '' ) ); ?></code></p>
          <div style="display:flex;gap:12px;flex-wrap:wrap">
            <form method="post"><?php wp_nonce_field( 'smup_import', 'smup_nonce' ); ?><input type="hidden" name="smup_action" value="refresh"><?php submit_button( 'Refresh leased packages', 'secondary', 'submit', false ); ?></form>
            <form method="post" onsubmit="return confirm('Disconnect this site from Gateway? Existing patterns and archives are preserved.');"><?php wp_nonce_field( 'smup_import', 'smup_nonce' ); ?><input type="hidden" name="smup_action" value="disconnect"><?php submit_button( 'Disconnect', 'secondary', 'submit', false ); ?></form>
          </div>
          <?php if ( is_wp_error( $remote_packages ) ) : ?>
            <div class="notice notice-warning inline"><p><?php echo esc_html( $remote_packages->get_error_message() ); ?></p></div>
          <?php elseif ( empty( $remote_packages ) ) : ?>
            <p>No downloadable packages under an active lease.</p>
          <?php else : ?>
            <table class="widefat striped"><thead><tr><th>Private package</th><th>Published version</th><th>Action</th></tr></thead><tbody>
            <?php foreach ( $remote_packages as $item ) : ?>
              <?php foreach ( $item['versions'] as $version ) : ?>
                <tr>
                  <td><?php echo esc_html( $item['name'] ); ?></td>
                  <td><?php echo esc_html( $version['version'] ); ?> (<?php echo esc_html( size_format( $version['size'] ) ); ?>)</td>
                  <td><form method="post">
                    <?php wp_nonce_field( 'smup_import', 'smup_nonce' ); ?>
                    <input type="hidden" name="smup_action" value="gateway_import">
                    <input type="hidden" name="gateway_package" value="<?php echo esc_attr( $item['id'] ); ?>">
                    <input type="hidden" name="gateway_version" value="<?php echo esc_attr( $version['version'] ); ?>">
                    <?php submit_button( 'Download &amp; Unpack', 'secondary', 'submit', false ); ?>
                  </form></td>
                </tr>
              <?php endforeach; ?>
            <?php endforeach; ?>
            </tbody></table>
          <?php endif; ?>
        <?php endif; ?>
        <p class="description">Manual updates only. Gateway revocation prevents future downloads, not access to already imported content.</p>
      </div>
      <div style="background:white;border:1px solid #ccd0d4;border-radius:8px;padding:20px;margin:20px 0">
        <h2 style="margin-top:0">1. Unpack a mock package</h2>
        <p>Installs an editable native Gutenberg pattern. Reimport to create another physical version.</p>
        <form method="post">
          <?php wp_nonce_field( 'smup_import', 'smup_nonce' ); ?>
          <input type="hidden" name="smup_action" value="sample">
          <?php submit_button( 'Import built-in mock package', 'primary', 'submit', false ); ?>
        </form>
        <hr style="margin:24px 0">
        <h2>Or upload the mock package ZIP</h2>
        <form method="post" enctype="multipart/form-data">
          <?php wp_nonce_field( 'smup_import', 'smup_nonce' ); ?>
          <input type="hidden" name="smup_action" value="upload">
          <input type="file" name="package_zip" accept=".zip" required>
          <?php submit_button( 'Unpack ZIP', 'secondary', 'submit', false ); ?>
        </form>
      </div>
      <div style="background:white;border:1px solid #ccd0d4;border-radius:8px;padding:20px;margin:20px 0">
        <h2 style="margin-top:0">2. Verify the saved pattern</h2>
        <?php if ( ! $all ) : ?><p>No imported patterns yet.</p><?php else : ?>
          <table class="widefat striped"><thead><tr><th>Pattern</th><th>Version archives</th><th>WordPress editor</th></tr></thead><tbody>
          <?php foreach ( $all as $post ) :
            $dir = smup_archive_dir( (int) $post->ID ) . '/versions';
            $snapshots = glob( $dir . '/v[0-9][0-9][0-9][0-9][0-9][0-9]/manifest.json' ) ?: array(); ?>
            <tr><td><?php echo esc_html( $post->post_title ); ?> (#<?php echo (int) $post->ID; ?>)</td>
              <td><?php echo count( $snapshots ); ?> archived version(s)</td>
              <td><a href="<?php echo esc_url( admin_url( 'post.php?post=' . (int) $post->ID . '&action=edit' ) ); ?>">Open in Gutenberg</a></td></tr>
          <?php endforeach; ?>
          </tbody></table>
        <?php endif; ?>
      </div>
      <div style="background:white;border:1px solid #ccd0d4;border-radius:8px;padding:20px;margin:20px 0">
        <h2 style="margin-top:0">3. Deactivate Unpacker and verify independence</h2>
        <p>Plugins → Deactivate <strong>SyndaMorph Client Unpacker — Independence POC</strong>. Then visit Appearance → Editor → Patterns (or your WordPress Patterns screen) and find <strong>Independence POC — Core Layout</strong>. Open and edit its Core blocks.</p>
        <p>Physical backups remain at: <code>wp-content/uploads/syndamorph-unpacker-archives/pattern-{ID}/versions/</code>. They are independent of this plugin directory.</p>
        <p><strong>Important:</strong> This POC proves only Core-block patterns with no external assets or runtime dependencies. Its admin screen disappears on deactivation, but the pattern and snapshots should remain.</p>
      </div>
    </div>
    <?php
}

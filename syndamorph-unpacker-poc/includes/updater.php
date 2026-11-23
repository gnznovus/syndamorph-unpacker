<?php
/**
 * SyndaMorph Unpacker POC — native WordPress GitHub Releases updater.
 *
 * Stable, published GitHub releases carrying the exact plugin ZIP are the
 * only eligible update sources. No personal access token is required for a
 * public release. No source-code auto-generated GitHub archives are accepted.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

const SMUP_UPDATE_URI   = 'https://github.com/gnznovus/syndamorph-unpacker';
const SMUP_RELEASE_API  = 'https://api.github.com/repos/gnznovus/syndamorph-unpacker/releases/latest';
const SMUP_RELEASE_ZIP  = 'syndamorph-unpacker-poc.zip';
const SMUP_UPDATE_CACHE = 'smup_poc_github_release_v1';
const SMUP_PLUGIN_FILE  = 'syndamorph-unpacker-poc/syndamorph-unpacker-poc.php';
const SMUP_UPDATE_STATUS = 'smup_poc_release_check_status';
const SMUP_HEARTBEAT = 'smup_poc_release_heartbeat';

/** Fail closed on malformed or non-stable releases and unexpected download URLs. */
function smup_parse_release( $release ): array {
    if ( ! is_array( $release ) || false !== ( $release['draft'] ?? null ) ||
        false !== ( $release['prerelease'] ?? null ) ||
        ! is_string( $release['published_at'] ?? null ) || '' === $release['published_at'] ||
        ! is_array( $release['assets'] ?? null ) ) {
        return array();
    }
    $tag = $release['tag_name'] ?? null;
    if ( ! is_string( $tag ) ||
        ! preg_match( '/^v?((?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*))$/D', $tag, $parts ) ) {
        return array();
    }
    $expected = SMUP_UPDATE_URI . '/releases/download/' . rawurlencode( $tag ) . '/' . SMUP_RELEASE_ZIP;
    foreach ( $release['assets'] as $asset ) {
        if ( ! is_array( $asset ) || ( $asset['name'] ?? null ) !== SMUP_RELEASE_ZIP ||
            ( $asset['state'] ?? null ) !== 'uploaded' ||
            ! is_int( $asset['size'] ?? null ) || $asset['size'] <= 0 ||
            ( $asset['browser_download_url'] ?? null ) !== $expected ) {
            continue;
        }
        return array(
            'version' => $parts[1],
            'package' => $expected,
            'url'     => SMUP_UPDATE_URI . '/releases/tag/' . rawurlencode( $tag ),
            'notes'   => is_string( $release['body'] ?? null ) ? $release['body'] : '',
        );
    }
    return array();
}

function smup_release_metadata( bool $force = false ): array {
    if ( $force ) { delete_transient( SMUP_UPDATE_CACHE ); }
    $cached = get_transient( SMUP_UPDATE_CACHE );
    if ( is_array( $cached ) ) { return $cached; }

    $response = wp_remote_get( SMUP_RELEASE_API, array(
        'timeout'             => 6,
        'redirection'         => 2,
        'limit_response_size' => 1024 * 1024,
        'headers'             => array(
            'Accept'               => 'application/vnd.github+json',
            'User-Agent'           => 'SyndaMorph-Unpacker-Poc-Updater',
            'X-GitHub-Api-Version'  => '2022-11-28',
        ),
    ) );
    if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
        set_transient( SMUP_UPDATE_CACHE, array(), MINUTE_IN_SECONDS );
        update_option( SMUP_UPDATE_STATUS, array( 'checked_at' => time(), 'ok' => false, 'version' => '', 'message' => 'GitHub release lookup failed.' ), false );
        return array();
    }
    $release = smup_parse_release( json_decode( wp_remote_retrieve_body( $response ), true ) );
    // Release publishing and ZIP asset uploads are not necessarily atomic.
    set_transient( SMUP_UPDATE_CACHE, $release, $release ? 5 * MINUTE_IN_SECONDS : MINUTE_IN_SECONDS );
    update_option( SMUP_UPDATE_STATUS, array(
        'checked_at' => time(), 'ok' => (bool) $release,
        'version' => $release['version'] ?? '',
        'message' => $release ? 'Published release available for comparison.' : 'No eligible published release with the required ZIP was found.',
    ), false );
    return $release;
}

/** Use the core update_plugins_{hostname} protocol: native update + auto-update UI. */
add_filter( 'update_plugins_github.com', static function ( $update, $plugin_data, $plugin_file ) {
    if ( false !== $update || SMUP_PLUGIN_FILE !== $plugin_file ||
        SMUP_UPDATE_URI !== ( $plugin_data['UpdateURI'] ?? '' ) ) {
        return $update;
    }
    $release = smup_release_metadata();
    // Always supply a valid no_update entry even before the first release or
    // during a network failure. Core then recognizes that this plugin owns an
    // update channel and exposes the native automatic-update control.
    if ( ! $release ) {
        return array(
            'id'           => SMUP_UPDATE_URI,
            'slug'         => 'syndamorph-unpacker-poc',
            'version'      => $plugin_data['Version'],
            'url'          => SMUP_UPDATE_URI,
            'package'      => '',
            'requires'     => '6.5',
            'requires_php' => '8.1',
        );
    }

    return array(
        'id'           => SMUP_UPDATE_URI,
        'slug'         => 'syndamorph-unpacker-poc',
        'version'      => $release['version'],
        'url'          => $release['url'],
        'package'      => version_compare( $release['version'], $plugin_data['Version'], '>' ) ? $release['package'] : '',
        'requires'     => '6.5',
        'requires_php' => '8.1',
    );
}, 10, 3 );

/** Populate the native WordPress plugin information dialog. */
add_filter( 'plugins_api', static function ( $result, $action, $args ) {
    if ( 'plugin_information' !== $action || ! is_object( $args ) ||
        'syndamorph-unpacker-poc' !== ( $args->slug ?? '' ) ) {
        return $result;
    }
    $release = smup_release_metadata();
    if ( ! $release ) { return $result; }
    return (object) array(
        'name'          => 'SyndaMorph Client Unpacker — Independence POC',
        'slug'          => 'syndamorph-unpacker-poc',
        'version'       => $release['version'],
        'homepage'      => SMUP_UPDATE_URI,
        'download_link' => $release['package'],
        'requires'      => '6.5',
        'requires_php'  => '8.1',
        'sections'      => array(
            'description' => '<p>Standalone Core-block package unpacker and local immutable archive POC.</p>',
            'changelog'   => '<pre style="white-space:pre-wrap">' . esc_html( $release['notes'] ?: 'See the release notes on GitHub.' ) . '</pre>',
        ),
    );
}, 10, 3 );

/**
 * A traffic-driven WP-Cron heartbeat keeps metadata fresh for active sites.
 * Native WordPress automatic installation remains controlled by WordPress.
 */
add_filter( 'cron_schedules', static function ( array $schedules ): array {
    $schedules['smup_15_minutes'] = array( 'interval' => 15 * MINUTE_IN_SECONDS, 'display' => 'Every 15 minutes (SyndaMorph Unpacker)' );
    return $schedules;
} );

add_action( 'init', static function (): void {
    if ( ! wp_next_scheduled( SMUP_HEARTBEAT ) ) {
        wp_schedule_event( time() + 60, 'smup_15_minutes', SMUP_HEARTBEAT );
    }
} );

add_action( SMUP_HEARTBEAT, static function (): void {
    $release = smup_release_metadata( true );
    if ( $release ) {
        delete_site_transient( 'update_plugins' );
        if ( ! function_exists( 'wp_update_plugins' ) ) { require_once ABSPATH . 'wp-includes/update.php'; }
        wp_update_plugins();
    }
} );

/** Explicit manual check ignores the custom metadata cache and core's update transient. */
add_action( 'admin_post_smup_force_update_check', static function (): void {
    if ( ! current_user_can( 'update_plugins' ) ) { wp_die( 'Forbidden', '', array( 'response' => 403 ) ); }
    check_admin_referer( 'smup_force_update_check' );
    $release = smup_release_metadata( true );
    delete_site_transient( 'update_plugins' );
    if ( ! function_exists( 'wp_update_plugins' ) ) { require_once ABSPATH . 'wp-includes/update.php'; }
    wp_update_plugins();
    $code = $release ? 'checked' : 'unavailable';
    wp_safe_redirect( add_query_arg( array( 'page' => 'smup-poc', 'smup_update_check' => $code ), admin_url( 'tools.php' ) ) );
    exit;
} );

function smup_render_update_status(): void {
    if ( ! current_user_can( 'manage_options' ) ) { return; }
    $status = get_option( SMUP_UPDATE_STATUS, array() );
    if ( ! is_array( $status ) ) { $status = array(); }
    $version = get_plugin_data( plugin_dir_path( dirname( __FILE__ ) ) . 'syndamorph-unpacker-poc.php', false, false )['Version'] ?? '';
    $remote = (string) ( $status['version'] ?? '' );
    $last = ! empty( $status['checked_at'] ) ? wp_date( 'Y-m-d H:i:s T', (int) $status['checked_at'] ) : 'Never';
    $newer = $remote && version_compare( $remote, $version, '>' );
    ?>
    <div style="background:#fff;border:1px solid #ccd0d4;border-radius:8px;padding:20px;margin:20px 0">
      <h2 style="margin-top:0">Plugin release heartbeat</h2>
      <p><strong>Installed:</strong> <?php echo esc_html( $version ); ?> · <strong>Latest published:</strong> <?php echo esc_html( $remote ?: 'Not confirmed' ); ?></p>
      <p><strong>Last GitHub check:</strong> <?php echo esc_html( $last ); ?> · <strong>Result:</strong> <?php echo esc_html( (string) ( $status['message'] ?? 'No checks recorded.' ) ); ?></p>
      <?php if ( $newer ) : ?>
        <p><strong>Newer release detected.</strong> <a href="<?php echo esc_url( admin_url( 'plugins.php' ) ); ?>">Open Plugins to install the update</a>.</p>
      <?php elseif ( $remote && ! $newer ) : ?>
        <p>No newer published release detected.</p>
      <?php endif; ?>
      <?php if ( isset( $_GET['smup_update_check'] ) ) : ?>
        <p role="status"><?php echo 'checked' === sanitize_key( wp_unslash( $_GET['smup_update_check'] ) ) ? 'Manual release check completed.' : 'Manual release check completed; no eligible published release could be confirmed.'; ?></p>
      <?php endif; ?>
      <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
        <input type="hidden" name="action" value="smup_force_update_check">
        <?php wp_nonce_field( 'smup_force_update_check' ); ?>
        <?php submit_button( 'Check GitHub for updates now', 'secondary', 'submit', false ); ?>
      </form>
      <p class="description">Checks automatically every 15 minutes when WP-Cron is running; manual check bypasses both update caches. Draft releases are not eligible. Updates are never installed by this button.</p>
    </div>
    <?php
}

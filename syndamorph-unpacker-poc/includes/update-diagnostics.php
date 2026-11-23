<?php
/** Temporary update diagnostics, enabled through wp-config.php. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function smup_diagnostics_can_check(): bool {
    $admin_cap = is_network_admin() ? 'manage_network_plugins' : 'manage_options';
    return current_user_can( $admin_cap ) && current_user_can( 'update_plugins' );
}

function smup_diagnostics_basename(): string {
    return plugin_basename( dirname( __DIR__ ) . '/syndamorph-unpacker-poc.php' );
}

function smup_diagnostics_action_link( array $actions ): array {
    if ( smup_diagnostics_can_check() ) {
        $url = add_query_arg( 'smup_check_updates', '1', self_admin_url( 'plugins.php' ) );
        $actions['smup_check_updates'] = '<a href="' . esc_url( wp_nonce_url( $url, 'smup_check_updates' ) ) .
            '">Check for updates</a>';
    }
    return $actions;
}
add_filter( 'plugin_action_links_' . smup_diagnostics_basename(), 'smup_diagnostics_action_link' );
add_filter( 'network_admin_plugin_action_links_' . smup_diagnostics_basename(), 'smup_diagnostics_action_link' );

function smup_diagnostics_entry( $entry ): string {
    if ( ! is_object( $entry ) && ! is_array( $entry ) ) { return 'Absent'; }
    $entry = (array) $entry;
    $version = $entry['new_version'] ?? $entry['version'] ?? '';
    return ( is_string( $version ) && preg_match( '/^[0-9][a-z0-9.+-]*$/iD', $version ) ? $version : 'Version unavailable' ) .
        ( empty( $entry['package'] ) ? ' (no package)' : ' (package present)' );
}

function smup_run_update_diagnostics(): void {
    if ( ! isset( $_GET['smup_check_updates'] ) ) { return; }
    if ( ! smup_diagnostics_can_check() ) {
        wp_die( 'You are not allowed to check for Unpacker updates.', '', array( 'response' => 403 ) );
    }
    check_admin_referer( 'smup_check_updates' );

    $basename = smup_diagnostics_basename();
    $plugin = get_plugins()[ $basename ] ?? array();
    $before = get_site_transient( 'update_plugins' );
    $github = array();
    smup_release_metadata( true, $github );

    $registered = false !== has_filter( SMUP_UPDATE_HOOK, 'smup_filter_plugin_update' );
    $ran = false;
    $observe_update = static function ( $update, $data, $file ) use ( $basename, &$ran ) {
        if ( $basename === $file ) { $ran = true; }
        return $update;
    };
    $wp_org = 'No request observed';
    $observe_http = static function ( $response, $context, $transport, $args, $url ) use ( &$wp_org ) {
        if ( 'response' === $context && 'https://api.wordpress.org/plugins/update-check/1.1/' === $url ) {
            $status = (int) wp_remote_retrieve_response_code( $response );
            $wp_org = $status ? 'HTTP ' . $status : 'Transport error';
        }
    };
    add_filter( SMUP_UPDATE_HOOK, $observe_update, 11, 3 );
    add_action( 'http_api_debug', $observe_http, 10, 5 );
    if ( is_object( $before ) ) {
        $expired = clone $before;
        $expired->last_checked = 0;
        set_site_transient( 'update_plugins', $expired );
    }
    wp_update_plugins();
    remove_filter( SMUP_UPDATE_HOOK, $observe_update, 11 );
    remove_action( 'http_api_debug', $observe_http, 10 );

    $after = get_site_transient( 'update_plugins' );
    $response = $after->response[ $basename ] ?? null;
    $no_update = $after->no_update[ $basename ] ?? null;
    $eligible = isset( $response ) || isset( $no_update );
    $allowed = wp_is_auto_update_enabled_for_type( 'plugin' );
    $selected = in_array( $basename, (array) get_site_option( 'auto_update_plugins', array() ), true );
    $installed = $plugin['Version'] ?? '';
    $comparison = ! empty( $github['version'] ) && '' !== $installed
        ? ' (GitHub version ' . ( version_compare( $github['version'], $installed, '>' ) ? 'is newer)' : 'is not newer)' )
        : '';
    $rows = array(
        'GitHub release API' => ( $github['status'] ? 'HTTP ' . $github['status'] : 'Transport error' ) .
            ( $github['authenticated'] ? ' (credential configured)' : ' (anonymous)' ),
        'GitHub release version' => ( $github['version'] ?? 'Unavailable' ) . $comparison,
        'Release ZIP validation' => $github['error'] ?? SMUP_RELEASE_ZIP . ' metadata valid',
        'Installed plugin basename' => $basename . ( SMUP_PLUGIN_FILE === $basename ? '' : ' (expected ' . SMUP_PLUGIN_FILE . ')' ),
        'Update URI header' => empty( $plugin['UpdateURI'] ) ? 'Missing' :
            ( SMUP_UPDATE_URI === $plugin['UpdateURI'] ? SMUP_UPDATE_URI : 'Unexpected URI (expected ' . SMUP_UPDATE_URI . ')' ),
        'Installed version' => $installed ?: 'Unavailable',
        SMUP_UPDATE_HOOK => ( $registered ? 'Unpacker callback registered' : 'Unpacker callback missing' ) .
            ( $ran ? ' (hook ran for this plugin)' : ' (hook did not run for this plugin)' ),
        'WordPress.org update API' => $wp_org,
        'update_plugins last_checked (UTC)' => empty( $after->last_checked ) ? 'Absent' : gmdate( 'Y-m-d H:i:s', $after->last_checked ),
        'update_plugins checked version' => $after->checked[ $basename ] ?? 'Absent',
        'update_plugins response (before → after)' => smup_diagnostics_entry( $before->response[ $basename ] ?? null ) . ' → ' .
            smup_diagnostics_entry( $response ),
        'update_plugins no_update (before → after)' => smup_diagnostics_entry( $before->no_update[ $basename ] ?? null ) . ' → ' .
            smup_diagnostics_entry( $no_update ),
        'Recognized as update-capable' => $eligible ? 'Yes' : 'No',
        'WordPress allows plugin auto-updates' => $allowed ? 'Yes' : 'No',
        'Plugin selected for auto-updates' => $selected ? 'Yes' : 'No',
    );
    if ( ! $ran ) {
        $rows['WordPress check result'] = 'The hostname hook did not run. Check the WordPress.org update API result and the Update URI.';
    }

    add_action( is_network_admin() ? 'network_admin_notices' : 'admin_notices', static function () use ( $rows ) {
        echo '<div class="notice notice-info"><p><strong>Unpacker update diagnostics</strong></p><dl>';
        foreach ( $rows as $label => $value ) {
            printf( '<dt><strong>%s</strong></dt><dd>%s</dd>', esc_html( $label ), esc_html( $value ) );
        }
        echo '</dl></div>';
    } );
}
add_action( 'load-plugins.php', 'smup_run_update_diagnostics', 5 );

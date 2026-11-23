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

function smup_release_metadata(): array {
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
        return array();
    }
    $release = smup_parse_release( json_decode( wp_remote_retrieve_body( $response ), true ) );
    // Release publishing and ZIP asset uploads are not necessarily atomic.
    set_transient( SMUP_UPDATE_CACHE, $release, $release ? 6 * HOUR_IN_SECONDS : MINUTE_IN_SECONDS );
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

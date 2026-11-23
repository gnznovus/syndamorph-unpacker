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
const SMUP_UPDATE_CACHE = 'smup_poc_github_release_v2';
const SMUP_UPDATE_HOOK  = 'update_plugins_github.com';
const SMUP_ASSET_API    = 'https://api.github.com/repos/gnznovus/syndamorph-unpacker/releases/assets/';
const SMUP_PLUGIN_FILE  = 'syndamorph-unpacker-poc/syndamorph-unpacker-poc.php';

/** Fail closed on malformed or non-stable releases and unexpected download URLs. */
function smup_parse_release( $release, ?string &$error = null, ?string &$version = null ): array {
    $error = 'GitHub did not return a published stable release.';
    $version = null;
    if ( ! is_array( $release ) || false !== ( $release['draft'] ?? null ) ||
        false !== ( $release['prerelease'] ?? null ) ||
        ! is_string( $release['published_at'] ?? null ) || '' === $release['published_at'] ) {
        return array();
    }
    $error = 'The release tag is not a stable x.y.z version.';
    $tag = $release['tag_name'] ?? null;
    if ( ! is_string( $tag ) ||
        ! preg_match( '/^v?((?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*))$/D', $tag, $parts ) ) {
        return array();
    }
    $version = $parts[1];
    $error = 'The release is missing a valid uploaded ' . SMUP_RELEASE_ZIP . ' asset.';
    if ( ! is_array( $release['assets'] ?? null ) ) { return array(); }
    $expected = SMUP_UPDATE_URI . '/releases/download/' . rawurlencode( $tag ) . '/' . SMUP_RELEASE_ZIP;
    foreach ( $release['assets'] as $asset ) {
        if ( ! is_array( $asset ) || ( $asset['name'] ?? null ) !== SMUP_RELEASE_ZIP ||
            ( $asset['state'] ?? null ) !== 'uploaded' ||
            ! is_int( $asset['size'] ?? null ) || $asset['size'] <= 0 ||
            ! is_int( $asset['id'] ?? null ) || $asset['id'] <= 0 ||
            ( $asset['browser_download_url'] ?? null ) !== $expected ) {
            continue;
        }
        $error = null;
        return array(
            'version' => $version,
            'package' => $expected,
            'asset'   => SMUP_ASSET_API . $asset['id'],
            'url'     => SMUP_UPDATE_URI . '/releases/tag/' . rawurlencode( $tag ),
            'notes'   => is_string( $release['body'] ?? null ) ? $release['body'] : '',
        );
    }
    return array();
}

function smup_github_token(): string {
    return defined( 'SMUP_GITHUB_TOKEN' ) && is_string( SMUP_GITHUB_TOKEN ) ? trim( SMUP_GITHUB_TOKEN ) : '';
}

function smup_github_headers( string $accept ): array {
    $headers = array(
        'Accept'               => $accept,
        'User-Agent'           => 'SyndaMorph-Unpacker-Poc-Updater',
        'X-GitHub-Api-Version'  => '2022-11-28',
    );
    $token = smup_github_token();
    if ( '' !== $token ) { $headers['Authorization'] = 'Bearer ' . $token; }
    return $headers;
}

function smup_release_metadata( bool $fresh = false, ?array &$diagnostics = null ): array {
    $authenticated = '' !== smup_github_token();
    $cache_key = SMUP_UPDATE_CACHE . ( $authenticated ? '_authenticated' : '' );
    if ( ! $fresh ) {
        $cached = get_transient( $cache_key );
        if ( is_array( $cached ) ) { return $cached; }
    }

    $response = wp_safe_remote_get( SMUP_RELEASE_API, array(
        'timeout'             => 6,
        'redirection'         => 0,
        'limit_response_size' => 1024 * 1024,
        'headers'             => smup_github_headers( 'application/vnd.github+json' ),
    ) );
    $status = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
    $release = array();
    $version = null;
    switch ( $status ) {
        case 200:
            $payload = json_decode( wp_remote_retrieve_body( $response ), true );
            $error = 'GitHub returned invalid JSON.';
            if ( JSON_ERROR_NONE === json_last_error() ) {
                $release = smup_parse_release( $payload, $error, $version );
            }
            break;
        case 0:
            $error = 'WordPress could not reach GitHub. Check outbound HTTPS and Playground networking.';
            break;
        case 401:
            $error = 'GitHub rejected the configured credential. Check whether it is valid and unexpired.';
            break;
        case 403:
        case 429:
            $error = 'GitHub denied or rate-limited the request. Check access and retry later.';
            break;
        case 404:
            $error = $authenticated
                ? 'Check repository access, Contents read permission, and whether a stable release is published.'
                : 'A private repository requires SMUP_GITHUB_TOKEN. A public repository must have a published stable release.';
            break;
        default:
            $error = 'GitHub returned an unexpected HTTP status.';
    }
    $diagnostics = compact( 'authenticated', 'status', 'version', 'error' );
    // Release publishing and ZIP asset uploads are not necessarily atomic.
    set_transient( $cache_key, $release, $release ? 6 * HOUR_IN_SECONDS : MINUTE_IN_SECONDS );
    return $release;
}

/** Use the core update_plugins_{hostname} protocol: native update + auto-update UI. */
function smup_filter_plugin_update( $update, $plugin_data, $plugin_file ) {
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
        'package'      => version_compare( $release['version'], $plugin_data['Version'], '>' ) ? smup_release_package( $release ) : '',
        'requires'     => '6.5',
        'requires_php' => '8.1',
    );
}
add_filter( SMUP_UPDATE_HOOK, 'smup_filter_plugin_update', 10, 3 );

function smup_release_package( array $release ): string {
    return '' !== smup_github_token() ? $release['asset'] : $release['package'];
}

/** Give the native upgrader a local ZIP from the private release asset API. */
function smup_download_release_asset( $reply, $package, $upgrader, $hook_extra ) {
    if ( false !== $reply || SMUP_PLUGIN_FILE !== ( $hook_extra['plugin'] ?? '' ) || ! is_string( $package ) ||
        ! preg_match( '/^' . preg_quote( SMUP_ASSET_API, '/' ) . '[1-9][0-9]*$/D', $package ) ) {
        return $reply;
    }
    if ( '' === smup_github_token() ) {
        return new WP_Error( 'smup_release_token_required', 'Configure SMUP_GITHUB_TOKEN to download this Unpacker release.' );
    }

    $filename = wp_tempnam( SMUP_RELEASE_ZIP );
    if ( ! $filename ) {
        return new WP_Error( 'smup_release_temp_file', 'WordPress could not create a temporary file for the Unpacker ZIP.' );
    }
    $args = array(
        'timeout'     => 30,
        'redirection' => 0,
        'stream'      => true,
        'filename'    => $filename,
    );
    $response = wp_safe_remote_get( $package, $args + array(
        'headers' => smup_github_headers( 'application/octet-stream' ),
    ) );
    $status = (int) wp_remote_retrieve_response_code( $response );
    if ( 302 === $status ) {
        // The signed URL is short-lived. Fetch it separately without the GitHub credential.
        $location = wp_remote_retrieve_header( $response, 'location' );
        $parts = is_string( $location ) ? wp_parse_url( $location ) : false;
        if ( 'https' === ( $parts['scheme'] ?? '' ) &&
            in_array( $parts['host'] ?? '', array( 'release-assets.githubusercontent.com', 'objects.githubusercontent.com' ), true ) &&
            ! isset( $parts['user'] ) && ! isset( $parts['pass'] ) && ! isset( $parts['port'] ) ) {
            $response = wp_safe_remote_get( $location, $args );
            $status = (int) wp_remote_retrieve_response_code( $response );
        }
    }
    if ( 200 === $status && "PK\x03\x04" === file_get_contents( $filename, false, null, 0, 4 ) ) {
        return $filename;
    }
    unlink( $filename );
    $error = $status ? sprintf( 'WordPress could not download a valid Unpacker ZIP (HTTP %d).', $status )
        : 'WordPress could not reach the Unpacker ZIP download host.';
    return new WP_Error( 'smup_release_download', $error );
}
add_filter( 'upgrader_pre_download', 'smup_download_release_asset', 10, 4 );

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
        'download_link' => smup_release_package( $release ),
        'requires'      => '6.5',
        'requires_php'  => '8.1',
        'sections'      => array(
            'description' => '<p>Standalone Core-block package unpacker and local immutable archive POC.</p>',
            'changelog'   => '<pre style="white-space:pre-wrap">' . esc_html( $release['notes'] ?: 'See the release notes on GitHub.' ) . '</pre>',
        ),
    );
}, 10, 3 );

if ( defined( 'SMUP_UPDATE_DIAGNOSTICS' ) && SMUP_UPDATE_DIAGNOSTICS && is_admin() ) {
    require_once __DIR__ . '/update-diagnostics.php';
}

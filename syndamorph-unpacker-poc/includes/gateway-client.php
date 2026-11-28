<?php
/**
 * Optional SyndaMorph Gateway client. No runtime dependency after import.
 * Only an administrator can configure/download. All HTTP requests are server-side.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function smup_gateway_secret_key(): string {
    return hash( 'sha256', wp_salt( 'auth' ) . wp_salt( 'secure_auth' ), true );
}

function smup_gateway_encrypt( string $value ) {
    if ( ! function_exists( 'openssl_encrypt' ) ) { return smup_error( 'OpenSSL is required to store the Gateway credential.', 503 ); }
    $iv = random_bytes( 12 );
    $tag = '';
    $encrypted = openssl_encrypt( $value, 'aes-256-gcm', smup_gateway_secret_key(), OPENSSL_RAW_DATA, $iv, $tag );
    if ( false === $encrypted ) { return smup_error( 'Could not protect the Gateway credential.', 500 ); }
    return base64_encode( $iv . $tag . $encrypted );
}

function smup_gateway_decrypt( string $encoded ): string {
    if ( ! function_exists( 'openssl_decrypt' ) ) { return ''; }
    $bytes = base64_decode( $encoded, true );
    if ( false === $bytes || strlen( $bytes ) < 29 ) { return ''; }
    $plaintext = openssl_decrypt(
        substr( $bytes, 28 ), 'aes-256-gcm', smup_gateway_secret_key(),
        OPENSSL_RAW_DATA, substr( $bytes, 0, 12 ), substr( $bytes, 12, 16 )
    );
    return is_string( $plaintext ) ? $plaintext : '';
}

function smup_gateway_config(): array {
    $settings = get_option( 'smup_gateway_connection', array() );
    if ( ! is_array( $settings ) ) { return array(); }
    $url = $settings['url'] ?? '';
    $key = $settings['credential'] ?? '';
    if ( ! is_string( $url ) || ! is_string( $key ) ) { return array(); }
    $decrypted = smup_gateway_decrypt( $key );
    return $decrypted ? array( 'url' => $url, 'key' => $decrypted ) : array();
}

/** Permit one exact loopback origin only when explicitly configured on a local site. */
function smup_gateway_is_local_origin( string $url ): bool {
    if ( ! defined( 'SMUP_LOCAL_GATEWAY_ORIGIN' ) || ! function_exists( 'wp_get_environment_type' ) ||
        'local' !== wp_get_environment_type() || ! is_string( SMUP_LOCAL_GATEWAY_ORIGIN ) ) {
        return false;
    }
    return $url === SMUP_LOCAL_GATEWAY_ORIGIN &&
        1 === preg_match( '~\Ahttp://127\.0\.0\.1:([1-9][0-9]{0,4})\z~', $url, $match ) &&
        (int) $match[1] >= 1024 && (int) $match[1] <= 65535;
}

/** Public HTTPS or the configured local development origin. */
function smup_gateway_valid_url( string $raw ): bool {
    if ( smup_gateway_is_local_origin( $raw ) ) { return true; }
    if ( strlen( $raw ) > 2048 || ! filter_var( $raw, FILTER_VALIDATE_URL ) ) { return false; }
    $parts = wp_parse_url( $raw );
    if ( ! is_array( $parts ) || 'https' !== ( $parts['scheme'] ?? '' ) ||
        empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ||
        isset( $parts['query'] ) || isset( $parts['fragment'] ) ||
        isset( $parts['path'] ) && ! in_array( $parts['path'], array( '', '/' ), true ) ) {
        return false;
    }
    // The HTTP transport re-validates resolved addresses on every request.
    return (int) ( $parts['port'] ?? 443 ) === 443 && (bool) wp_http_validate_url( rtrim( $raw, '/' ) . '/v1/client/me' );
}

function smup_gateway_request( array $config, string $method, string $path, array $body = array(), bool $authenticated = true, ?string $destination = null ) {
    if ( empty( $config['url'] ) || ! smup_gateway_valid_url( $config['url'] ) ||
        ! preg_match( '~^/v1/(?:client/me|packages(?:/pkg_[a-f0-9]{24}/downloads)?|downloads/[A-Za-z0-9_-]{43})$~D', $path ) ) {
        return smup_error( 'Invalid Gateway endpoint.', 400 );
    }
    $headers = array( 'Accept' => 'application/json' );
    if ( $authenticated ) {
        if ( empty( $config['key'] ) || ! preg_match( '/^smup_[A-Za-z0-9_-]{30,100}$/D', $config['key'] ) ) {
            return smup_error( 'Invalid Gateway credential.', 401 );
        }
        $headers['Authorization'] = 'Bearer ' . $config['key'];
    }
    $download = 'GET' === $method && str_starts_with( $path, '/v1/downloads/' );
    $args = array(
        'method' => $method,
        'timeout' => $download ? 30 : 15,
        'redirection' => 0,
        'reject_unsafe_urls' => ! smup_gateway_is_local_origin( $config['url'] ),
        'sslverify' => true,
        'headers' => $headers,
        'limit_response_size' => $download ? SMUP_PORTABLE_MAX_ZIP_BYTES + 1 : 65536,
    );
    if ( $download ) {
        if ( null === $destination ) { return smup_error( 'The Gateway download requires a temporary file.', 500 ); }
        $args['stream'] = true;
        $args['filename'] = $destination;
        $args['headers']['Accept'] = 'application/zip';
        $args['headers']['Connection'] = 'close';
    }
    if ( 'POST' === $method ) {
        $args['headers']['Content-Type'] = 'application/json';
        $args['body'] = wp_json_encode( $body );
    }
    $response = wp_remote_request( rtrim( $config['url'], '/' ) . $path, $args );
    if ( is_wp_error( $response ) ) { return smup_error( 'Gateway unreachable or TLS validation failed.', 502 ); }
    $status = wp_remote_retrieve_response_code( $response );
    if ( $status < 200 || $status >= 300 ) {
        $messages = array(
            401 => 'Gateway authentication rejected. Check the client API key.',
            403 => 'Package access denied: lease inactive, expired, or revoked.',
            404 => 'Package or version is not available to this client.',
        );
        return smup_error( $messages[ $status ] ?? 'Gateway request failed.', (int) $status >= 400 && $status < 600 ? (int) $status : 502 );
    }
    if ( $download ) {
        return true;
    }
    $json = json_decode( wp_remote_retrieve_body( $response ), true );
    return is_array( $json ) ? $json : smup_error( 'Unexpected Gateway response.', 502 );
}

function smup_gateway_connect( string $url, string $api_key ) {
    $url = rtrim( trim( $url ), '/' );
    $api_key = trim( $api_key );
    if ( ! smup_gateway_valid_url( $url ) || ! preg_match( '/^smup_[A-Za-z0-9_-]{30,100}$/D', $api_key ) ) {
        return smup_error( 'Provide a valid Gateway origin and client API key.' );
    }
    $connection = array( 'url' => $url, 'key' => $api_key );
    $identity = smup_gateway_request( $connection, 'GET', '/v1/client/me' );
    if ( is_wp_error( $identity ) ) { return $identity; }
    if ( ! is_string( $identity['id'] ?? null ) || ! preg_match( '/^cus_[a-f0-9]{24}$/D', $identity['id'] ) ||
        ! is_string( $identity['name'] ?? null ) ) {
        return smup_error( 'Gateway returned invalid client identity.', 502 );
    }
    $protected = smup_gateway_encrypt( $api_key );
    if ( is_wp_error( $protected ) ) { return $protected; }
    update_option( 'smup_gateway_connection', array(
        'url' => $url, 'credential' => $protected, 'client_id' => $identity['id'], 'client_name' => sanitize_text_field( $identity['name'] ),
    ), false );
    return array( 'name' => $identity['name'], 'id' => $identity['id'] );
}

function smup_gateway_packages() {
    $config = smup_gateway_config();
    if ( ! $config ) { return smup_error( 'Connect to the Gateway first.', 401 ); }
    $result = smup_gateway_request( $config, 'GET', '/v1/packages' );
    if ( is_wp_error( $result ) ) { return $result; }
    if ( ! is_array( $result['packages'] ?? null ) ) { return smup_error( 'Gateway returned invalid package data.', 502 ); }
    $packages = array();
    foreach ( $result['packages'] as $item ) {
        if ( ! is_array( $item ) || ! preg_match( '/^pkg_[a-f0-9]{24}$/D', (string) ( $item['id'] ?? '' ) ) ||
            ! is_string( $item['name'] ?? null ) || ! is_array( $item['versions'] ?? null ) ) {
            return smup_error( 'Gateway returned invalid package data.', 502 );
        }
        $versions = array();
        foreach ( $item['versions'] as $version ) {
            if ( ! is_array( $version ) || ! preg_match( '/^\d+\.\d+\.\d+$/D', (string) ( $version['version'] ?? '' ) ) ||
                ! preg_match( '/^[a-f0-9]{64}$/D', (string) ( $version['sha256'] ?? '' ) ) ||
                ! is_int( $version['size'] ?? null ) ) {
                return smup_error( 'Gateway returned invalid version data.', 502 );
            }
            $versions[] = $version;
        }
        $packages[] = array( 'id' => $item['id'], 'name' => $item['name'], 'versions' => $versions );
    }
    return $packages;
}

/** Download only listed, leased versions; verify bytes before the existing importer sees them. */
function smup_gateway_import( string $package_id, string $version ) {
    if ( ! preg_match( '/^pkg_[a-f0-9]{24}$/D', $package_id ) || ! preg_match( '/^\d+\.\d+\.\d+$/D', $version ) ) {
        return smup_error( 'Invalid package selection.' );
    }
    $packages = smup_gateway_packages();
    if ( is_wp_error( $packages ) ) { return $packages; }
    $selected = null;
    foreach ( $packages as $package ) {
        if ( $package['id'] !== $package_id ) { continue; }
        foreach ( $package['versions'] as $entry ) {
            if ( $entry['version'] === $version ) { $selected = $entry; break; }
        }
    }
    if ( null === $selected ) { return smup_error( 'Package version is not leased to this client.', 403 ); }
    if ( $selected['size'] < 1 || $selected['size'] > SMUP_PORTABLE_MAX_ZIP_BYTES ) {
        return smup_error( 'Package exceeds the Gateway ZIP size limit.' );
    }
    $config = smup_gateway_config();
    $grant = smup_gateway_request( $config, 'POST', '/v1/packages/' . $package_id . '/downloads', array( 'version' => $version ) );
    if ( is_wp_error( $grant ) ) { return $grant; }
    if ( ! is_string( $grant['grant'] ?? null ) || ! preg_match( '/^[A-Za-z0-9_-]{43}$/D', $grant['grant'] ) ||
        60 !== ( $grant['expiresIn'] ?? null ) ||
        ! hash_equals( $selected['sha256'], (string) ( $grant['sha256'] ?? '' ) ) || $selected['size'] !== ( $grant['size'] ?? null ) ) {
        return smup_error( 'Gateway grant does not match published artifact.', 502 );
    }
    require_once ABSPATH . 'wp-admin/includes/file.php';
    $temp = wp_tempnam( 'syndamorph-gateway.zip' );
    if ( ! $temp ) { return smup_error( 'Cannot create temporary package file.', 500 ); }
    try {
        $downloaded = smup_gateway_request( $config, 'GET', '/v1/downloads/' . $grant['grant'], array(), false, $temp );
        if ( is_wp_error( $downloaded ) ) { return $downloaded; }
        clearstatcache( true, $temp );
        $checksum = $selected['size'] === filesize( $temp ) ? hash_file( 'sha256', $temp ) : false;
        if ( ! is_string( $checksum ) || ! hash_equals( $selected['sha256'], $checksum ) ) {
            return smup_error( 'Downloaded package checksum or length mismatch.', 502 );
        }
        $package = smup_read_zip_file( $temp );
        return is_wp_error( $package ) ? $package : smup_import( $package );
    } finally {
        @unlink( $temp );
    }
}

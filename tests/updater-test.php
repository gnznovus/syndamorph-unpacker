<?php
declare(strict_types=1);
define( 'ABSPATH', '/test/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
$GLOBALS['filters'] = array();
$GLOBALS['transients'] = array();
$GLOBALS['response'] = null;
$GLOBALS['http_requests'] = array();
function add_filter( $name, $callback, $priority = 10, $accepted_args = 1 ): void {
    $GLOBALS['filters'][$name] = $callback;
}
function get_transient( $key ) { return $GLOBALS['transients'][$key] ?? false; }
function set_transient( $key, $data, $ttl ) {
    $GLOBALS['transients'][$key] = $data;
    $GLOBALS['ttl'] = $ttl;
}
function wp_safe_remote_get( $url, $args ) {
    $GLOBALS['http_requests'][] = array( $url, $args );
    if ( isset( $args['filename'] ) ) {
        $result = $GLOBALS['downloads'][$url];
        file_put_contents( $args['filename'], $result['body'] ?? '' );
        return $result;
    }
    return $GLOBALS['response'];
}
function wp_remote_retrieve_response_code( $result ) { return $result['code'] ?? 0; }
function wp_remote_retrieve_body( $result ) { return $result['body'] ?? ''; }
function wp_remote_retrieve_header( $result, $key ) { return $result['headers'][$key] ?? ''; }
function wp_tempnam( $name ) { return $GLOBALS['filename'] = tempnam( sys_get_temp_dir(), 'smup' ); }
function wp_parse_url( $url ) { return parse_url( $url ); }
function is_wp_error( $result ) { return $result instanceof WP_Error; }
function esc_html( $text ) { return htmlspecialchars( $text, ENT_QUOTES ); }
class WP_Error {}
function check( $value, $message ): void { if ( ! $value ) throw new RuntimeException( $message ); }
require dirname( __DIR__ ) . '/syndamorph-unpacker-poc/includes/updater.php';
$good = array(
    'tag_name' => 'v0.1.2', 'draft' => false, 'prerelease' => false,
    'published_at' => '2026-11-23T10:00:00Z', 'body' => 'Test release notes',
    'assets' => array( array(
        'name' => SMUP_RELEASE_ZIP, 'state' => 'uploaded', 'size' => 1042, 'id' => 123,
        'browser_download_url' => SMUP_UPDATE_URI . '/releases/download/v0.1.2/' . SMUP_RELEASE_ZIP,
    ) ),
);
$meta = smup_parse_release( $good );
check( $meta['version'] === '0.1.2', 'Stable release not recognized' );
check( str_ends_with( $meta['package'], SMUP_RELEASE_ZIP ), 'Wrong asset path' );
check( SMUP_ASSET_API . '123' === $meta['asset'], 'Wrong API asset path' );
foreach ( array(
    array_merge( $good, array( 'draft' => true ) ),
    array_merge( $good, array( 'prerelease' => true ) ),
    array_merge( $good, array( 'tag_name' => 'v0.1.2-rc1' ) ),
    array_merge( $good, array( 'published_at' => '' ) ),
    array_merge( $good, array( 'assets' => array() ) ),
    array_merge( $good, array( 'assets' => array( array_merge( $good['assets'][0], array( 'name' => 'source.zip' ) ) ) ) ),
    array_merge( $good, array( 'assets' => array( array_merge( $good['assets'][0], array( 'id' => '123/other' ) ) ) ) ),
    array_merge( $good, array( 'assets' => array( array_merge( $good['assets'][0], array( 'browser_download_url' => 'https://evil.example/plugin.zip' ) ) ) ) ),
) as $invalid ) check( [] === smup_parse_release( $invalid ), 'Invalid release was accepted' );
$GLOBALS['response'] = array( 'code' => 200, 'body' => json_encode( $good ) );
$one = smup_release_metadata();
$two = smup_release_metadata();
check( $one === $two && count( $GLOBALS['http_requests'] ) === 1, 'Cache did not work' );
check( $GLOBALS['http_requests'][0][0] === SMUP_RELEASE_API, 'Unexpected repo endpoint' );
check( ! isset( $GLOBALS['http_requests'][0][1]['headers']['Authorization'] ), 'Anonymous request unexpectedly used authentication' );
$update = $GLOBALS['filters']['update_plugins_github.com'];
$data = array( 'UpdateURI' => SMUP_UPDATE_URI, 'Version' => '0.1.1' );
$offer = $update( false, $data, SMUP_PLUGIN_FILE );
check( is_array( $offer ) && $offer['version'] === '0.1.2' && $offer['package'] === $meta['package'], 'Newer version not offered' );
check( false === $update( false, $data, 'someone/else.php' ), 'Updater attached to another plugin' );
check( false === $update( false, array( 'UpdateURI' => 'https://wrong.example', 'Version' => '0.1.1' ), SMUP_PLUGIN_FILE ), 'Wrong source accepted' );
check( 'preserve' === $update( 'preserve', $data, SMUP_PLUGIN_FILE ), 'Existing update result overwritten' );
check( '' === $update( false, array( 'UpdateURI' => SMUP_UPDATE_URI, 'Version' => '0.1.2' ), SMUP_PLUGIN_FILE )['package'], 'Equal release offered' );
check( '' === $update( false, array( 'UpdateURI' => SMUP_UPDATE_URI, 'Version' => '0.1.3' ), SMUP_PLUGIN_FILE )['package'], 'Older release offered' );
$info = $GLOBALS['filters']['plugins_api'];
$details = $info( false, 'plugin_information', (object) array( 'slug' => 'syndamorph-unpacker-poc' ) );
check( is_object( $details ) && $details->version === '0.1.2', 'Plugin information missing' );
check( str_contains( $details->sections['changelog'], 'Test release notes' ), 'Release notes missing' );
check( false === $info( false, 'plugin_information', (object) array( 'slug' => 'other' ) ), 'Plugin details leaked to other slug' );
$GLOBALS['transients'] = array();
$GLOBALS['response'] = array( 'code' => 404, 'body' => 'Not Found' );
check( [] === smup_release_metadata() && $GLOBALS['ttl'] === 60, 'Failed API response must fail closed and short-cache' );
$fallback = $update( false, $data, SMUP_PLUGIN_FILE );
check( is_array( $fallback ) && $fallback['version'] === '0.1.1' && '' === $fallback['package'], 'Missing release must still register supported no-update metadata' );
$report = array();
smup_release_metadata( true, $report );
check( 404 === $report['status'] && false === $report['authenticated'] && str_contains( $report['error'], 'SMUP_GITHUB_TOKEN' ), 'Anonymous 404 lacks an actionable diagnosis' );

$GLOBALS['response'] = array( 'code' => 200, 'body' => json_encode( $good ) );
check( [] === smup_release_metadata(), 'The anonymous failure should still be cached' );
define( 'SMUP_GITHUB_TOKEN', 'fixture-token' );
$offer = $update( false, $data, SMUP_PLUGIN_FILE );
check( $offer['package'] === $meta['asset'], 'Authenticated offer must use the private asset API and bypass the anonymous negative cache' );
$request = end( $GLOBALS['http_requests'] );
check( 'Bearer fixture-token' === $request[1]['headers']['Authorization'] && 0 === $request[1]['redirection'], 'Authenticated metadata request must disable automatic redirects' );

$GLOBALS['response'] = array( 'code' => 200, 'body' => '{"invalid-json"' );
check( $meta === smup_release_metadata(), 'Positive release cache was bypassed by a regular request' );
check( [] === smup_release_metadata( true, $report ) && 'GitHub returned invalid JSON.' === $report['error'], 'Fresh diagnostic did not bypass positive cache or report invalid JSON' );
$without_zip = array_merge( $good, array( 'assets' => array() ) );
$GLOBALS['response'] = array( 'code' => 200, 'body' => json_encode( $without_zip ) );
check( [] === smup_release_metadata( true, $report ) && '0.1.2' === $report['version'] &&
    str_contains( $report['error'], SMUP_RELEASE_ZIP ), 'Missing asset diagnosis did not retain the detected release version' );
$GLOBALS['response'] = new WP_Error();
check( [] === smup_release_metadata( true, $report ) && 0 === $report['status'], 'Transport failure did not produce diagnostic data' );

$download = $GLOBALS['filters']['upgrader_pre_download'];
$hook_extra = array( 'plugin' => SMUP_PLUGIN_FILE );
$asset = $meta['asset'];
$cdn = 'https://release-assets.githubusercontent.com/package?signature=fixture';
$zip = "PK\x03\x04fixture";
$requests = count( $GLOBALS['http_requests'] );
check( false === $download( false, $asset, null, array( 'plugin' => 'other/plugin.php' ) ), 'Download hook leaked to another plugin' );
check( false === $download( false, $asset . '?other=1', null, $hook_extra ), 'Unexpected asset API URL was accepted' );
check( 'existing' === $download( 'existing', $asset, null, $hook_extra ), 'Download hook overwrote an earlier result' );
check( $requests === count( $GLOBALS['http_requests'] ), 'Download hook contacted an unrelated URL' );
$GLOBALS['downloads'] = array(
    $asset => array( 'code' => 302, 'headers' => array( 'location' => $cdn ) ),
    $cdn => array( 'code' => 200, 'body' => $zip ),
);
$file = $download( false, $asset, null, $hook_extra );
check( is_string( $file ) && $zip === file_get_contents( $file ), 'Redirected ZIP was not returned to WordPress' );
unlink( $file );
$hops = array_slice( $GLOBALS['http_requests'], -2 );
check( $asset === $hops[0][0] && 'Bearer fixture-token' === $hops[0][1]['headers']['Authorization'] &&
    'application/octet-stream' === $hops[0][1]['headers']['Accept'] && 0 === $hops[0][1]['redirection'], 'Asset request must use authenticated binary mode with redirects disabled' );
check( $cdn === $hops[1][0] && ! isset( $hops[1][1]['headers'] ) && 0 === $hops[1][1]['redirection'], 'Signed redirect request must not carry credentials or follow further redirects' );
$GLOBALS['downloads'][$asset] = array( 'code' => 200, 'body' => $zip );
$file = $download( false, $asset, null, $hook_extra );
check( is_string( $file ) && $zip === file_get_contents( $file ), 'Direct GitHub ZIP response not supported' );
unlink( $file );
$GLOBALS['downloads'][$asset] = array( 'code' => 200, 'body' => '{"message":"not a ZIP"}' );
check( is_wp_error( $download( false, $asset, null, $hook_extra ) ) && ! file_exists( $GLOBALS['filename'] ), 'Invalid ZIP was accepted or left on disk' );
$requests = count( $GLOBALS['http_requests'] );
$GLOBALS['downloads'][$asset] = array( 'code' => 302, 'headers' => array( 'location' => 'https://release-assets.githubusercontent.com.example.test/zip' ) );
check( is_wp_error( $download( false, $asset, null, $hook_extra ) ) && ! file_exists( $GLOBALS['filename'] ) &&
    $requests + 1 === count( $GLOBALS['http_requests'] ), 'An untrusted redirect was followed or left a temporary file' );
print "PASS: GitHub release parsing, forced diagnostics, cache isolation, native update hooks, and private ZIP downloads\n";

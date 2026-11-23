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
function wp_remote_get( $url, $args ) {
    $GLOBALS['http_requests'][] = array( $url, $args );
    return $GLOBALS['response'];
}
function wp_remote_retrieve_response_code( $result ) { return $result['code'] ?? 0; }
function wp_remote_retrieve_body( $result ) { return $result['body'] ?? ''; }
function is_wp_error( $result ) { return $result instanceof WP_Error; }
function esc_html( $text ) { return htmlspecialchars( $text, ENT_QUOTES ); }
class WP_Error {}
function check( $value, $message ): void { if ( ! $value ) throw new RuntimeException( $message ); }
require dirname( __DIR__ ) . '/syndamorph-unpacker-poc/includes/updater.php';
$good = array(
    'tag_name' => 'v0.1.2', 'draft' => false, 'prerelease' => false,
    'published_at' => '2026-11-23T10:00:00Z', 'body' => 'Test release notes',
    'assets' => array( array(
        'name' => SMUP_RELEASE_ZIP, 'state' => 'uploaded', 'size' => 1042,
        'browser_download_url' => SMUP_UPDATE_URI . '/releases/download/v0.1.2/' . SMUP_RELEASE_ZIP,
    ) ),
);
$meta = smup_parse_release( $good );
check( $meta['version'] === '0.1.2', 'Stable release not recognized' );
check( str_ends_with( $meta['package'], SMUP_RELEASE_ZIP ), 'Wrong asset path' );
foreach ( array(
    array_merge( $good, array( 'draft' => true ) ),
    array_merge( $good, array( 'prerelease' => true ) ),
    array_merge( $good, array( 'tag_name' => 'v0.1.2-rc1' ) ),
    array_merge( $good, array( 'published_at' => '' ) ),
    array_merge( $good, array( 'assets' => array() ) ),
    array_merge( $good, array( 'assets' => array( array_merge( $good['assets'][0], array( 'name' => 'source.zip' ) ) ) ) ),
    array_merge( $good, array( 'assets' => array( array_merge( $good['assets'][0], array( 'browser_download_url' => 'https://evil.example/plugin.zip' ) ) ) ) ),
) as $invalid ) check( [] === smup_parse_release( $invalid ), 'Invalid release was accepted' );
$GLOBALS['response'] = array( 'code' => 200, 'body' => json_encode( $good ) );
$one = smup_release_metadata();
$two = smup_release_metadata();
check( $one === $two && count( $GLOBALS['http_requests'] ) === 1, 'Cache did not work' );
check( $GLOBALS['http_requests'][0][0] === SMUP_RELEASE_API, 'Unexpected repo endpoint' );
check( ! isset( $GLOBALS['http_requests'][0][1]['headers']['Authorization'] ), 'Updater must not require a private token' );
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
check( [] === smup_release_metadata() && $GLOBALS['ttl'] === 900, 'Failed API response must fail closed and short-cache' );
print "PASS: GitHub release parsing, security gates, caching, WordPress update and plugin-info hooks\n";

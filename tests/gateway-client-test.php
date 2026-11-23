<?php
declare(strict_types=1);

// WordPress HTTP and option doubles for the independent Gateway client smoke test.
$GLOBALS['smup_gateway_options'] = [];
$GLOBALS['smup_gateway_http_status'] = 200;
$GLOBALS['smup_gateway_http_calls'] = [];
function wp_salt($name) { return 'gateway-test-salt-' . $name; }
function wp_parse_url($url, $part = -1) { return parse_url($url, $part); }
function wp_http_validate_url($url) { return str_starts_with($url, 'https://test-gateway.example.org/') ? $url : false; }
function get_option($name, $default = false) { return $GLOBALS['smup_gateway_options'][$name] ?? $default; }
function update_option($name, $value, $autoload = null) { $GLOBALS['smup_gateway_options'][$name] = $value; return true; }
function delete_option($name) { unset($GLOBALS['smup_gateway_options'][$name]); return true; }
function wp_remote_request($url, $args) {
    $GLOBALS['smup_gateway_http_calls'][] = [$url, $args];
    $status = $GLOBALS['smup_gateway_http_status'];
    if ($status !== 200) return ['status' => $status, 'body' => '{"error":"denied"}'];
    if (str_ends_with($url, '/v1/client/me'))
        return ['status' => 200, 'body' => '{"id":"cus_aaaaaaaaaaaaaaaaaaaaaaaa","name":"Client A"}'];
    if (str_ends_with($url, '/v1/packages'))
        return ['status' => 200, 'body' => '{"packages":[{"id":"pkg_bbbbbbbbbbbbbbbbbbbbbbbb","name":"A package","versions":[{"version":"1.0.0","sha256":"' . str_repeat('a', 64) . '","size":100}]}]}'];
    if (str_ends_with($url, '/downloads'))
        return ['status' => 200, 'body' => '{"grant":"' . str_repeat('x', 43) . '","sha256":"' . str_repeat('b', 64) . '","size":100}'];
    throw new RuntimeException('Unexpected Gateway test request: ' . $url);
}
function wp_remote_retrieve_response_code($response) { return $response['status']; }
function wp_remote_retrieve_body($response) { return $response['body']; }

require __DIR__ . '/import-test.php';

$key = 'smup_' . str_repeat('a', 43);
$url = 'https://test-gateway.example.org';
expect(!smup_gateway_valid_url('http://test-gateway.example.org'), 'Plaintext connection accepted');
expect(!smup_gateway_valid_url('https://test-gateway.example.org@evil.example.org'), 'Userinfo accepted');
expect(!smup_gateway_valid_url('https://test-gateway.example.org/v1/elsewhere'), 'Arbitrary base path accepted');
expect(smup_gateway_valid_url($url), 'Public HTTPS gateway rejected');
$identity = smup_gateway_connect($url, $key);
expect(is_array($identity) && $identity['name'] === 'Client A', 'Gateway authentication failed');
$stored = $GLOBALS['smup_gateway_options']['smup_gateway_connection'];
expect(!str_contains(json_encode($stored), $key), 'Raw API key was stored in options');
expect(smup_gateway_config()['key'] === $key, 'Encrypted key could not be recovered');
$packages = smup_gateway_packages();
expect(is_array($packages) && count($packages) === 1, 'Private package discovery failed');
expect($packages[0]['id'] === 'pkg_bbbbbbbbbbbbbbbbbbbbbbbb', 'Unexpected private package ID');
$failed = smup_gateway_import('pkg_bbbbbbbbbbbbbbbbbbbbbbbb', '1.0.0');
expect(is_wp_error($failed) && str_contains($failed->get_error_message(), 'grant'), 'Mismatched download grant accepted');
$GLOBALS['smup_gateway_http_status'] = 403;
expect(is_wp_error(smup_gateway_packages()), 'Revoked lease unexpectedly allowed');
delete_option('smup_gateway_connection');
expect(smup_gateway_config() === [], 'Credential remained after disconnect');
expect(count($GLOBALS['posts']) === 1, 'Remote connection changed local imported pattern');
echo "PASS: HTTPS-only connection, encrypted credential, discovery, grant checksum denial, lease denial, disconnect independence\n";

<?php
declare(strict_types=1);

require __DIR__ . '/import-test.php';
if ( ! class_exists( 'ZipArchive' ) ) {
    throw new RuntimeException( 'Enable PHP ZipArchive to run the portable package tests.' );
}

function portable_test_package( array $manifest, array $files ) {
    $filename = tempnam( sys_get_temp_dir(), 'sm-portable-' );
    $zip = new ZipArchive();
    expect( $zip->open( $filename, ZipArchive::CREATE | ZipArchive::OVERWRITE ) === true, 'Cannot open ZIP fixture' );
    $zip->addFromString( 'manifest.json', json_encode( $manifest, JSON_THROW_ON_ERROR ) );
    foreach ( $files as $path => $bytes ) { $zip->addFromString( $path, $bytes ); }
    expect( $zip->close(), 'Cannot write ZIP fixture' );
    try {
        return smup_read_zip_file( $filename );
    } finally {
        unlink( $filename );
    }
}

function portable_test_file( string $kind, string $bytes ): array {
    return array( 'kind' => $kind, 'bytes' => strlen( $bytes ), 'sha256' => hash( 'sha256', $bytes ) );
}

$body = '<!-- wp:paragraph --><p>Native example</p><!-- /wp:paragraph -->';
$block_path = 'items/pattern-test.blocks.html';
$css_path = 'items/pattern-test.css';
$manifest = [
    'schema' => 2, 'type' => 'wordpress-portable-content',
    'items' => [[ 'id' => 'pattern-test', 'kind' => 'pattern', 'title' => 'Native example',
        'slug' => 'native-example', 'content' => $block_path, 'blocks' => 1,
        'regions' => null, 'canvas' => 'native', 'style' => null ]],
    'files' => [ $block_path => portable_test_file( 'blocks', $body ) ],
    'components' => [], 'media' => [],
];
$valid = portable_test_package( $manifest, [ $block_path => $body ] );
expect( is_array( $valid ) && $valid['portable'] && $valid['data'][$block_path] === $body, 'Portable Core selection rejected' );

foreach ( [
    'same-length corrupt content' => [ $block_path => str_replace( 'Native', 'Edited', $body ) ],
    'unlisted extra file' => [ $block_path => $body, 'extra.txt' => 'extra' ],
    'renamed content' => [ 'items/another.blocks.html' => $body ],
    'empty content' => [ $block_path => '' ],
] as $name => $files ) {
    expect( is_wp_error( portable_test_package( $manifest, $files ) ), 'Accepted ' . $name );
}

$unrelated = $manifest;
$other = 'items/unused.blocks.html';
$unrelated['files'][$other] = portable_test_file( 'blocks', $body );
expect( is_wp_error( portable_test_package( $unrelated, [ $block_path => $body, $other => $body ] ) ), 'Unselected content was accepted' );

foreach ( [
    '@import "unrelated.css";',
    'main{background:url(https://example.com/a.png)}',
    'main{background:image-set("https://example.com/a.png" 1x)}',
    'main{background:url(./relative.png)}',
    'main{background:u\72l(./relative.png)}',
    '</style>',
] as $css ) {
    $unsafe = $manifest;
    $unsafe['items'][0]['style'] = $css_path;
    $unsafe['files'][$css_path] = portable_test_file( 'style', $css );
    $result = portable_test_package( $unsafe, [ $block_path => $body, $css_path => $css ] );
    expect( is_wp_error( $result ), 'Foreign or executable stylesheet resource accepted' );
}
$asset = 'assets/' . str_repeat( 'a', 64 ) . '.otf';
$style = '@media (width < 1024px){main{max-width:100%}}@font-face{font-family:Local;src:url(sm-asset:' . $asset . ')}';
expect( smup_portable_css_paths( $style ) === [ $asset ], 'Local stylesheet marker or media-range syntax rejected' );
$font = '@font-face{font-family:"Local Face";font-style:normal;font-weight:100 900;src:url(sm-asset:' . $asset . ') format("opentype")}';
$alias = '.has-local-face-font-family{font-family:"Local Face",system-ui,sans-serif!important}';
expect( smup_portable_typography_only( $font . $alias ), 'Portable typography could not be registered for unsynced copies' );
expect( ! smup_portable_typography_only( $font . '.has-local-face-font-family{display:none}' ), 'Non-typography rule accepted in a native style' );

echo "PASS: portable ZIP manifest, checksum, selection closure, CSS safety and local resource markers\n";

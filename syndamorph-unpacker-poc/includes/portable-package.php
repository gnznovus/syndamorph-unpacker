<?php
/** Reader for selection-scoped Gutenberg packages. Never extract ZIP paths. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

const SMUP_PORTABLE_MAX_ZIP_BYTES = 25 * 1024 * 1024;
const SMUP_PORTABLE_MAX_EXPANDED_BYTES = 32 * 1024 * 1024;

function smup_portable_path_kind( string $path ): string {
    if ( preg_match( '~\Aitems/[a-z][a-z0-9-]{0,63}\.blocks\.html\z~', $path ) ) { return 'blocks'; }
    if ( preg_match( '~\Aitems/[a-z][a-z0-9-]{0,63}\.css\z~', $path ) ) { return 'style'; }
    if ( preg_match( '~\Aassets/[a-f0-9]{64}\.(?:png|jpe?g|webp|gif|avif)\z~', $path ) ) { return 'image'; }
    if ( preg_match( '~\Aassets/[a-f0-9]{64}\.(?:woff2?|ttf|otf)\z~', $path ) ) { return 'font'; }
    return '';
}

function smup_portable_css_paths( string $css ) {
    // Only literal, local asset markers are allowed in CSS capable of a fetch.
    if ( preg_match( '~[\\\\\x00-\x08\x0B\x0C\x0E-\x1F]|<\s*[/!a-z]|/\*|(?:https?|data|javascript)\s*:|(?:image-set|src|image|expression)\s*\(~i', $css ) ||
        preg_match( '~@(?!media\b|supports\b|font-face\b|keyframes\b|container\b)~i', $css ) ) {
        return smup_error( 'Portable stylesheet contains unsupported CSS.' );
    }
    preg_match_all( '~(?<![\w-])url\s*\(([^)]*)\)~i', $css, $matches );
    $paths = array();
    foreach ( $matches[1] as $value ) {
        if ( ! str_starts_with( $value, 'sm-asset:' ) ) {
            return smup_error( 'Portable stylesheet contains an external resource.' );
        }
        $path = substr( $value, strlen( 'sm-asset:' ) );
        if ( ! in_array( smup_portable_path_kind( $path ), array( 'image', 'font' ), true ) ) {
            return smup_error( 'Portable stylesheet references an invalid asset path.' );
        }
        $paths[$path] = true;
    }
    if ( preg_match( '~(?<![\w-])url\s*\(~i', preg_replace( '~(?<![\w-])url\s*\([^)]*\)~i', '', $css ) ) ) {
        return smup_error( 'Portable stylesheet contains an invalid resource declaration.' );
    }
    return array_keys( $paths );
}

function smup_portable_typography_only( string $css ): bool {
    $family = '"[A-Za-z0-9][A-Za-z0-9 ._-]{0,63}"';
    $face = '@font-face\{font-family:' . $family . ';font-style:(?:normal|italic);font-weight:[0-9]{1,4}(?: [0-9]{1,4})?;' .
        'src:url\(sm-asset:assets/[a-f0-9]{64}\.(?:woff2?|ttf|otf)\) format\("(?:woff2?|truetype|opentype)"\)\}';
    $preset = '\.has-[a-z][a-z0-9-]{0,63}-font-family\{font-family:' . $family . ',system-ui,sans-serif!important\}';
    $remaining = preg_replace( '~(?:' . $face . '|' . $preset . ')~', '', $css );
    return is_string( $remaining ) && trim( $remaining ) === '';
}

function smup_portable_read_zip( ZipArchive $zip, array $manifest, string $manifest_text ) {
    $files = $manifest['files'] ?? null;
    $items = $manifest['items'] ?? null;
    $media = $manifest['media'] ?? null;
    $components = $manifest['components'] ?? null;
    if ( array_diff( array_keys( $manifest ), array( 'schema', 'type', 'files', 'items', 'media', 'components' ) ) ||
        ! is_array( $files ) || ! is_array( $items ) || ! array_is_list( $items ) || ! $items || count( $items ) > 40 ||
        ! is_array( $media ) || ! is_array( $components ) || count( $components ) > 20 ||
        count( $files ) > 160 || count( $files ) + 1 !== $zip->numFiles ) {
        return smup_error( 'Invalid portable package inventory.' );
    }
    $expanded = strlen( $manifest_text );
    $seen = array( 'manifest.json' => false );
    $data = array();
    for ( $i = 0; $i < $zip->numFiles; $i++ ) {
        $stat = $zip->statIndex( $i );
        $path = $stat['name'] ?? '';
        if ( 'manifest.json' === $path && ! $seen[$path] ) {
            $seen[$path] = true;
            continue;
        }
        $kind = smup_portable_path_kind( $path );
        $entry = $files[$path] ?? null;
        $limit = array( 'blocks' => SMUP_MAX_CONTENT_BYTES, 'style' => 524288, 'image' => 8 * 1024 * 1024, 'font' => 1048576 )[$kind] ?? 0;
        if ( isset( $seen[$path] ) || ! $kind || ! is_array( $entry ) || count( $entry ) !== 3 || $kind !== ( $entry['kind'] ?? null ) ||
            ! is_int( $entry['bytes'] ?? null ) || $entry['bytes'] < 1 || $entry['bytes'] > $limit ||
            $entry['bytes'] !== ( $stat['size'] ?? null ) || ! is_string( $entry['sha256'] ?? null ) ||
            ! preg_match( '/\A[a-f0-9]{64}\z/', $entry['sha256'] ) ||
            ( str_starts_with( $path, 'assets/' ) && substr( $path, 7, 64 ) !== $entry['sha256'] ) ||
            ( $expanded += $entry['bytes'] ) > SMUP_PORTABLE_MAX_EXPANDED_BYTES ) {
            return smup_error( 'Duplicate, unexpected, or oversized portable ZIP entry.' );
        }
        $bytes = $zip->getFromIndex( $i, $entry['bytes'] + 1 );
        if ( ! is_string( $bytes ) || strlen( $bytes ) !== $entry['bytes'] ||
            ! hash_equals( $entry['sha256'], hash( 'sha256', $bytes ) ) ) {
            return smup_error( 'Portable package entry failed its size or checksum check.' );
        }
        $seen[$path] = true;
        $data[$path] = $bytes;
    }
    if ( ! $seen['manifest.json'] ) { return smup_error( 'Portable package manifest is missing.' ); }

    $paths = array();
    $ids = array();
    foreach ( $items as $item ) {
        $id = $item['id'] ?? '';
        if ( ! is_array( $item ) || count( $item ) !== 9 || ! is_string( $id ) ||
            ! preg_match( '/\A[a-z][a-z0-9-]{0,63}\z/', $id ) || isset( $ids[$id] ) ||
            ! in_array( $item['kind'] ?? null, array( 'page', 'pattern' ), true ) ||
            ! is_string( $item['title'] ?? null ) || trim( $item['title'] ) === '' ||
            strlen( $item['title'] ) > 512 || sanitize_text_field( $item['title'] ) !== $item['title'] ||
            ! is_string( $item['slug'] ?? null ) || ! preg_match( '/\A[a-z0-9][a-z0-9-]{0,99}\z/', $item['slug'] ) ||
            ( $item['content'] ?? null ) !== "items/$id.blocks.html" || ! isset( $data[$item['content']] ) ||
            ! is_int( $item['blocks'] ?? null ) || $item['blocks'] < 1 || $item['blocks'] > 5000 ||
            ! array_key_exists( 'regions', $item ) || ( null !== $item['regions'] &&
                ( ! is_int( $item['regions'] ) || $item['regions'] < 1 || $item['regions'] > $item['blocks'] ) ) ||
            ! in_array( $item['canvas'] ?? null, array( 'native', 'production-v1' ), true ) ||
            ! array_key_exists( 'style', $item ) ) {
            return smup_error( 'Invalid portable document metadata.' );
        }
        $ids[$id] = true;
        $paths[$item['content']] = true;
        if ( 'production-v1' === $item['canvas'] && ( 'page' !== $item['kind'] || null === $item['style'] ) ) {
            return smup_error( 'A production canvas requires a page and a stylesheet.' );
        }
        if ( null !== $item['style'] ) {
            if ( $item['style'] !== "items/$id.css" || ! isset( $data[$item['style']] ) ) {
                return smup_error( 'Portable document stylesheet is missing.' );
            }
            $css_paths = smup_portable_css_paths( $data[$item['style']] );
            if ( is_wp_error( $css_paths ) ) { return $css_paths; }
            if ( 'native' === $item['canvas'] && ! smup_portable_typography_only( $data[$item['style']] ) ) {
                return smup_error( 'A native document stylesheet may only carry local typography.' );
            }
            $paths[$item['style']] = true;
            foreach ( $css_paths as $path ) { $paths[$path] = true; }
        }
    }
    $source_ids = array();
    $source_urls = array();
    foreach ( $media as $id => $binding ) {
        $source_id = $binding['sourceId'] ?? null;
        $url = $binding['sourceUrl'] ?? null;
        $path = $binding['path'] ?? null;
        if ( ! is_array( $binding ) || count( $binding ) !== 4 || ! is_int( $source_id ) || $source_id < 1 ||
            $id !== 'media-' . $source_id || isset( $source_ids[$source_id] ) || ! is_string( $url ) ||
            strlen( $url ) > 2048 || ! preg_match( '~\Ahttps?://[^/\s@]+/[^\s?#"<>]+\z~', $url ) ||
            isset( $source_urls[$url] ) || ! is_string( $path ) || 'image' !== smup_portable_path_kind( $path ) ||
            ! is_string( $binding['alt'] ?? null ) || strlen( $binding['alt'] ) > 512 ||
            sanitize_text_field( $binding['alt'] ) !== $binding['alt'] ) {
            return smup_error( 'Invalid portable attachment binding.' );
        }
        $source_ids[$source_id] = true;
        $source_urls[$url] = true;
        $paths[$path] = true;
    }
    if ( array_diff_key( $paths, $data ) || array_diff_key( $data, $paths ) ) {
        return smup_error( 'Portable package has missing or unrelated files.' );
    }
    return array(
        'portable' => true,
        'identity' => hash( 'sha256', $manifest_text ),
        'manifest' => $manifest,
        'data' => $data,
    );
}

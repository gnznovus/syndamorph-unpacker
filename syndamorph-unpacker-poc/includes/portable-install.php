<?php
/** Installs validated selections using native posts and permanent WordPress uploads. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function smup_portable_link_allowed( string $value ): bool {
    return $value !== '' && ! preg_match( '~[\x00-\x20\x7f\\\\]~', $value ) &&
        preg_match( '~\A(?:\#|https?://[^/@?#]+(?:[/?#]|\z)|mailto:|tel:)~i', $value );
}

function smup_portable_remap_html( string $html, array $by_url, array $by_id, array &$references ): string {
    $allowed = explode( ' ', 'A ARTICLE ASIDE B BLOCKQUOTE BR BUTTON CITE CODE DD DETAILS DIV DL DT EM FIGCAPTION FIGURE FOOTER H1 H2 H3 H4 H5 H6 HEADER HR I IMG LI MAIN MARK NAV OL P S SECTION SMALL SPAN STRONG SUB SUMMARY SUP UL' );
    if ( preg_match( '~<(?:[!?]|/?[a-z][a-z0-9-]*:)~i', $html ) ) {
        throw new RuntimeException( 'A block contains unsupported HTML markup.' );
    }
    $tags = new WP_HTML_Tag_Processor( $html );
    while ( $tags->next_tag() ) {
        $tag = $tags->get_tag();
        if ( ! in_array( $tag, $allowed, true ) ) {
            throw new RuntimeException( 'A block contains an unsupported HTML tag.' );
        }
        foreach ( $tags->get_attribute_names_with_prefix( '' ) ?? array() as $name ) {
            $value = $tags->get_attribute( $name );
            if ( preg_match( '/\A(?:on|data-wp-)/i', $name ) ||
                in_array( $name, array( 'ping', 'action', 'formaction', 'srcdoc', 'background', 'srcset', 'poster', 'xmlns', 'is' ), true ) ) {
                throw new RuntimeException( 'A block contains an unsupported HTML attribute.' );
            }
            if ( 'src' === $name || 'href' === $name ) {
                if ( is_string( $value ) && isset( $by_url[$value] ) ) {
                    $media = $by_url[$value];
                    $references[$media['sourceId']] = true;
                    if ( isset( $media['url'] ) ) { $tags->set_attribute( $name, $media['url'] ); }
                    continue;
                }
                if ( 'href' !== $name || 'A' !== $tag || ! is_string( $value ) || ! smup_portable_link_allowed( $value ) ) {
                    throw new RuntimeException( 'A block contains a nonportable URL.' );
                }
            }
            if ( 'style' === $name && ( ! is_string( $value ) || smup_portable_css_paths( $value ) !== array() ) ) {
                throw new RuntimeException( 'A block contains nonportable inline styling.' );
            }
            if ( 'class' === $name && is_string( $value ) ) {
                $class = preg_replace_callback( '/(?<!\S)wp-image-([0-9]+)(?!\S)/', static function ( array $match ) use ( $by_id, &$references ): string {
                    $source_id = (int) $match[1];
                    if ( ! isset( $by_id[$source_id] ) ) { throw new RuntimeException( 'A block references an unlisted attachment class.' ); }
                    $references[$source_id] = true;
                    return isset( $by_id[$source_id]['id'] ) ? 'wp-image-' . $by_id[$source_id]['id'] : $match[0];
                }, $value );
                if ( $class !== $value ) { $tags->set_attribute( $name, $class ); }
            }
        }
    }
    return $tags->get_updated_html();
}

function smup_portable_remap_blocks( array $blocks, array $media, array &$requirements, array &$references, int &$count, int $depth = 0 ): array {
    if ( $depth > 40 ) { throw new RuntimeException( 'Portable block nesting exceeds its limit.' ); }
    $core = explode( ' ', 'group columns column heading paragraph separator spacer buttons button list list-item quote image details' );
    $custom = explode( ' ', 'recorded-header booking-summary desktop-offer recorded-newsletter tabs' );
    foreach ( $blocks as &$block ) {
        $name = $block['blockName'];
        if ( null === $name && '' === trim( $block['innerHTML'] ) ) { continue; }
        $parts = explode( '/', (string) $name, 2 );
        if ( count( $parts ) !== 2 || ++$count > 5000 || ! WP_Block_Type_Registry::get_instance()->is_registered( $name ) ) {
            throw new RuntimeException( 'A selected block is unavailable or exceeds the block limit.' );
        }
        [$namespace, $type] = $parts;
        if ( 'syndamorph' === $namespace && in_array( $type, $custom, true ) ) {
            $requirements[$type] = true;
        } elseif ( 'core' !== $namespace || ! in_array( $type, $core, true ) ) {
            throw new RuntimeException( 'An unsupported block is present in the selection.' );
        }
        $attrs = $block['attrs'];
        if ( 'syndamorph' === $namespace && null === match ( $type ) {
            'recorded-header' => syndamorph_header_content( $attrs['navigation'] ?? null ),
            'booking-summary' => syndamorph_booking_copy( $attrs['copy'] ?? array() ),
            'desktop-offer' => syndamorph_offer_copy( $attrs['copy'] ?? null ),
            'recorded-newsletter' => syndamorph_newsletter_profile( $attrs['profile'] ?? null ),
            'tabs' => syndamorph_tabs_decode( $attrs['panelData'] ?? null ),
        } ) {
            throw new RuntimeException( 'A custom block does not match its Runtime component contract.' );
        }
        if ( 'core' === $namespace && ! empty( $attrs['tagName'] ) &&
            ! in_array( $attrs['tagName'], explode( ' ', 'div section article aside main header footer h1 h2 h3 h4 h5 h6 nav p' ), true ) ) {
            throw new RuntimeException( 'A Core block declares an unsafe element.' );
        }
        if ( 'core' === $namespace && ( isset( $attrs['metadata']['bindings'] ) || isset( $attrs['url'] ) ||
            isset( $attrs['style']['background']['backgroundImage'] ) ) ) {
            throw new RuntimeException( 'A Core block declares an unsupported external binding.' );
        }
        foreach ( preg_split( '/\s+/', (string) ( $attrs['className'] ?? '' ) ) as $class ) {
            if ( str_starts_with( $class, 'sm-' ) || str_starts_with( $class, 'is-style-sm-' ) ) {
                $requirements['native-layout'] = true;
                if ( 'sm-rooms-layout' === $class ) { $requirements['rooms'] = true; }
            }
        }
        $id = match ( $name ) {
            'core/image' => $attrs['id'] ?? 0,
            'syndamorph/recorded-header' => $attrs['navigation']['logoId'] ?? 0,
            default => 0,
        };
        if ( $id || 'core/image' === $name ) {
            if ( ! is_int( $id ) || ! isset( $media['ids'][$id] ) ) {
                throw new RuntimeException( 'A selected block references a missing image.' );
            }
            $references[$id] = true;
            $binding = $media['ids'][$id];
            if ( 'syndamorph/recorded-header' === $name && ( $attrs['logoSha256'] ?? null ) !== substr( $binding['path'], 7, 64 ) ) {
                throw new RuntimeException( 'The recorded header logo identity does not match its file.' );
            }
            if ( isset( $binding['id'] ) ) {
                if ( 'core/image' === $name ) {
                    $block['attrs']['id'] = $binding['id'];
                } else {
                    $block['attrs']['navigation']['logoId'] = $binding['id'];
                }
            }
        }
        foreach ( $block['innerContent'] as &$html ) {
            if ( is_string( $html ) ) {
                $html = smup_portable_remap_html( $html, $media['urls'], $media['ids'], $references );
            }
        }
        unset( $html );
        $block['innerBlocks'] = smup_portable_remap_blocks( $block['innerBlocks'], $media, $requirements, $references, $count, $depth + 1 );
    }
    unset( $block );
    return $blocks;
}

function smup_portable_index_media( array $media ): array {
    $index = array( 'ids' => array(), 'urls' => array() );
    foreach ( $media as $binding ) {
        $index['ids'][$binding['sourceId']] = $binding;
        $index['urls'][$binding['sourceUrl']] = $binding;
    }
    return $index;
}

function smup_portable_plan( array $package ): array {
    $manifest = $package['manifest'];
    $runtime = function_exists( 'syndamorph_runtime_components' ) ? syndamorph_runtime_components() : array();
    foreach ( $manifest['components'] as $component => $version ) {
        if ( ! is_int( $version ) || ( $runtime[$component] ?? null ) !== $version ) {
            throw new RuntimeException( 'Install and activate a compatible SyndaMorph Runtime before importing this selection.' );
        }
    }
    $media = smup_portable_index_media( $manifest['media'] );
    $requirements = $references = $plan = array();
    foreach ( $manifest['items'] as $item ) {
        $id = $item['id'];
        $item_components = $item_references = array();
        if ( 'production-v1' === $item['canvas'] ) { $item_components['production-canvas'] = true; }
        if ( null !== $item['style'] ) { $item_components['native-layout'] = true; }
        $count = 0;
        $blocks = parse_blocks( $package['data'][$item['content']] );
        smup_portable_remap_blocks( $blocks, $media, $item_components, $item_references, $count );
        if ( $count !== $item['blocks'] || ( null !== $item['regions'] &&
            count( array_filter( $blocks, static fn( array $b ): bool => $b['blockName'] !== null ) ) < $item['regions'] ) ) {
            throw new RuntimeException( 'A portable document block or region count is incorrect.' );
        }
        $references += $item_references;
        $requirements += $item_components;
        $bindings = array_intersect_key( $media['ids'], $item_references );
        $versions = array_intersect_key( $manifest['components'], $item_components );
        ksort( $bindings );
        ksort( $versions );
        $identity_fields = $item;
        ksort( $identity_fields );
        $identity = hash( 'sha256', wp_json_encode( array( $identity_fields,
            $manifest['files'][$item['content']]['sha256'], $manifest['files'][$item['style']]['sha256'] ?? null,
            $bindings, $versions,
        ) ) );
        $plan[$id] = array( 'blocks' => $blocks, 'identity' => $identity );
    }
    if ( array_diff_key( $references, $media['ids'] ) || array_diff_key( $media['ids'], $references ) ||
        array_diff_key( $requirements, $manifest['components'] ) || array_diff_key( $manifest['components'], $requirements ) ) {
        throw new RuntimeException( 'Portable package dependencies do not match the selection.' );
    }
    return $plan;
}

function smup_portable_persist_image( array $binding, string $bytes, array &$created_posts, array &$created_files ): array {
    $sha = hash( 'sha256', $bytes );
    $alt = $binding['alt'];
    $identity = hash( 'sha256', $sha . "\n" . $alt );
    $types = array( 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'webp' => 'image/webp', 'gif' => 'image/gif', 'avif' => 'image/avif' );
    $ext = pathinfo( $binding['path'], PATHINFO_EXTENSION );
    $size = @getimagesizefromstring( $bytes );
    $type = $types[$ext] ?? null;
    if ( ! $size || $type !== $size['mime'] || $size[0] < 1 || $size[1] < 1 || $size[0] * $size[1] > 40000000 ) {
        throw new RuntimeException( 'A portable image failed its format or dimensions check.' );
    }
    $existing = get_posts( array(
        'post_type' => 'attachment', 'post_status' => 'inherit', 'numberposts' => 1,
        'meta_key' => '_sm_portable_asset', 'meta_value' => $identity,
    ) );
    if ( $existing ) {
        $id = (int) $existing[0]->ID;
        $file = get_attached_file( $id );
        $url = wp_get_attachment_url( $id );
        if ( ! $file || ! is_file( $file ) || hash_file( 'sha256', $file ) !== $sha || ! $url ) {
            throw new RuntimeException( 'A previously installed portable image failed its integrity check.' );
        }
        return $binding + array( 'id' => $id, 'url' => $url );
    }
    $upload = wp_upload_bits( substr( $sha, 0, 24 ) . '.' . $ext, null, $bytes );
    if ( ! empty( $upload['error'] ) ) { throw new RuntimeException( 'WordPress could not store a portable image.' ); }
    $created_files[] = $upload['file'];
    $actual = wp_check_filetype_and_ext( $upload['file'], basename( $upload['file'] ) );
    if ( ( $actual['type'] ?? null ) !== $type || hash_file( 'sha256', $upload['file'] ) !== $sha ) {
        throw new RuntimeException( 'WordPress rejected an image type or changed its bytes.' );
    }
    $id = wp_insert_attachment( wp_slash( array(
        'post_title' => $alt ?: 'Portable image ' . substr( $sha, 0, 12 ),
        'post_status' => 'inherit', 'post_mime_type' => $type,
    ) ), $upload['file'], 0, true );
    if ( is_wp_error( $id ) || ! $id ) { throw new RuntimeException( 'WordPress could not register a portable image.' ); }
    $created_posts[] = (int) $id;
    if ( ! add_post_meta( $id, '_sm_portable_asset', $identity, true ) ||
        ( $alt !== '' && ! add_post_meta( $id, '_wp_attachment_image_alt', wp_slash( $alt ), true ) ) ||
        ! wp_update_attachment_metadata( $id, array(
            'width' => $size[0], 'height' => $size[1], 'file' => _wp_relative_upload_path( $upload['file'] ), 'sizes' => array(),
        ) ) ) {
        throw new RuntimeException( 'WordPress could not store portable attachment metadata.' );
    }
    return $binding + array( 'id' => (int) $id, 'url' => wp_get_attachment_url( $id ) );
}

function smup_portable_store_font( string $path, string $bytes, array &$created_files ): string {
    $ext = pathinfo( $path, PATHINFO_EXTENSION );
    $magic = array( 'woff' => 'wOFF', 'woff2' => 'wOF2', 'otf' => 'OTTO', 'ttf' => "\x00\x01\x00\x00" );
    if ( strlen( $bytes ) < 12 || ( $magic[$ext] ?? null ) !== substr( $bytes, 0, 4 ) ) {
        throw new RuntimeException( 'A portable font failed its format check.' );
    }
    $uploads = wp_get_upload_dir();
    $folder = trailingslashit( $uploads['basedir'] ) . 'syndamorph-portable';
    $asset = basename( $path );
    $target = $folder . '/' . $asset;
    if ( ! empty( $uploads['error'] ) || ! wp_mkdir_p( $folder ) || is_link( $folder ) || is_link( $target ) ) {
        throw new RuntimeException( 'The local portable font directory is unavailable.' );
    }
    if ( ! is_file( $target ) ) {
        $stream = @fopen( $target, 'xb' );
        if ( ! $stream ) { throw new RuntimeException( 'The portable font could not be opened for writing.' ); }
        $created_files[] = $target;
        $written = fwrite( $stream, $bytes );
        $closed = fclose( $stream );
        if ( $written !== strlen( $bytes ) || ! $closed ) {
            throw new RuntimeException( 'The portable font could not be saved.' );
        }
    }
    if ( hash_file( 'sha256', $target ) !== hash( 'sha256', $bytes ) ) {
        throw new RuntimeException( 'The installed font failed its integrity check.' );
    }
    return trailingslashit( $uploads['baseurl'] ) . 'syndamorph-portable/' . $asset;
}

function smup_portable_resolve_css( string $css, array $asset_urls ): string {
    return preg_replace_callback( '~url\s*\(sm-asset:(assets/[a-f0-9]{64}\.[a-z0-9]+)\)~i',
        static function ( array $match ) use ( $asset_urls ): string {
            $url = $asset_urls[$match[1]] ?? '';
            if ( ! $url || preg_match( '~[\x00-\x20"<>\\\\]~', $url ) ) {
                throw new RuntimeException( 'A portable stylesheet asset is unavailable.' );
            }
            return 'url("' . $url . '")';
        }, $css );
}

function smup_portable_import( array $package ) {
    if ( ! current_user_can( 'manage_options' ) ) { return smup_error( 'Only a site administrator can install portable content.', 403 ); }
    if ( ! add_option( '_sm_portable_install_lock', 1, '', false ) ) {
        return smup_error( 'Another portable installation is in progress.', 409 );
    }
    $created_posts = $created_files = array();
    try {
        $plan = smup_portable_plan( $package );
        $items = $package['manifest']['items'];
        $results = $missing = array();
        foreach ( $items as $item ) {
            $key = $plan[$item['id']]['identity'];
            $existing = get_posts( array(
                'post_type' => 'page' === $item['kind'] ? 'page' : 'wp_block',
                'post_status' => array( 'draft', 'publish', 'pending', 'private', 'future', 'trash' ),
                'numberposts' => 2, 'meta_key' => '_sm_portable_identity', 'meta_value' => $key,
            ) );
            if ( count( $existing ) > 1 || ( $existing && 'trash' === $existing[0]->post_status ) ) {
                throw new RuntimeException( 'A previously installed item is in conflict. Restore or delete it in WordPress first.' );
            }
            if ( $existing ) {
                $results[$item['id']] = (int) $existing[0]->ID;
            } else {
                $missing[] = $item;
            }
        }
        if ( ! $missing ) { return array( 'items' => $results, 'message' => 'This package is already installed.' ); }
        $asset_urls = $media = array();
        foreach ( $package['manifest']['media'] as $binding ) {
            $installed = smup_portable_persist_image( $binding, $package['data'][$binding['path']], $created_posts, $created_files );
            $media[] = $installed;
            $asset_urls[$binding['path']] = $installed['url'];
        }
        foreach ( $package['manifest']['files'] as $path => $entry ) {
            if ( 'font' === $entry['kind'] ) {
                $asset_urls[$path] = smup_portable_store_font( $path, $package['data'][$path], $created_files );
            }
            if ( 'image' === $entry['kind'] && ! isset( $asset_urls[$path] ) ) {
                $installed = smup_portable_persist_image( array( 'path' => $path, 'alt' => '' ),
                    $package['data'][$path], $created_posts, $created_files );
                $asset_urls[$path] = $installed['url'];
            }
        }
        $media = smup_portable_index_media( $media );
        $patterns = array();
        foreach ( $missing as $item ) {
            $local_id = $item['id'];
            $requirements = $references = array();
            $count = 0;
            $blocks = smup_portable_remap_blocks( $plan[$local_id]['blocks'], $media, $requirements, $references, $count );
            $content = serialize_blocks( $blocks );
            $is_page = 'page' === $item['kind'];
            $id = wp_insert_post( wp_slash( array(
                'post_type' => $is_page ? 'page' : 'wp_block', 'post_status' => 'draft',
                'post_title' => $item['title'], 'post_content' => $content, 'post_name' => $item['slug'],
            ) ), true );
            if ( is_wp_error( $id ) || ! $id ) { throw new RuntimeException( 'WordPress could not save portable content.' ); }
            $created_posts[] = (int) $id;
            $saved = get_post( $id );
            if ( ! $saved || 'draft' !== $saved->post_status || $saved->post_content !== $content ||
                ! add_post_meta( $id, '_sm_portable_identity', $plan[$local_id]['identity'], true ) ) {
                throw new RuntimeException( 'WordPress changed a staged portable record or could not save its identity.' );
            }
            if ( ! $is_page ) {
                if ( ! add_post_meta( $id, 'wp_pattern_sync_status', 'unsynced', true ) ) {
                    throw new RuntimeException( 'Could not preserve the unsynced Pattern setting.' );
                }
                $patterns[] = (int) $id;
            }
            if ( 'production-v1' === $item['canvas'] && ( ! add_post_meta( $id, '_sm_portable_canvas', 'production-v1', true ) ||
                ! add_post_meta( $id, '_wp_page_template', 'syndamorph//production-canvas', true ) ) ) {
                throw new RuntimeException( 'Could not save the portable canvas setting.' );
            }
            if ( null !== $item['style'] ) {
                $css = smup_portable_resolve_css( $package['data'][$item['style']], $asset_urls );
                if ( ! add_post_meta( $id, '_sm_portable_css', wp_slash( $css ), true ) ) {
                    throw new RuntimeException( 'Could not save the portable document stylesheet.' );
                }
            }
            $results[$local_id] = (int) $id;
        }
        foreach ( $patterns as $id ) {
            $changed = wp_update_post( array( 'ID' => $id, 'post_status' => 'publish' ), true );
            if ( is_wp_error( $changed ) || ! $changed || 'publish' !== get_post_status( $id ) ) {
                throw new RuntimeException( 'WordPress could not finish registering the portable Pattern.' );
            }
        }
        return array( 'items' => $results, 'message' => sprintf( 'Installed %d selected items.', count( $results ) ) );
    } catch ( Throwable $error ) {
        foreach ( array_reverse( $created_posts ) as $id ) { wp_delete_post( $id, true ); }
        foreach ( array_reverse( $created_files ) as $file ) {
            if ( is_file( $file ) ) { wp_delete_file( $file ); }
        }
        return smup_error( $error instanceof RuntimeException ? $error->getMessage() : 'Could not install portable content.' );
    } finally {
        delete_option( '_sm_portable_install_lock' );
    }
}

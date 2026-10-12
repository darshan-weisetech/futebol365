<?php
/**
 * Migration module: Newsstand — Publishers and Publication Editions.
 *
 * Django tables: newsstand_publisher, newsstand_publicationedition
 * WordPress Taxonomies: f365_publisher
 * WordPress CPTs: f365_newsstand
 *
 * @package Futebol365
 * @since   2.0.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
    return;
}

trait F365_Module_Newsstand {

    protected function import_publishers(): void {
        $rows   = $this->pg_all( 'SELECT * FROM newsstand_publisher ORDER BY "order" NULLS LAST, id' );
        $n = $skip = $fail = 0;

        foreach ( $rows as $r ) {
            if ( $n >= $this->limit ) break;
            if ( ! $this->force && $this->already_imported( 'f365_publisher', $r['id'] ) ) {
                $skip++;
                $this->log( 'newsstand', "SKIP publisher_id={$r['id']}" );
                continue;
            }

            $term_id = $this->ensure_term( $r['name'] ?? '', 'f365_publisher', $r['slug'] ?? '' );
            if ( ! $term_id ) {
                $fail++;
                $this->log( 'newsstand', "FAIL publisher_id={$r['id']}" );
                continue;
            }

            update_term_meta( $term_id, 'publisher_url', $r['publisher_url'] ?? '' );
            update_term_meta( $term_id, 'is_visible', in_array( strtolower( (string) ( $r['is_visible'] ?? false ) ), [ '1', 't', 'true', 'on', 'yes' ], true ) ? 1 : 0 );
            update_term_meta( $term_id, 'external_id', $r['external_id'] ?? '' );
            update_term_meta( $term_id, 'order', (int) ( $r['order'] ?? 0 ) );
            update_term_meta( $term_id, 'django_publisher_id', (int) $r['id'] );

            $this->map_set( 'f365_publisher', $r['id'], $term_id );
            $this->log( 'newsstand', "OK publisher_id={$r['id']} → term_id=$term_id ({$r['name']})" );
            $n++;
        }

        $this->map_save( 'f365_publisher' );
        $this->log( 'newsstand', "SUMMARY: imported=$n skipped=$skip failed=$fail" );
        WP_CLI::success( "Publishers: $n imported, $skip skipped, $fail failed." );
    }

    protected function import_newsstand(): void {
        $pub_map = $this->map_get( 'f365_publisher' );
        $total   = (int) $this->pg_val( 'SELECT COUNT(*) FROM newsstand_publicationedition' );
        $effective = min( $total, $this->limit );

        WP_CLI::log( "Newsstand editions total: $total | effective: $effective | batch: {$this->batch}" );
        $progress = WP_CLI\Utils\make_progress_bar( 'Newsstand Editions', $effective );
        $n = $skip = $fail = 0;
        $offset = $this->offset;

        while ( $offset < $total && $n < $this->limit ) {
            $rows = $this->pg_all( '
                SELECT pe.*, p.name AS pub_name, p.slug AS pub_slug
                FROM newsstand_publicationedition pe
                JOIN newsstand_publisher p ON p.id = pe.publisher_id
                ORDER BY pe.published_at DESC
                LIMIT $1 OFFSET $2
            ', [ $this->batch, $offset ] );

            foreach ( $rows as $r ) {
                if ( $n >= $this->limit ) break;
                $progress->tick();

                if ( ! $this->force && $this->already_imported( 'f365_newsstand', $r['id'] ) ) {
                    $skip++;
                    $this->log( 'newsstand', "SKIP edition_id={$r['id']}" );
                    continue;
                }

                $pub_date = $r['published_at']
                    ? date( 'Y-m-d H:i:s', strtotime( $r['published_at'] ) )
                    : '';

                $title = sprintf( '%s — %s',
                    $r['pub_name'] ?? 'Publisher',
                    $pub_date ? date( 'd/m/Y', strtotime( $pub_date ) ) : $r['id']
                );

                $pub_slug = $r['pub_slug'] ?: sanitize_title( $r['pub_name'] ?? 'edition' );
                $date_slug = $r['published_at'] ? date( 'd-m-Y', strtotime( $r['published_at'] ) ) : $r['id'];
                $post_slug = "{$pub_slug}-{$date_slug}";

                $wp_id = $this->insert_post( [
                    'post_type'   => 'f365_newsstand',
                    'post_title'  => $title,
                    'post_name'   => $post_slug,
                    'post_status' => 'publish',
                    'post_date'   => $pub_date,
                ] );
                if ( ! $wp_id ) {
                    $fail++;
                    $this->log( 'newsstand', "FAIL edition_id={$r['id']}" );
                    continue;
                }

                $pub_term_id = $pub_map[ (string)(int) $r['publisher_id'] ] ?? null;

                if ( $pub_term_id ) {
                    wp_set_object_terms( $wp_id, [ (int) $pub_term_id ], 'f365_publisher', false );
                }

                $this->set_meta( $wp_id, [
                    'is_last_edition'     => in_array( strtolower( (string) ( $r['is_last_edition'] ?? false ) ), [ '1', 't', 'true', 'on', 'yes' ], true ) ? 1 : 0,
                    'django_newsstand_id' => (int) $r['id'],
                    'django_publisher_id' => (int) ( $r['publisher_id'] ?? 0 ),
                ] );

                // Sideload cover image → featured image
                $cover_path = $r['cover_file'] ?? '';
                if ( $cover_path && ! $this->dry_run ) {
                    $att_id = $this->sideload_image( $this->media_url( $cover_path ), $wp_id, $title );
                    if ( $att_id ) {
                        set_post_thumbnail( $wp_id, $att_id );
                    } else {
                        $this->log( 'newsstand', "IMAGE_FAIL edition_id={$r['id']} cover=$cover_path" );
                    }
                }

                $this->map_set( 'f365_newsstand', $r['id'], $wp_id );

                $publisher_name = $r['pub_name'] ?? '';
                $pub_date = $r['published_at'] ? date( 'd-m-Y', strtotime( $r['published_at'] ) ) : '';

                $seo_title = "Banca de Jornais - {$publisher_name} - {$pub_date}";
                $seo_desc = "Veja a capa da publicação {$publisher_name} de {$pub_date}.";

                update_post_meta( $wp_id, '_yoast_wpseo_title', $seo_title );
                update_post_meta( $wp_id, '_yoast_wpseo_metadesc', $seo_desc );

                $this->log( 'newsstand', "OK edition_id={$r['id']} → wp_id=$wp_id" );
                $n++;
            }

            $this->map_save( 'f365_newsstand' );
            $offset += $this->batch;
        }

        $progress->finish();
        $this->log( 'newsstand', "SUMMARY: imported=$n skipped=$skip failed=$fail" );
        WP_CLI::success( "Newsstand editions: $n imported, $skip skipped, $fail failed." );
    }
}

<?php
/**
 * News routing + list & single post SEO.
 *
 * Category and tag pages are served by WordPress's NATIVE taxonomy archives
 * (category base = noticias/categoria, tag base = noticias/tag), so they need
 * NO custom rewrite rules — the template hierarchy loads category.php / tag.php
 * and get_queried_object() gives the term. Only the listing CONTENT is read
 * from the custom tables (no WP term relationships).
 *
 * Custom rewrite rules remain only for routes that are NOT WP archives: the
 * fixed-slug listings, search, the canonical detail, and the legacy id redirect.
 *
 * @package Futebol365
 */

defined( 'ABSPATH' ) || exit;


/* ===========================================================================
 * 1. Rewrite rules — only for non-WP-archive routes.
 * =========================================================================== */
add_action( 'init', 'f365_news_rewrite_rules', 20 );
function f365_news_rewrite_rules(): void {

    // Legacy numeric-id redirect  /noticia/{id}/  → handled in template_redirect.
    add_rewrite_rule( '^noticia/([0-9]+)/?$',
        'index.php?f365_news_legacy_id=$matches[1]', 'top' );

    // AMP detail  /artigo/amp/{slug}/  (register BEFORE the canonical rule).
    add_rewrite_rule( '^artigo/amp/([^/]+)/?$',
        'index.php?post_type=post&name=$matches[1]&f365_news_amp=1', 'top' );

    // Canonical detail  /artigo/{slug}/
    add_rewrite_rule( '^artigo/([^/]+)/?$',
        'index.php?post_type=post&name=$matches[1]', 'top' );

    // Search  /noticias/pesquisa/  (reads ?q= from the request).
    add_rewrite_rule( '^noticias/pesquisa/?$',
        'index.php?f365_news_route=search', 'top' );

    // Fixed-slug listings (NOT WP archives).
    add_rewrite_rule( '^noticias/destaques/?$',       'index.php?f365_news_route=destaques', 'top' );
    add_rewrite_rule( '^noticias/com-video/?$',       'index.php?f365_news_route=com-video', 'top' );
    add_rewrite_rule( '^noticias/mais-visitadas/?$',  'index.php?f365_news_route=mais-visitadas', 'top' );
    add_rewrite_rule( '^noticias/mais-comentadas/?$', 'index.php?f365_news_route=mais-comentadas', 'top' );
    add_rewrite_rule( '^transferencias/noticias/?$',  'index.php?f365_news_route=transferencias', 'top' );
}


/* ===========================================================================
 * 2. Query vars — only what the remaining routes use.
 * =========================================================================== */
add_filter( 'query_vars', function ( array $vars ): array {
    foreach ( [ 'f365_news_route', 'f365_news_legacy_id', 'f365_news_amp' ] as $v ) {
        if ( ! in_array( $v, $vars, true ) ) {
            $vars[] = $v;
        }
    }
    return $vars;
} );


/* ===========================================================================
 * 3. Template routing for the fixed-slug listings (extend the map as needed).
 * =========================================================================== */
add_filter( 'template_include', 'f365_news_template_include' );
function f365_news_template_include( string $template ): string {
    $route = (string) get_query_var( 'f365_news_route' );
    if ( $route === '' ) {
        return $template;
    }

    $map = [
        'mais-visitadas' => 'page-mais-visitadas.php',
        // 'recentes'     => 'home.php',
        'destaques'    => 'page-destaques.php',
        // 'com-video'    => 'page-com-video.php',
        // 'transferencias' => 'page-transferencias.php',
        // 'search'       => 'search-news.php',
    ];

    if ( isset( $map[ $route ] ) ) {
        $found = locate_template( $map[ $route ] );
        if ( $found ) {
            return $found;
        }
    }
    return $template;
}


/* ===========================================================================
 * 4. Legacy id redirect  /noticia/{id}/ → 301 → canonical permalink.
 * =========================================================================== */
add_action( 'template_redirect', 'f365_news_legacy_redirect' );
function f365_news_legacy_redirect(): void {
    $legacy_id = (int) get_query_var( 'f365_news_legacy_id' );
    if ( $legacy_id <= 0 ) {
        return;
    }
    $post_id = function_exists( 'f365_news_post_id_from_legacy' ) ? f365_news_post_id_from_legacy( $legacy_id ) : 0;
    if ( $post_id > 0 ) {
        wp_safe_redirect( get_permalink( $post_id ), 301 );
        exit;
    }
    status_header( 404 );
}

add_action( 'pre_get_posts', 'f365_force_publish_date_order', 999 );

function f365_force_publish_date_order( $query ) {

    if (
        is_admin() ||
        ! $query->is_main_query()
    ) {
        return;
    }

    $post_type = $query->get( 'post_type' );

    if (
        empty( $post_type ) ||
        'post' === $post_type ||
        ( is_array( $post_type ) && in_array( 'post', $post_type, true ) )
    ) {
        $query->set( 'orderby', 'date' );
        $query->set( 'order', 'DESC' );
        $query->set( 'posts_per_page', 18 );
    }
}


/* ===========================================================================
 * 5. News SEO context (Handles Lists, Archives, and Single Articles).
 * =========================================================================== */
function f365_news_seo_context(): ?array {
    static $memo = null;
    if ( $memo !== null ) {
        return $memo === false ? null : $memo;
    }

    $route         = '';
    $category_name = '';

    // Handle single post display context
    if ( is_single() && get_post_type() === 'post' ) {
        $post = get_queried_object();
        if ( $post instanceof WP_Post ) {
            $memo = [
                'route'         => 'single',
                'category_name' => '',
                'post'          => $post
            ];
            return $memo;
        }
    }

    if ( is_category() ) {
        $term = get_queried_object();
        if ( $term instanceof WP_Term ) {
            $route         = 'category';
            $category_name = $term->name;
            if ( function_exists( 'f365_news_category_by_slug' ) ) {
                $cat = f365_news_category_by_slug( $term->slug );
                if ( ! empty( $cat['name'] ) ) {
                    $category_name = $cat['name'];
                }
            }
        }
    } elseif ( is_tag() ) {
        $term = get_queried_object();
        if ( $term instanceof WP_Term ) {
            $route         = 'tag';
            $category_name = $term->name;
            if ( function_exists( 'f365_news_tag_by_slug' ) ) {
                $tag = f365_news_tag_by_slug( $term->slug );
                if ( ! empty( $tag['name'] ) ) {
                    $category_name = $tag['name'];
                }
            }
        }
    } else {
        $route = (string) get_query_var( 'f365_news_route' );
        $fixed = [
            'recentes'        => 'Mais recentes',
            'destaques'       => 'Destaques',
            'com-video'       => 'Com vídeo',
            'mais-visitadas'  => 'Mais vistas',
            'mais-comentadas' => 'Mais comentadas',
            'transferencias'  => 'Mercado',
        ];
        $category_name = $fixed[ $route ] ?? '';
        if ( ! isset( $fixed[ $route ] ) ) {
            $route = ''; // search and unknown routes have no list SEO override
        }
    }

    if ( $route === '' ) {
        $memo = false;
        return null;
    }

    $memo = [ 'route' => $route, 'category_name' => $category_name, 'post' => null ];
    return $memo;
}


/** capfirst: uppercase only the first character (mb-safe), like Django capfirst. */
function f365_capfirst( string $s ): string {
    if ( $s === '' ) {
        return '';
    }
    return mb_strtoupper( mb_substr( $s, 0, 1 ) ) . mb_substr( $s, 1 );
}


/** Source title core (without the " - Futebol 365" suffix). */
function f365_news_list_seo_title_core( string $category_name ): string {
    return 'Notícias do Futebol ao Minuto - ' . f365_capfirst( $category_name );
}


/** Source meta description map. */
function f365_news_list_seo_description( array $ctx ): string {
    global $wpdb;

    $route = $ctx['route'] ?? '';
    $category_name = $ctx['category_name'] ?? '';

    // Route-specific configurations for individual Single news details
    if ( $route === 'single' && ! empty( $ctx['post'] ) ) {
        $post = $ctx['post'];
        $summary = '';

        // 1. Check native WP post excerpt first
        if ( ! empty( $post->post_excerpt ) ) {
            $summary = $post->post_excerpt;
        }

        // 2. FALLBACK: Fetch data directly from the publish_news custom table using the post slug
        if ( empty( trim( wp_strip_all_tags( $summary ) ) ) ) {
            // NOTE: If your database table does NOT use the standard WordPress prefix, 
            // change "{$wpdb->prefix}publish_news" to "publish_news"
            $db_row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT summary, description FROM {$wpdb->prefix}publish_news WHERE slug = %s LIMIT 1",
                    $post->post_name
                ),
                ARRAY_A
            );

            if ( ! empty( $db_row ) ) {
                $summary = ! empty( $db_row['summary'] ) ? $db_row['summary'] : $db_row['description'];
            }
        }

        // Render verified output snippet up to 155 chars
        if ( ! empty( $summary ) ) {
            return $summary;
        }
        
        return wp_html_excerpt( wp_strip_all_tags( $post->post_title ), 150 ) . ' - Acompanhe a atualidade desportiva no Futebol 365.';
    }

    if ( $category_name !== '' && $category_name !== 'Mais recentes' ) {
        return 'Notícias sobre ' . mb_strtolower( $category_name )
            . ' com resultados, análises, mercado e antevisões. Acompanhe a atualidade e os destaques diários no Futebol 365.';
    }
    return 'Notícias de futebol com resultados, análises, mercado e antevisões. Acompanhe a atualidade nacional e internacional no Futebol 365.';
}

/* ===========================================================================
 * 6. Yoast integration (priority 99).
 * =========================================================================== */
function f365_news_list_wpseo_title( string $title ): string {
    $ctx = f365_news_seo_context();
    if ( $ctx === null || $ctx['route'] === 'single' ) {
        return $title; // Let single posts fallback normally to standard title formats configured in Yoast settings
    }
    return f365_news_list_seo_title_core( $ctx['category_name'] ) . ' - Futebol 365';
}
add_filter( 'wpseo_title', 'f365_news_list_wpseo_title', 99 );
add_filter( 'wpseo_opengraph_title', 'f365_news_list_wpseo_title', 20 );

function f365_news_list_wpseo_metadesc( string $desc ): string {
    $ctx = f365_news_seo_context();
    if ( $ctx === null ) {
        return $desc;
    }
    return f365_news_list_seo_description( $ctx );
}
add_filter( 'wpseo_metadesc', 'f365_news_list_wpseo_metadesc', 99 );
add_filter( 'wpseo_opengraph_desc', 'f365_news_list_wpseo_metadesc', 20 );

/* ===========================================================================
 * 7. Canonical + og:url (self-referencing) for the non-archive news routes.
 *
 * Category/tag (native WP archives) and single articles already get correct
 * canonicals from Yoast/permalinks, so they pass through untouched. The
 * fixed-slug listings and search are virtual routes with no native queried
 * object, so Yoast cannot canonicalize them — we build their URLs here. AMP
 * detail pages canonicalize to the non-AMP article.
 *
 * Priority 40 runs after the generic section-append (20) and the pagination
 * canonical (30); since those archive helpers don't apply to these virtual
 * routes, this filter also appends ?pagina=N itself when the listing paginates.
 * =========================================================================== */
function f365_news_wpseo_canonical( $canonical ) {

    // AMP detail → canonical points to the non-AMP article.
    if ( (int) get_query_var( 'f365_news_amp' ) === 1 ) {
        $post = get_queried_object();
        if ( $post instanceof WP_Post && $post->post_type === 'post' ) {
            return user_trailingslashit( home_url( 'artigo/' . $post->post_name ) );
        }
    }

    $route = (string) get_query_var( 'f365_news_route' );

    $paths = [
        'destaques'       => 'noticias/destaques',
        'recentes'        => 'noticias/recentes',
        'com-video'       => 'noticias/com-video',
        'mais-visitadas'  => 'noticias/mais-visitadas',
        'mais-comentadas' => 'noticias/mais-comentadas',
        'transferencias'  => 'transferencias/noticias',
        'search'          => 'noticias/pesquisa',
    ];

    if ( ! isset( $paths[ $route ] ) ) {
        return $canonical; // single / category / tag → Yoast native
    }

    $url = user_trailingslashit( home_url( $paths[ $route ] ) );

    // Not WP archives → the generic ?pagina canonical does not run here.
    $pagina = isset( $_GET['pagina'] ) ? max( 1, intval( $_GET['pagina'] ) ) : 1;
    if ( $pagina >= 2 ) {
        $url = add_query_arg( 'pagina', $pagina, $url );
    }

    return $url;
}
add_filter( 'wpseo_canonical', 'f365_news_wpseo_canonical', 40 );
add_filter( 'wpseo_opengraph_url', 'f365_news_wpseo_canonical', 40 );


add_filter( 'wpseo_breadcrumb_links', 'f365_home_breadcrumb' );

function f365_home_breadcrumb( $links ) {


	if ( function_exists( 'f365_ranking_current_section' ) ) {
		$section = f365_ranking_current_section();
		if ( $section !== '' ) {

			$leaf = array(
				'national' => __( 'Ranking Seleções', 'futebol365' ),
				'club'     => __( 'Ranking Clubes', 'futebol365' ),
				'player'   => __( 'Ranking Jogadores', 'futebol365' ),
			);

			return array(
				array(
					'url'  => home_url( '/' ),
					'text' => __( 'Home', 'futebol365' ),
				),
				array(
					'url'  => '', // source: <a href="">Rankings</a>
					'text' => __( 'Rankings', 'futebol365' ),
				),
				array(
					'text' => $leaf[ $section ] ?? __( 'Rankings', 'futebol365' ),
				),
			);
		}
	}

    if ( is_home() ) {

        return array(
            array(
                'url'  => home_url( '/' ),
                'text' => __( 'Home', 'futebol365' ),
            ),
            array(
                'url'  => get_permalink( get_option( 'page_for_posts' ) ),
                'text' => __( 'Notícias', 'futebol365' ),
            ),
            array(
                'text' => get_the_title( get_option( 'page_for_posts' ) ),
            ),
        );
    }

    if ( is_category() || is_tag() ) {

        $term = get_queried_object();

        if (
            ! $term instanceof WP_Term ||
            ! in_array( $term->taxonomy, array( 'category', 'post_tag' ), true )
        ) {
            return $links;
        }

        return array(
            array(
                'url'  => home_url( '/' ),
                'text' => __( 'Home', 'futebol365' ),
            ),
            array(
                'url'  => get_permalink( get_option( 'page_for_posts' ) ),
                'text' => __( 'Notícias', 'futebol365' ),
            ),
            array(
                'text' => single_term_title( '', false ),
            ),
        );
    }

    return $links;
}
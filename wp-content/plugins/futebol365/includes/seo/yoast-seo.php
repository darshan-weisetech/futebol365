<?php
/**
 * Yoast SEO integration for all Futebol365 CPTs.
 *
 * Registers all custom post types with Yoast SEO so they:
 *   - appear in the SEO sitemap
 *   - display the SEO meta-box in the admin edit screen
 *   - inherit Yoast's schema and breadcrumb support
 *
 * Also provides helper functions for importing SEO data during migration:
 *   f365_set_yoast_seo( $post_id, $seo_title, $seo_description )
 *   f365_get_yoast_title( $post_id ) : string
 *   f365_get_yoast_desc( $post_id )  : string
 *
 * The same Yoast postmeta keys are written by ACF fields in each acf-*.php
 * file (_yoast_wpseo_title and _yoast_wpseo_metadesc), so data written by
 * the migration command is immediately visible in the SEO meta-box.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Tell Yoast SEO about all F365 CPTs so it indexes and sitemaps them.
 * This runs at priority 20 so our CPTs are already registered at priority 10.
 */
add_filter( 'wpseo_accessible_post_types', 'f365_yoast_accessible_post_types', 20 );
add_filter( 'wpseo_sitemap_post_types',    'f365_yoast_accessible_post_types', 20 );

function f365_yoast_accessible_post_types( array $post_types ): array {
    $f365_types = [
        'f365_team',
        'f365_player',
        'f365_coach',
        'f365_president',
        'f365_referee',
        'f365_match',
        'f365_stadium',
        'f365_transfer',
        'f365_video',
        'f365_gallery',
        'f365_newsstand',
        'f365_livestream',
        'f365_ranking',
    ];
    foreach ( $f365_types as $cpt ) {
        if ( ! isset( $post_types[ $cpt ] ) ) {
            $post_types[ $cpt ] = $cpt;
        }
    }
    return $post_types;
}

/**
 * Enable Yoast breadcrumb support for all F365 CPTs.
 */
add_filter( 'wpseo_breadcrumb_links', 'f365_yoast_breadcrumbs', 10, 1 );
function f365_yoast_breadcrumbs( array $crumbs ): array {
    $remove_post_types = [
        'f365_coach',
        'f365_president',
        'f365_referee',
        'f365_player'
    ];

    foreach ( $crumbs as $key => $crumb ) {
        if ( isset( $crumb['ptarchive'] ) && in_array( $crumb['ptarchive'], $remove_post_types, true ) ) {
            unset( $crumbs[ $key ] );
        }
    }
    return array_values( $crumbs );
}

/**
 * Register Yoast social tags for all CPTs (OpenGraph / Twitter Cards).
 * By returning the CPT in the accessible post types filter above,
 * Yoast automatically adds OG meta for those CPTs. No extra code needed.
 */

/**
 * Add Yoast SEO meta-box to all F365 CPTs.
 *
 * When Yoast is active and the CPT is in wpseo_accessible_post_types,
 * the meta-box appears automatically. This filter is a safety fallback
 * for the primary meta-box registration.
 */
add_filter( 'wpseo_metabox_prio', 'f365_yoast_metabox_priority' );
function f365_yoast_metabox_priority(): string {
    return 'default';
}

// ── Helper functions used by the migration command ──────────────────────────

/**
 * Write Yoast SEO postmeta for a post.
 *
 * @param int    $post_id         WP post ID.
 * @param string $seo_title       Raw SEO title (may contain Yoast replacement vars).
 * @param string $seo_description SEO meta description.
 * @param array  $extra           Optional extra Yoast keys (e.g. ['focuskw' => '...']).
 */
function f365_set_yoast_seo( int $post_id, string $seo_title, string $seo_description, array $extra = [] ): void {
    if ( ! $post_id ) return;

    if ( $seo_title ) {
        update_post_meta( $post_id, '_yoast_wpseo_title',    $seo_title );
    }
    if ( $seo_description ) {
        update_post_meta( $post_id, '_yoast_wpseo_metadesc', $seo_description );
    }
    foreach ( $extra as $key => $val ) {
        update_post_meta( $post_id, '_yoast_wpseo_' . ltrim( $key, '_' ), $val );
    }
}

/**
 * Read Yoast SEO title for a post.
 */
function f365_get_yoast_title( int $post_id ): string {
    return (string) get_post_meta( $post_id, '_yoast_wpseo_title', true );
}

/**
 * Read Yoast SEO meta description for a post.
 */
function f365_get_yoast_desc( int $post_id ): string {
    return (string) get_post_meta( $post_id, '_yoast_wpseo_metadesc', true );
}

/**
 * Mark a post as a Cornerstone Content page in Yoast (optional quality signal).
 */
function f365_set_yoast_cornerstone( int $post_id, bool $is_cornerstone = true ): void {
    update_post_meta( $post_id, '_yoast_wpseo_is_cornerstone', $is_cornerstone ? '1' : '' );
}

/**
 * Set the Yoast canonical URL for a post (overrides computed canonical).
 */
function f365_set_yoast_canonical( int $post_id, string $url ): void {
    if ( $url ) {
        update_post_meta( $post_id, '_yoast_wpseo_canonical', $url );
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// VIRTUAL POLL PAGES SEO OVERRIDES
// ─────────────────────────────────────────────────────────────────────────────

add_filter( 'wpseo_title', 'f365_poll_seo_title' );
function f365_poll_seo_title( $title ) {
    $poll_id = get_query_var( 'f365_poll_id' );
    if ( ! empty( $poll_id ) ) {
        global $wpdb;
        $polls_table = $wpdb->prefix . 'ayspoll_polls';
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$polls_table}'" ) == $polls_table ) {
            $poll_title = $wpdb->get_var( $wpdb->prepare( "SELECT title FROM {$polls_table} WHERE id = %d", $poll_id ) );
            if ( $poll_title ) {
                // Return the poll title, appending the site name like Yoast typically does
                return $poll_title . ' - ' . get_bloginfo( 'name' );
            }
        }
    }
    return $title;
}

add_filter( 'wpseo_metadesc', 'f365_poll_seo_metadesc' );
function f365_poll_seo_metadesc( $desc ) {
    $poll_id = get_query_var( 'f365_poll_id' );
    if ( ! empty( $poll_id ) ) {
        return 'Dê a sua opinião sobre a nossa sondagem.';
    }
    return $desc;
}

/**
 * Override Yoast canonical URL for custom CPT sub-sections so that they are indexed individually.
 */
add_filter( 'wpseo_canonical', 'f365_yoast_canonical_override', 20, 1 );
function f365_yoast_canonical_override( string $canonical ): string {
    $section = get_query_var( 'f365_section' );
    if ( $section && $canonical ) {
        $canonical = user_trailingslashit( trailingslashit( $canonical ) . $section );
    }
    return $canonical;
}

/**
 * Dynamic Yoast SEO title for the Agenda / Calendário de Jogos page
 * (template-parts/template-agenda.php).
 *
 * Source:
 *   {% block title %}Calendário de Jogos - {{ scheduled_date|date:'l, j \d\e F \d\e Y' }}{% endblock %}
 *
 * scheduled_date mirrors the agenda template exactly: the validated
 * ?schedule_date_input=Y-m-d query arg, defaulting to today. The date is
 * formatted with wp_date() (timezone + locale aware) so the title matches the
 * date headers the page itself renders.
 *
 * Registered plugin-side at load time, priority 99 (consistent with the other
 * page-level F365 SEO titles).
 */

/* ---------------------------------------------------------------------------
 * Scheduled-games pages (Agenda / Calendário de Jogos and Probabilidades).
 * Both share the same date selection and "l, j \d\e F \d\e Y" formatting,
 * so the logic lives in two shared helpers.
 * ------------------------------------------------------------------------- */

if ( ! function_exists( 'f365_seo_selected_schedule_date' ) ) {
    /**
     * Selected date for the scheduled-games pages: validated ?schedule_date_input,
     * else today. Mirrors template-agenda.php / f365_probabilities_selected_date().
     *
     * @return string Y-m-d
     */
    function f365_seo_selected_schedule_date() {
        $raw = isset( $_GET['schedule_date_input'] ) ? wp_unslash( $_GET['schedule_date_input'] ) : '';
        if ( is_string( $raw ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) ) {
            $d = DateTime::createFromFormat( 'Y-m-d', $raw );
            if ( $d && $d->format( 'Y-m-d' ) === $raw ) {
                return sanitize_text_field( $raw );
            }
        }
        return current_time( 'Y-m-d' );
    }
}

if ( ! function_exists( 'f365_seo_long_date' ) ) {
    /**
     * Format a Y-m-d date as the source "l, j \d\e F \d\e Y", e.g.
     * "Saturday, 20 de Junho de 2026". The month name is forced to Portuguese
     * (the site locale is en_US, so wp_date()'s F would render English); the
     * weekday keeps wp_date()'s locale output, matching the agenda page.
     *
     * @param string $selected_date Y-m-d
     * @return string
     */
    function f365_seo_long_date( $selected_date ) {
        $dt = date_create_immutable( $selected_date . ' 12:00:00', wp_timezone() );
        $ts = $dt ? $dt->getTimestamp() : time();

        $months_pt = array(
            '01' => 'Janeiro',
            '02' => 'Fevereiro',
            '03' => 'Março',
            '04' => 'Abril',
            '05' => 'Maio',
            '06' => 'Junho',
            '07' => 'Julho',
            '08' => 'Agosto',
            '09' => 'Setembro',
            '10' => 'Outubro',
            '11' => 'Novembro',
            '12' => 'Dezembro',
        );

        return sprintf(
            '%s, %s de %s de %s',
            wp_date( 'l', $ts ),                 // weekday (locale, e.g. Saturday)
            wp_date( 'j', $ts ),                 // day, no leading zero
            $months_pt[ wp_date( 'm', $ts ) ],   // month forced to Portuguese
            wp_date( 'Y', $ts )                  // year
        );
    }
}


/* ----- Agenda / Calendário de Jogos (template-agenda.php) ------------------ */
if ( ! function_exists( 'f365_agenda_wpseo_title' ) ) {
    add_filter( 'wpseo_title', 'f365_agenda_wpseo_title', 99 );
    add_filter( 'wpseo_opengraph_title', 'f365_agenda_wpseo_title', 99 );

    /**
     * Source: {% block title %}Calendário de Jogos - {{ scheduled_date|date:'l, j \d\e F \d\e Y' }}{% endblock %}
     */
    function f365_agenda_wpseo_title( $title ) {
        $qid = get_queried_object_id();
        $tpl = $qid ? (string) get_page_template_slug( $qid ) : '';

        $is_agenda = ( '' !== $tpl && 'template-agenda.php' === basename( $tpl ) )
            || is_page( 'calendario-jogos' );

        if ( ! $is_agenda ) {
            return $title;
        }

        return 'Calendário de Jogos - ' . f365_seo_long_date( f365_seo_selected_schedule_date() ) . ' - Futebol 365';
    }
}


/* ----- Probabilidades (page-probabilidades.php) ---------------------------- */
if ( ! function_exists( 'f365_probabilidades_is_page' ) ) {
    /** Robust detection of the Probabilidades page (template basename or slug). */
    function f365_probabilidades_is_page() {
        $qid = get_queried_object_id();
        $tpl = $qid ? (string) get_page_template_slug( $qid ) : '';

        return ( '' !== $tpl && 'page-probabilidades.php' === basename( $tpl ) )
            || is_page( 'probabilidades' );
    }
}

if ( ! function_exists( 'f365_probabilidades_wpseo_title' ) ) {
    add_filter( 'wpseo_title', 'f365_probabilidades_wpseo_title', 99 );
    add_filter( 'wpseo_opengraph_title', 'f365_probabilidades_wpseo_title', 99 );

    /**
     * Source: {% block title %}Probabilidades - {{ scheduled_date|date:'l, j \d\e F \d\e Y' }}{% endblock %}
     */
    function f365_probabilidades_wpseo_title( $title ) {
        if ( ! f365_probabilidades_is_page() ) {
            return $title;
        }

        return 'Probabilidades - ' . f365_seo_long_date( f365_seo_selected_schedule_date() ). ' - Futebol 365';
    }
}

if ( ! function_exists( 'f365_probabilidades_wpseo_metadesc' ) ) {
    add_filter( 'wpseo_metadesc', 'f365_probabilidades_wpseo_metadesc', 99 );
    add_filter( 'wpseo_opengraph_desc', 'f365_competition_wpseo_metadesc', 99 );

    /**
     * Source: {% block description %}Todas as probabilidades dos jogos de futebol
     *         agendados para {{ scheduled_date|date:'l, j \d\e F \d\e Y' }}{% endblock %}
     */
    function f365_probabilidades_wpseo_metadesc( $desc ) {
        if ( ! f365_probabilidades_is_page() ) {
            return $desc;
        }

        return 'Todas as probabilidades dos jogos de futebol agendados para ' . f365_seo_long_date( f365_seo_selected_schedule_date() );
    }
}

/* ----- Search (/pesquisa/) ------------------------------------------------- */
if ( ! function_exists( 'f365_search_wpseo_title' ) ) {
    add_filter( 'wpseo_title', 'f365_search_wpseo_title', 99 );
    add_filter( 'wpseo_opengraph_title', 'f365_search_wpseo_title', 99 );

    /**
     * Search results page title: "Pesquisa - {site name}", e.g. "Pesquisa - Futebol 365".
     * The wpseo_title return is the final title, so the site name is included here.
     */
    function f365_search_wpseo_title( $title ) {
        if ( ! is_search() ) {
            return $title;
        }
        return 'Pesquisa - Futebol 365';
    }
}

if ( ! function_exists( 'f365_search_wpseo_metadesc' ) ) {
    add_filter( 'wpseo_metadesc', 'f365_search_wpseo_metadesc', 99 );
    add_filter( 'wpseo_opengraph_desc', 'f365_search_wpseo_metadesc', 99 );

    /**
     * Search results page meta description (site's general description).
     */
    function f365_search_wpseo_metadesc( $desc ) {
        if ( ! is_search() ) {
            return $desc;
        }
        return 'O Portal do Futebol Mundial. Tudo sobre os principais campeonatos e competições nacionais e internacionais de clubes e seleções.';
    }
}

/* ----- 404 (Not Found) ----------------------------------------------------- */
if ( ! function_exists( 'f365_404_wpseo_title' ) ) {
    add_filter( 'wpseo_title', 'f365_404_wpseo_title', 99 );
    add_filter( 'wpseo_opengraph_title', 'f365_404_wpseo_title', 99 );

    /**
     * 404 page title: "Página não encontrada | {site name}".
     * A 404 is not a post, so this cannot be set from the admin — it must be a
     * filter. The wpseo_title return is the final title, so the site name is
     * included here.
     */
    function f365_404_wpseo_title( $title ) {
        if ( ! is_404() ) {
            return $title;
        }
        return 'Página não encontrada | ' . 'Futebol 365';
    }
}

/* ----- Banca de Jornais — f365_publisher taxonomy archive ------------------ */
if ( ! function_exists( 'f365_publisher_term_name' ) ) {
    /** Current f365_publisher term name (e.g. "A Bola"), or '' when not on that archive. */
    function f365_publisher_term_name() {
        if ( ! is_tax( 'f365_publisher' ) ) {
            return '';
        }
        $term = get_queried_object();
        return ( $term instanceof WP_Term ) ? $term->name : '';
    }
}

if ( ! function_exists( 'f365_publisher_wpseo_title' ) ) {
    add_filter( 'wpseo_title', 'f365_publisher_wpseo_title', 99 );
    add_filter( 'wpseo_opengraph_title', 'f365_publisher_wpseo_title', 99 );

    /** Title: "Banca de Jornais - {publisher} - {site name}". */
    function f365_publisher_wpseo_title( $title ) {
        $name = f365_publisher_term_name();
        if ( '' === $name ) {
            return $title;
        }
        return 'Banca de Jornais - ' . $name . ' - ' . 'Futebol 365';
    }
}

if ( ! function_exists( 'f365_publisher_wpseo_metadesc' ) ) {
    add_filter( 'wpseo_metadesc', 'f365_publisher_wpseo_metadesc', 99 );
    add_filter( 'wpseo_opengraph_desc', 'f365_publisher_wpseo_metadesc', 99 );

    /** Description: "Consulte as capas diárias da publicação {publisher}.". */
    function f365_publisher_wpseo_metadesc( $desc ) {
        $name = f365_publisher_term_name();
        if ( '' === $name ) {
            return $desc;
        }
        return 'Consulte as capas diárias da publicação ' . $name . '.';
    }
}

/* ----- Banca de Jornais — f365_newsstand post-type archive ----------------- */
if ( ! function_exists( 'f365_newsstand_archive_wpseo_title' ) ) {
    add_filter( 'wpseo_title', 'f365_newsstand_archive_wpseo_title', 99 );
     add_filter( 'wpseo_opengraph_title', 'f365_newsstand_archive_wpseo_title', 99 );

    /** Title: "Banca de Jornais - {site name}". */
    function f365_newsstand_archive_wpseo_title( $title ) {
        if ( ! is_post_type_archive( 'f365_newsstand' ) ) {
            return $title;
        }
        return 'Banca de Jornais - ' .'Futebol 365' ;
    }
}

if ( ! function_exists( 'f365_newsstand_archive_wpseo_metadesc' ) ) {
    add_filter( 'wpseo_metadesc', 'f365_newsstand_archive_wpseo_metadesc', 99 );
    add_filter( 'wpseo_opengraph_desc', 'f365_newsstand_archive_wpseo_metadesc', 99 );

    /** Description for the Banca de Jornais archive. */
    function f365_newsstand_archive_wpseo_metadesc( $desc ) {
        if ( ! is_post_type_archive( 'f365_newsstand' ) ) {
            return $desc;
        }
        return 'Veja as capas dos principais jornais desportivos de hoje';
    }
}

if ( ! function_exists( 'f365_canonical_append_pagina' ) ) {
    add_filter( 'wpseo_canonical',     'f365_canonical_append_pagina', 30 );
    add_filter( 'wpseo_opengraph_url', 'f365_canonical_append_pagina', 30 );

    /**
     * Normalise listing pagination to the site's ?pagina=N form on the canonical
     * and og:url (post-type archives, taxonomy/category/tag/author/date archives,
     * the blog index/news routes and search).
     *
     * Once the main query's `paged` reflects ?pagina (see pre_get_posts), Yoast
     * builds its canonical/og:url in the /page/N/ permalink form, so we first
     * strip that segment (and any ?paged arg) back to the clean base, then append
     * ?pagina=N. Without the strip the two stack up as /page/N/?pagina=N. Page 1
     * (and singular views) get the clean base.
     */
    function f365_canonical_append_pagina( $canonical ) {
        if ( empty( $canonical ) ) {
            return $canonical;
        }

        // Only listing pages paginate with ?pagina=. is_archive() covers every
        // post-type archive and taxonomy/category/tag/author/date archive.
        $is_listing = is_archive() || is_home() || is_search();
        if ( ! $is_listing ) {
            return $canonical;
        }

        // Strip Yoast's /page/N/ (or ?paged=N) back to the clean unpaginated base.
        $canonical = preg_replace( '#/page/\d+/?#', '/', $canonical );
        $canonical = remove_query_arg( 'paged', $canonical );

        $pagina = isset( $_GET['pagina'] ) ? max( 1, intval( $_GET['pagina'] ) ) : 1;
        if ( $pagina < 2 ) {
            return $canonical; // page 1 → clean canonical
        }

        return add_query_arg( 'pagina', $pagina, $canonical );
    }
}

if ( ! function_exists( 'f365_adjacent_rel_use_pagina' ) ) {
    add_filter( 'wpseo_adjacent_rel_url', 'f365_adjacent_rel_use_pagina', 99, 2 );

    /**
     * Emit Yoast's rel="next" / rel="prev" in the site's ?pagina=N form for ANY
     * paginated listing (every post-type archive incl. the default `post` blog
     * index/news routes, every taxonomy/category/tag/author/date archive and
     * search). Uses Yoast's own filter — nothing is disabled.
     *
     * Rather than reformatting the URL Yoast built (whose page number/bounds come
     * from the main query, which several CPT archives deliberately minimize), we
     * recompute both ends from the real current page (?pagina) and the real total
     * (f365_archive_total_pages()). Page 1 is the clean base, matching the
     * canonical. Returning a value here makes the tag render even when Yoast's own
     * value was empty (Abstract_Indexable_Tag_Presenter::present() re-checks after
     * the filter), which is what restores rel=next on the minimized archives.
     *
     * @param string $url The adjacent URL Yoast generated (ignored; recomputed).
     * @param string $rel 'next' or 'prev'.
     * @return string The ?pagina URL, or '' when there is no adjacent page.
     */
    function f365_adjacent_rel_use_pagina( $url, $rel ) {
        if ( ! ( is_archive() || is_home() || is_search() ) ) {
            return $url; // not a ?pagina listing — leave Yoast's value untouched
        }

        $current = isset( $_GET['pagina'] )
            ? max( 1, (int) $_GET['pagina'] )
            : max( 1, (int) get_query_var( 'paged' ) );

        $base = f365_listing_base_url();

        if ( 'prev' === $rel ) {
            if ( $current <= 1 ) {
                return '';
            }
            return ( 2 === $current ) ? $base : add_query_arg( 'pagina', $current - 1, $base );
        }

        if ( 'next' === $rel ) {
            $total = f365_archive_total_pages();
            if ( $current >= $total ) {
                return '';
            }
            return add_query_arg( 'pagina', $current + 1, $base );
        }

        return $url;
    }
}

if ( ! function_exists( 'f365_archive_total_pages' ) ) {
    /**
     * Real number of pages for the current listing, used to bound rel=next.
     *
     * Most listings render from the main loop, so the main query's max_num_pages
     * is authoritative. A few CPT archives render from a secondary WP_Query while
     * their main query is minimized (no_found_rows) or uses a different per-page,
     * so the main query is NOT authoritative for them — those are recomputed to
     * match the template. Register new such archives here.
     */
    function f365_archive_total_pages(): int {
        if ( is_post_type_archive( 'f365_video' ) ) {
            return f365_video_archive_total_pages();
        }
        if ( is_post_type_archive( 'f365_gallery' ) ) {
            return f365_gallery_archive_total_pages();
        }

        global $wp_query;
        $total = ( $wp_query instanceof WP_Query ) ? (int) $wp_query->max_num_pages : 0;

        return max( 1, $total );
    }
}


if ( ! function_exists( 'f365_listing_base_url' ) ) {
    /**
     * The current listing URL reduced to its clean page-1 base: the configured
     * site scheme/host + the live request path with ?pagina and any /page/N/
     * segment stripped (subdirectory install path and other query args kept).
     */
    function f365_listing_base_url(): string {
        $home   = wp_parse_url( home_url( '/' ) );
        $scheme = $home['scheme'] ?? ( is_ssl() ? 'https' : 'http' );
        $host   = $home['host'] ?? ( isset( $_SERVER['HTTP_HOST'] ) ? wp_unslash( $_SERVER['HTTP_HOST'] ) : '' );
        $port   = isset( $home['port'] ) ? ':' . $home['port'] : '';

        $path = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
        $path = remove_query_arg( 'pagina', $path );
        $path = preg_replace( '#/page/\d+/?#', '/', $path );

        return esc_url_raw( $scheme . '://' . $host . $port . $path );
    }
}

if ( ! function_exists( 'f365_video_archive_total_pages' ) ) {
    /**
     * Real, capped number of pages for the f365_video archive / its sections.
     *
     * The archive's main query is minimized for performance (the template runs
     * its own WP_Query), so Yoast cannot derive the page count. We reproduce the
     * section-filtered count and 20-per-page caps from archive-f365_video.php.
     * KEEP IN SYNC with that template if its section logic changes. Cached per
     * section for a few minutes since it is a COUNT over a large table.
     */
    function f365_video_archive_total_pages(): int {

        static $memo = array();

        $section = (string) get_query_var( 'f365_section' );
        $skey    = $section ?: 'mais-recentes';

        if ( isset( $memo[ $skey ] ) ) {
            return $memo[ $skey ];
        }

        $tkey   = 'f365_vidarch_pages_' . $skey;
        $cached = wp_cache_get( $tkey, 'f365_videos' );

        if ( false !== $cached ) {
            $memo[ $skey ] = (int) $cached;
            return $memo[ $skey ];
        }

        switch ( $section ) {

            case 'destaques':
                $total = 5; // 100 videos / 20 per page
                break;

            case 'mais-vistos':
                $total = 5; // 100 videos / 20 per page
                break;

            case 'mais-recentes':
            default:
                $total = 20; // 400 videos / 20 per page
                break;
        }

        wp_cache_set(
            $tkey,
            $total,
            'f365_videos',
            10 * MINUTE_IN_SECONDS
        );

        $memo[ $skey ] = $total;

        return $total;
    }
}

if ( ! function_exists( 'f365_gallery_archive_total_pages' ) ) {
    /**
     * Real number of pages for the f365_gallery archive / its filtered views.
     *
     * The gallery archive renders from a secondary WP_Query at F365_PG_PER_PAGE
     * (20) per page, which differs from the main query's per-page, so the main
     * query's max_num_pages is not authoritative. We reproduce the filter and
     * per-page from archive-f365_gallery.php (latest / highlight / most-viewed;
     * no page cap). KEEP IN SYNC with that template if its filters change.
     * Cached per filter for a few minutes (COUNT over a large table).
     */
    function f365_gallery_archive_total_pages(): int {
        static $memo = array();

        $request_uri = isset( $_SERVER['REQUEST_URI'] )
            ? wp_unslash( $_SERVER['REQUEST_URI'] )
            : '';

        $filter = 'latest';

        if ( false !== strpos( $request_uri, '/destaques' ) ) {
            $filter = 'highlight';
        } elseif ( false !== strpos( $request_uri, '/mais-vistas' ) ) {
            $filter = 'most-viewed';
        }

        if ( isset( $_GET['filter'] ) ) {
            $req = sanitize_text_field( wp_unslash( $_GET['filter'] ) );

            if ( in_array( $req, array( 'latest', 'highlight', 'most-viewed' ), true ) ) {
                $filter = $req;
            }
        }

        if ( isset( $memo[ $filter ] ) ) {
            return $memo[ $filter ];
        }

        $tkey   = 'f365_gallery_pages_' . $filter;
        $cached = wp_cache_get( $tkey, 'f365_gallery' );

        if ( false !== $cached ) {
            $memo[ $filter ] = (int) $cached;
            return $memo[ $filter ];
        }

        switch ( $filter ) {

            case 'highlight':
                $total = 5; // 100 items / 20 per page
                break;

            case 'most-viewed':
                $total = 5; // 100 items / 20 per page
                break;

            case 'latest':
            default:
                $total = 20; // 400 items / 20 per page
                break;
        }

        wp_cache_set(
            $tkey,
            $total,
            'f365_gallery',
            10 * MINUTE_IN_SECONDS
        );

        $memo[ $filter ] = $total;

        return $total;
    }
}


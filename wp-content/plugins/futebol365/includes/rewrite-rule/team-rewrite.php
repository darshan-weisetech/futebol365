<?php
/**
 * Futebol365 — Team Rewrite Rules & Routing
 *
 * Follows the exact same architecture pattern as federation-rewrite.php:
 *   1. Rewrite rules
 *   2. Query vars
 *   3. pre_get_posts  (ID resolution)
 *   4. Resolve helper (django_id → WP post ID, static-cached)
 *   5. Permalinks     (post_type_link filter)
 *   6. URL helper     (f365_team_url)
 *   7. Compare URL helper
 *   8. template_include filter  (NO template_redirect)
 *   9. Yoast SEO     (wpseo_title + wpseo_metadesc filters)
 *
 * URL structure:
 *   /equipa/{id}/                              → redirects to /equipa/{id}/jogos/
 *   /equipa/{id}/jogos/                        → team matches
 *   /equipa/{id}/calendario/                   → team calendar
 *   /equipa/{id}/info/                         → team info
 *   /equipa/{id}/plantel/                      → team squad
 *   /equipa/{id}/transferencias/               → team transfers
 *   /equipa/{id}/palmares/                     → team awards
 *   /equipa/{id}/noticias/                     → team news
 *   /equipa/{id}/videos/                       → team videos
 *   /equipa/{id}/historico/                    → team history
 *   /equipa/{id}/comparar-com/{id2}/           → compare (summary)
 *   /equipa/{id}/comparar-com/{id2}/confrontos/→ compare – head to head
 *   /equipa/{id}/comparar-com/{id2}/forma/     → compare – form
 *   /equipa/{id}/comparar-com/{id2}/palmares/  → compare – honours
 *   /equipa/{id}/comparar-com/{id2}/estadios/  → compare – stadiums
 *
 * {id} = Django team.id stored in postmeta 'django_id'. Falls back to WP post ID.
 *
 * After any change run: wp rewrite flush --hard
 *
 * @package Futebol365
 * @since   2.2.1
 */

defined( 'ABSPATH' ) || exit;


/**
 * -------------------------------------------------------
 * 1. REWRITE RULES
 * -------------------------------------------------------
 */

function f365_team_rewrite_rules(): void {

    /**
     * Compare sub-sections must come before the base compare rule
     * and before the single-section rules (more specific → top).
     */
    $compare_sections = [
        'confrontos',
        'forma',
        'palmares',
        'estadios',
    ];

    foreach ( $compare_sections as $sub ) {
        add_rewrite_rule(
            '^equipa/([0-9]+)/comparar-com/([0-9]+)/' . $sub . '/?$',
            'index.php?post_type=f365_team&f365_team_id=$matches[1]&f365_team2_id=$matches[2]&f365_section=comparar-' . $sub,
            'top'
        );
    }

    // Base compare (no sub-section)
    add_rewrite_rule(
        '^equipa/([0-9]+)/comparar-com/([0-9]+)/?$',
        'index.php?post_type=f365_team&f365_team_id=$matches[1]&f365_team2_id=$matches[2]&f365_section=comparar',
        'top'
    );

    /**
     * Team sub-pages (single {id} + section slug).
     */
    $sections = [
        'jogos',
        'calendario',
        'info',
        'plantel',
        'transferencias',
        'palmares',
        'noticias',
        'videos',
        'historico',
    ];

    foreach ( $sections as $section ) {
        add_rewrite_rule(
            '^equipa/([0-9]+)/' . $section . '/?$',
            'index.php?post_type=f365_team&f365_team_id=$matches[1]&f365_section=' . $section,
            'top'
        );
    }

    /**
     * Base team URL — handled as a redirect inside template_include.
     * No separate 'redirect' sentinel value is needed; the absence of
     * a section triggers the redirect in f365_team_template_include().
     */
    add_rewrite_rule(
        '^equipa/([0-9]+)/?$',
        'index.php?post_type=f365_team&f365_team_id=$matches[1]',
        'top'
    );

}

add_action( 'init', 'f365_team_rewrite_rules', 20 );


/**
 * -------------------------------------------------------
 * 2. QUERY VARS
 *
 * f365_section is already registered centrally in
 * register-rewrite-rules.php → f365_register_query_vars().
 * We only register the team-specific vars here.
 * -------------------------------------------------------
 */

add_filter( 'query_vars', 'f365_team_query_vars' );

function f365_team_query_vars( array $vars ): array {

    $new = [
        'f365_team_id',    // Django team.id from URL segment
        'f365_team2_id',   // Second team for compare routes
        'f365_edition',    // Optional ?edition= filter
        'f365_step',       // Optional ?step= filter
        'f365_modality',   // Optional ?modality= filter
        'f365_gender',     // Optional ?gender= filter
    ];

    foreach ( $new as $var ) {
        if ( ! in_array( $var, $vars, true ) ) {
            $vars[] = $var;
        }
    }

    return $vars;
}


/**
 * -------------------------------------------------------
 * 3. PRE_GET_POSTS — resolve f365_team_id to WP post
 * -------------------------------------------------------
 */

add_action( 'pre_get_posts', 'f365_team_pre_get_posts' );

function f365_team_pre_get_posts( WP_Query $query ): void {

    if ( is_admin() || ! $query->is_main_query() ) {
        return;
    }

    $team_id = (int) $query->get( 'f365_team_id' );

    if ( ! $team_id ) {
        return;
    }

    $wp_post_id = f365_team_resolve_id( $team_id );

    if ( $wp_post_id ) {

        $query->set( 'p',         $wp_post_id );
        $query->set( 'post_type', 'f365_team' );

    } else {

        // Fallback: treat URL segment as a raw WP post ID
        $query->set( 'p',         $team_id );
        $query->set( 'post_type', 'f365_team' );
    }

    $query->set( 'name', '' );
      $query->is_single            = true;
    $query->is_singular          = true;
    $query->is_archive           = false;
    $query->is_post_type_archive = false;
}


/**
 * -------------------------------------------------------
 * 4. RESOLVE DJANGO ID → WP POST ID
 *
 * Mirrors f365_federation_resolve_id() exactly.
 * Uses 'django_id' postmeta key (set during migration).
 * Static cache prevents repeat DB hits within one request.
 * -------------------------------------------------------
 */

function f365_team_resolve_id( int $team_id ): ?int {

    static $cache = [];

    if ( isset( $cache[ $team_id ] ) ) {
        return $cache[ $team_id ];
    }

    global $wpdb;

    $wp_id = $wpdb->get_var(
        // $wpdb->prepare(
        //     "
        //     SELECT post_id
        //     FROM   {$wpdb->postmeta}
        //     WHERE  meta_key   = 'django_id'
        //     AND    meta_value = %d
        //     AND    post_id IN (
        //         SELECT ID FROM {$wpdb->posts}
        //         WHERE  post_type = 'f365_team'
        //         AND    post_status != 'trash'
        //     )
        //     LIMIT 1
        //     ",
        //     $team_id
        // )
        $wpdb->prepare(
            "
            SELECT ID
            FROM {$wpdb->posts}
            WHERE legacy_id = %d
            AND post_type = 'f365_team'
            AND post_status != 'trash'
            LIMIT 1
            ",
            $team_id
        )
    );

    $cache[ $team_id ] = $wp_id ? (int) $wp_id : null;

    return $cache[ $team_id ];
}


/**
 * -------------------------------------------------------
 * 5. PERMALINKS
 *
 * post_type_link filter: generate /equipa/{django_id}/
 * for f365_team posts, using django_id postmeta.
 * -------------------------------------------------------
 */

add_filter( 'post_type_link', 'f365_team_permalink', 10, 2 );

function f365_team_permalink( string $post_link, WP_Post $post ): string {

    if ( 'f365_team' !== $post->post_type ) {
        return $post_link;
    }

    $django_id = (int) get_post_meta( $post->ID, 'django_id', true );

    $final_id = $django_id ?: $post->ID;

    return user_trailingslashit(
        home_url( "equipa/{$final_id}/jogos" )
    );
}



/**
 * -------------------------------------------------------
 * 6. TEAM URL HELPER
 *
 * Mirrors f365_federation_url() exactly.
 *
 * Usage:
 *   f365_team_url( $post_id )               → /equipa/42/
 *   f365_team_url( $post_id, 'plantel' )    → /equipa/42/plantel/
 * -------------------------------------------------------
 */

function f365_team_url( int $post_id, string $section = '' ): string {

    $django_id = (int) get_post_meta( $post_id, 'django_id', true );

    $final_id = $django_id ?: $post_id;

    $path = "equipa/{$final_id}";

    if ( $section ) {
        $path .= '/' . trim( $section, '/' );
    }

    return user_trailingslashit(
        home_url( $path )
    );
}


/**
 * -------------------------------------------------------
 * 7. COMPARE URL HELPER
 *
 * Usage:
 *   f365_team_compare_url( $id1, $id2 )                 → /equipa/1/comparar-com/2/
 *   f365_team_compare_url( $id1, $id2, 'confrontos' )   → /equipa/1/comparar-com/2/confrontos/
 * -------------------------------------------------------
 */

function f365_team_compare_url( int $post_id_1, int $post_id_2, string $section = '' ): string {

    $id1 = (int) get_post_meta( $post_id_1, 'django_id', true ) ?: $post_id_1;
    $id2 = (int) get_post_meta( $post_id_2, 'django_id', true ) ?: $post_id_2;

    $path = "equipa/{$id1}/comparar-com/{$id2}";

    if ( $section ) {
        $path .= '/' . trim( $section, '/' );
    }

    return user_trailingslashit(
        home_url( $path )
    );
}


/**
 * Get the current team WP_Post from the active query.
 * Mirrors f365_get_current_federation_post().
 */
function f365_get_current_team_post(): ?WP_Post {

    $team_id = (int) get_query_var( 'f365_team_id' );

    if ( ! $team_id ) {
        return null;
    }

    global $post;

    if ( $post instanceof WP_Post && $post->post_type === 'f365_team' ) {
        return $post;
    }

    $wp_post_id = f365_team_resolve_id( $team_id ) ?? $team_id;

    return get_post( $wp_post_id ) ?: null;
}


/**
 * -------------------------------------------------------
 * 8. TEMPLATE LOADER  (template_include — NOT template_redirect)
 *
 * When no section is present (base /equipa/{id}/ URL),
 * we issue a 301 redirect here inside template_include,
 * which is the correct place per the federation pattern.
 *
 * Template name → file mapping:
 *   (none / base)    → 301 redirect to /equipa/{id}/jogos/
 *   jogos            → f365-team-jogos.php
 *   calendario       → f365-team-calendario.php
 *   info             → f365-team-info.php
 *   plantel          → f365-team-plantel.php
 *   transferencias   → f365-team-transferencias.php
 *   palmares         → f365-team-palmares.php
 *   noticias         → f365-team-noticias.php
 *   videos           → f365-team-videos.php
 *   historico        → f365-team-historico.php
 *   comparar         → f365-team-compare.php
 *   comparar-*       → f365-team-compare-{sub}.php
 *
 * f365_locate_template() is defined in federation-rewrite.php
 * (loaded before this file) and checks theme → plugin/templates/.
 * -------------------------------------------------------
 */

add_filter( 'template_include', 'f365_team_template_include' );

function f365_team_template_include( string $template ): string {

    $team_id = get_query_var( 'f365_team_id' );

    if ( ! $team_id ) {
        return $template;
    }

    $section = get_query_var( 'f365_section' );

    /**
     * Base URL (/equipa/{id}/) has no section.
     * Redirect to /equipa/{id}/jogos/ with a 301.
     * This is done inside template_include so we avoid the
     * extra template_redirect hook the old code required.
     */
    if ( ! $section ) {
        $target = user_trailingslashit( home_url( "equipa/{$team_id}/jogos" ) );

        /*
         * Mirror Django's redirect_for_team_matches(): carry the `edition`
         * filter across the redirect so /equipa/{id}/?edition=YYYY lands on
         * /equipa/{id}/jogos/?edition=YYYY instead of dropping the season.
         */
        $edition = isset( $_GET['edition'] ) ? sanitize_text_field( wp_unslash( $_GET['edition'] ) ) : '';
        if ( $edition !== '' ) {
            $target = add_query_arg( 'edition', $edition, $target );
        }

        wp_redirect( $target, 301 );
        exit;
    }

    /**
     * Section → template file map.
     * All compare variants share a predictable naming convention.
     */
    $map = [
        'jogos'              => 'f365-team-jogos',
        'calendario'         => 'f365-team-calendario',
        'info'               => 'f365-team-info',
        'plantel'            => 'f365-team-plantel',
        'transferencias'     => 'f365-team-transferencias',
        'palmares'           => 'f365-team-palmares',
        'noticias'           => 'f365-team-noticias',
        'videos'             => 'f365-team-videos',
        'historico'          => 'f365-team-historico',
        'comparar'           => 'f365-team-compare',
        'comparar-confrontos'=> 'f365-team-compare-confrontos',
        'comparar-forma'     => 'f365-team-compare-forma',
        'comparar-palmares'  => 'f365-team-compare-palmares',
        'comparar-estadios'  => 'f365-team-compare-estadios',
    ];

    $tpl_name = $map[ $section ] ?? 'f365-team-jogos';

    $found = f365_locate_team_template( $tpl_name );

    return $found ?: $template;
}

/**
 * -------------------------------------------------------
 * 7. TEMPLATE LOCATOR
 * -------------------------------------------------------
 */

function f365_locate_team_template( string $name ): ?string {

    $template_paths = [
        "template-parts/team/{$name}.php",
        "{$name}.php",
    ];

    /**
     * Theme override
     */
    $theme_file = locate_template(
        $template_paths,
        false,
        false
    );

    if ( $theme_file ) {
        return $theme_file;
    }

    /**
     * Plugin fallback
     */
    if ( defined( 'F365_PLUGIN_DIR' ) ) {

        $plugin_file = trailingslashit( F365_PLUGIN_DIR ) . "templates/team-template/{$name}.php";

        if ( file_exists( $plugin_file ) ) {
            return $plugin_file;
        }
    }

    return null;
}

/**
 * -------------------------------------------------------
 * 8d. SEO DATA SOURCE — shortname / longname from the cloned
 *     table {prefix}team_team (NOT wp_postmeta).
 *
 *     The {f365_team_id}/{f365_team2_id} query vars already carry the
 *     Django team.id, so the lookup is keyed on team_team.id directly.
 * -------------------------------------------------------
 */
if ( ! function_exists( 'f365_team_seo_data' ) ) {
    function f365_team_seo_data( int $team_django_id ): array {
        static $memo = [];

        if ( $team_django_id <= 0 ) {
            return [ 'shortname' => '', 'longname' => '' ];
        }
        if ( array_key_exists( $team_django_id, $memo ) ) {
            return $memo[ $team_django_id ];
        }

        $cache_key = 'f365_team_seo_' . $team_django_id;
        $cached    = wp_cache_get( $cache_key, 'f365' );
        if ( is_array( $cached ) ) {
            return $memo[ $team_django_id ] = $cached;
        }

        global $wpdb;
        $T_TEAM = $wpdb->prefix . 'team_team';

        if ( function_exists( 'f365_src_table_exists' ) && ! f365_src_table_exists( $T_TEAM ) ) {
            return $memo[ $team_django_id ] = [ 'shortname' => '', 'longname' => '' ];
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT shortname, longname FROM {$T_TEAM} WHERE id = %d LIMIT 1",
                $team_django_id
            ),
            ARRAY_A
        );

        // Do not cache empty/failed lookups.
        if ( $wpdb->last_error !== '' || ! is_array( $row ) ) {
            return $memo[ $team_django_id ] = [ 'shortname' => '', 'longname' => '' ];
        }

        $data = [
            'shortname' => isset( $row['shortname'] ) ? (string) $row['shortname'] : '',
            'longname'  => ( isset( $row['longname'] ) && $row['longname'] !== null ) ? (string) $row['longname'] : '',
        ];

        wp_cache_set( $cache_key, $data, 'f365', 12 * HOUR_IN_SECONDS );
        return $memo[ $team_django_id ] = $data;
    }
}

/**
 * -------------------------------------------------------
 * 9. YOAST SEO — wpseo_title
 *
 * Title format (mirrors federation-rewrite.php exactly):
 *   {shortname} - {longname} - {Section Label} - Futebol 365
 *
 * When no section label exists (base page or unknown section),
 * the section part is omitted:
 *   {shortname} - {longname} - Futebol 365
 * -------------------------------------------------------
 */
function f365_team_wpseo_title( string $title ): string {
    $team_id = get_query_var('f365_team_id');
    if (!$team_id) {
        return $title;
    }

    $post = f365_get_current_team_post();
    if (!$post || $post->post_type !== 'f365_team') {
        return $title;
    }

    $section = get_query_var('f365_section');

    $sd        = f365_team_seo_data((int) $team_id);
    $shortname = $sd['shortname'] ?: get_the_title($post->ID);
    $longname  = $sd['longname'];

    $is_compare = ($section && str_starts_with($section, 'comparar'));

    /**
     * -----------------------------
     * COMPARE SEO
     * -----------------------------
     */
    if ($is_compare) {

        $team2_id = get_query_var('f365_team2_id');

        $t2_post_id = f365_team_resolve_id((int)$team2_id);
        $t2_post = $t2_post_id ? get_post($t2_post_id) : null;

        $name2 = f365_team_seo_data((int) $team2_id)['shortname']
            ?: ($t2_post ? get_the_title($t2_post->ID) : $team2_id);

        $section_labels = [
            'comparar-confrontos' => 'Confrontos',
            'comparar-forma'      => 'Forma',
            'comparar-palmares'   => 'Palmarés',
            'comparar-estadios'   => 'Estádios',
            'comparar'            => 'Resumo histórico',
        ];

        $label = $section_labels[$section] ?? 'Comparação';

        return "{$shortname} vs {$name2} - {$label} - Futebol 365";
    }

    /**
     * -----------------------------
     * NORMAL TEAM SEO
     * -----------------------------
     */

    $section_labels = [
        'jogos'              => 'Jogos',
        'calendario'         => 'Calendário',
        'info'               => 'Dados Gerais',
        'plantel'            => 'Plantel',
        'transferencias'     => 'Transferências',
        'palmares'           => 'Palmarés',
        'noticias'           => 'Notícias',
        'videos'             => 'Vídeos',
        'historico'          => 'Histórico',
    ];

    $parts = [];
    $parts[] = $shortname;

    if ($longname) {
        $parts[] = $longname;
    }

    if (!empty($section) && isset($section_labels[$section])) {
        $parts[] = $section_labels[$section];
    }

    $parts[] = 'Futebol 365';

    return implode(' - ', $parts);

}
add_filter('wpseo_title', 'f365_team_wpseo_title', 20);
add_filter( 'wpseo_opengraph_title', 'f365_team_wpseo_title', 20 );

/**
 * -------------------------------------------------------
 * 9b. YOAST SEO — wpseo_metadesc
 *
 * Description format (max 145 chars):
 *   Toda a informação sobre {longname}. Jogos, jogadores,
 *   estatísticas, transferências, palmarés, notícias,
 *   vídeos e muito mais!
 *
 * Mirrors f365_dynamic_federation_description() exactly.
 * -------------------------------------------------------
 */
function f365_team_wpseo_metadesc( string $desc ): string {
    $team_id = get_query_var('f365_team_id');
    if (!$team_id) {
        return $desc;
    }

    $post = f365_get_current_team_post();
    if (!$post || $post->post_type !== 'f365_team') {
        return $desc;
    }

    $section = get_query_var('f365_section');

    $sd        = f365_team_seo_data((int) $team_id);
    $shortname = $sd['shortname'];
    $longname  = $sd['longname'];

    $name = $shortname ?: $longname ?: get_the_title($post->ID);

    /**
     * -----------------------------
     * COMPARE DESCRIPTION
     * -----------------------------
     */
    if ($section && str_starts_with($section, 'comparar')) {

        $team2_id = get_query_var('f365_team2_id');

        $sd2   = f365_team_seo_data((int) $team2_id);
        $name2 = $sd2['shortname'] ?: $sd2['longname'];

        $desc_map = [
            'comparar'            => "O resumo do frente-a-frente entre {$name} e {$name2}.",
            'comparar-confrontos' => "Todos os jogos disputados entre {$name} e {$name2}.",
            'comparar-forma'      => "Os últimos jogos disputados por {$name} e {$name2}.",
            'comparar-palmares'   => "A comparação do palmarés entre {$name} e {$name2}.",
            'comparar-estadios'   => "Os estádios onde jogam {$name} e {$name2}.",
        ];

        return $desc_map[$section] ?? "O resumo do frente-a-frente entre {$name} e {$name2}.";
    }

    /**
     * -----------------------------
     * NORMAL DESCRIPTION (145 chars)
     * -----------------------------
     */
    return "Toda a informação sobre {$longname}. Jogos, jogadores, estatísticas, transferências, palmarés, notícias, vídeos e muito mais!";
}
add_filter('wpseo_metadesc', 'f365_team_wpseo_metadesc', 20);
add_filter( 'wpseo_opengraph_desc', 'f365_team_wpseo_metadesc', 20 );

/**
 * -------------------------------------------------------
 * 8b. CANONICAL REDIRECT BYPASS
 * * Prevents WordPress from hijacking custom sub-sections/compare routes
 * and forcing them back to the single post base URL.
 * -------------------------------------------------------
 */
add_filter( 'redirect_canonical', 'f365_team_disable_canonical_sections', 10, 2 );

function f365_team_disable_canonical_sections( string $redirect_url, string $requested_url ): string|false {

    // If our custom section or compare query vars are active, skip canonical redirect
    if ( get_query_var( 'f365_section' ) || get_query_var( 'f365_team2_id' ) ) {
        return false;
    }

    return $redirect_url;
}

/**
 * -------------------------------------------------------
 * 9c. YOAST SEO — wpseo_canonical (self-referencing)
 *
 * The generic f365_yoast_canonical_override() only appends the raw
 * f365_section to Yoast's base, which cannot express the compare URL
 * shape (/equipa/{id}/comparar-com/{id2}/{sub}/) and was producing a
 * mangled canonical such as /equipa/comparar-palmares/.
 *
 * This filter rebuilds the canonical authoritatively from the URL query
 * vars so it always matches the real URL. Runs at priority 40 — after the
 * generic override (20) and the pagination canonical (30) — and fully
 * replaces whatever they produced for team routes.
 *
 *   Normal:        /equipa/{id}/{section}/
 *   Compare base:  /equipa/{id}/comparar-com/{id2}/
 *   Compare sub:   /equipa/{id}/comparar-com/{id2}/{sub}/
 *                  (sub = section minus the 'comparar-' prefix)
 * -------------------------------------------------------
 */
/**
 * -------------------------------------------------------
 * 9c. YOAST SEO — wpseo_canonical (self-referencing)
 *
 * The generic f365_yoast_canonical_override() only appends the raw
 * f365_section to Yoast's base, which cannot express the compare URL
 * shape (/equipa/{id}/comparar-com/{id2}/{sub}/) and was producing a
 * mangled canonical such as /equipa/comparar-palmares/.
 *
 * This filter rebuilds the canonical authoritatively from the URL query
 * vars so it always matches the real URL. Runs at priority 40 — after the
 * generic override (20) and the pagination canonical (30) — and fully
 * replaces whatever they produced for team routes.
 *
 *   Normal:        /equipa/{id}/{section}/
 *   Compare base:  /equipa/{id}/comparar-com/{id2}/
 *   Compare sub:   /equipa/{id}/comparar-com/{id2}/{sub}/
 *                  (sub = section minus the 'comparar-' prefix)
 * -------------------------------------------------------
 */
add_filter( 'wpseo_canonical', 'f365_team_wpseo_canonical', 40 );
add_filter( 'wpseo_opengraph_url', 'f365_team_wpseo_canonical', 40 );

/**
 * Recognised team filter query args that select distinct content (a specific
 * TeamStep / squad view) and therefore MUST be preserved in the canonical so
 * each filtered view is self-referencing — mirroring the Django source, whose
 * team canonical is request.get_full_path() (path + full query string,
 * team/templates/team/team_details.html). Without this, every edition / step /
 * modality / gender variant collapses onto the same canonical.
 *
 * The public URLs use the unprefixed names (?edition=&step=&modality=&gender=),
 * so $_GET is read first with the registered f365_* query vars as a fallback.
 * Only non-empty values are emitted, in a stable order, so the canonical is
 * independent of the order the params arrived in. Deliberately a whitelist:
 * arbitrary / tracking params (utm_*, fbclid, …) are never copied in.
 *
 * @return array<string,string>
 */
function f365_team_canonical_query_args(): array {

    $filters = [
        'edition'  => 'f365_edition',
        'step'     => 'f365_step',
        'modality' => 'f365_modality',
        'gender'   => 'f365_gender',
    ];

    $args = [];

    foreach ( $filters as $param => $query_var ) {

        $value = (string) get_query_var( $query_var );

        if ( $value === '' && isset( $_GET[ $param ] ) ) {
            $value = (string) wp_unslash( $_GET[ $param ] );
        }

        $value = sanitize_text_field( $value );

        if ( $value !== '' ) {
            $args[ $param ] = $value;
        }
    }

    return $args;
}

function f365_team_wpseo_canonical( $canonical ) {

    $team_id = (int) get_query_var( 'f365_team_id' );
    if ( ! $team_id ) {
        return $canonical;
    }

    $section = (string) get_query_var( 'f365_section' );

    // Compare routes: /equipa/{id}/comparar-com/{id2}/[sub]/
    if ( $section !== '' && str_starts_with( $section, 'comparar' ) ) {

        $team2_id = (int) get_query_var( 'f365_team2_id' );
        $path     = "equipa/{$team_id}/comparar-com/{$team2_id}";

        if ( $section !== 'comparar' ) {
            $sub = substr( $section, strlen( 'comparar-' ) ); // confrontos|forma|palmares|estadios
            if ( $sub !== '' ) {
                $path .= '/' . $sub;
            }
        }

        $url  = user_trailingslashit( home_url( $path ) );
        $args = f365_team_canonical_query_args();

        return $args ? add_query_arg( $args, $url ) : $url;
    }

    // Normal team sections: /equipa/{id}/{section}/
    $path = "equipa/{$team_id}";
    if ( $section !== '' ) {
        $path .= '/' . $section;
    }

    $url = user_trailingslashit( home_url( $path ) );

    // Preserve the team filter params so the canonical is self-referencing for
    // each TeamStep view (edition/step/modality/gender), matching Django.
    $args = f365_team_canonical_query_args();

    return $args ? add_query_arg( $args, $url ) : $url;
}
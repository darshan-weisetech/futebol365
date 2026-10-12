<?php
/**
 * Futebol365 — Competition Rewrite Rules & Routing.
 *
 * Mirrors the original Django URL structure exactly:
 *   /competicao/{fed_comp_id}/                 → summary (resumo)
 *   /competicao/{fed_comp_id}/jogos/           → matches
 *   /competicao/{fed_comp_id}/classificacao/   → standings
 *   /competicao/{fed_comp_id}/marcadores/      → top scorers
 *   /competicao/{fed_comp_id}/estadios/        → stadiums
 *   /competicao/{fed_comp_id}/videos/          → videos
 *   /competicao/{fed_comp_id}/noticias/        → news
 *   ?edition=YYYY  selects the edition (defaults to latest available)
 *
 * {fed_comp_id} = the URL group key = competition_competition.federation_competition_id
 * (one source row per edition). ?edition selects the year and defaults to the latest
 * available; the chosen row maps back to its post via the 'django_competition_id' meta
 * (= competition_competition.id). This mirrors Django's Competition.get_absolute_url().
 *
 * Follows the same 9-part architecture as team-rewrite.php.
 *
 * @package Futebol365
 * @since   2.3.0
 */

defined('ABSPATH') || exit;

/* 1. REWRITE RULES ------------------------------------------------------- */
add_action('init', 'f365_competition_rewrite_rules', 20);
function f365_competition_rewrite_rules(): void
{

    $sections = ['jogos', 'calendario', 'classificacao', 'equipas', 'estatisticas', 'transferencias', 'vencedores', 'estadios', 'videos', 'noticias'];

    // Round filter: /competicao/{id}/jogos/jornada/{n}/
    add_rewrite_rule(
        '^competicao/([0-9]+)/jogos/jornada/([0-9]+)/?$',
        'index.php?post_type=f365_competition&f365_fedcomp_id=$matches[1]&f365_section=jogos&f365_round=$matches[2]',
        'top'
    );

    foreach ($sections as $section) {
        add_rewrite_rule(
            '^competicao/([0-9]+)/' . $section . '/?$',
            'index.php?post_type=f365_competition&f365_fedcomp_id=$matches[1]&f365_section=' . $section,
            'top'
        );
    }

    // Base competition URL → summary.
    add_rewrite_rule(
        '^competicao/([0-9]+)/?$',
        'index.php?post_type=f365_competition&f365_fedcomp_id=$matches[1]&f365_section=resumo',
        'top'
    );
}

/* 2. QUERY VARS ---------------------------------------------------------- */
add_filter('query_vars', 'f365_competition_query_vars');
function f365_competition_query_vars(array $vars): array
{
    foreach (['f365_fedcomp_id', 'f365_edition', 'f365_round', 'f365_phase', 'f365_group'] as $v) {
        if (!in_array($v, $vars, true)) {
            $vars[] = $v;
        }
    }
    return $vars;
}

/* 3. PRE_GET_POSTS — resolve (fed_comp_id, edition) → competition post ---- */
add_action('pre_get_posts', 'f365_competition_pre_get_posts');
function f365_competition_pre_get_posts(WP_Query $query): void
{
    if (is_admin() || !$query->is_main_query()) {
        return;
    }

    $fedcomp_id = (int) $query->get('f365_fedcomp_id');
    if (!$fedcomp_id) {
        return;
    }

    $edition = (string) ($query->get('f365_edition') ?: ($_GET['edition'] ?? ''));
    $post_id = competition_post_id($fedcomp_id, $edition);
    $query->set('post_type', 'f365_competition');
    $query->set('p', $post_id ?: 0);
    $query->set('name', '');
    // Correct query flags for custom rewrite matching so that Yoast SEO and WP recognize it as singular
    $query->is_single = true;
    $query->is_singular = true;
    $query->is_archive = false;
    $query->is_post_type_archive = false;


}

/* 4. RESOLVE HELPER (static-cached) -------------------------------------- */
function f365_competition_resolve(int $fed_comp_id, string $edition = ''): ?int
{
    return competition_post_id($fed_comp_id, $edition);
}

/* 5. PERMALINK — /competicao/{fed_comp_id}/?edition= --------------------- */
add_filter('post_type_link', 'f365_competition_permalink', 10, 2);
function f365_competition_permalink(string $post_link, WP_Post $post): string
{
    if ('f365_competition' !== $post->post_type) {
        return $post_link;
    }
    return f365_competition_url($post->ID);
}

/* 6. URL HELPER ---------------------------------------------------------- */
function f365_competition_url(int $post_id, string $section = ''): string
{
    // Permalink is keyed by the competition group (federation_competition_id). The
    // edition defaults to the latest year, so the latest edition produces a clean URL
    // and only non-latest editions carry an explicit ?edition.
    $src = f365_competition_seo_source($post_id);
    $fed_comp_id = (int) $src['federation_competition_id'];
    $edition = (string) $src['edition'];
    $final_id = $fed_comp_id ?: $post_id;

    $path = "competicao/{$final_id}";
    if ($section) {
        $path .= '/' . trim($section, '/');
    }

    $url = user_trailingslashit(home_url($path));

    if ($edition !== '' && $edition !== f365_competition_latest_edition($fed_comp_id)) {
        $url = add_query_arg('edition', $edition, $url);
    }
    return $url;
}

/** Current competition post from the active query. */
function f365_get_current_competition_post(): ?WP_Post
{
    $fedcomp_id = (int) get_query_var('f365_fedcomp_id');
    if (!$fedcomp_id) {
        return null;
    }

    global $post;
    if ($post instanceof WP_Post && $post->post_type === 'f365_competition') {
        return $post;
    }

    $edition = (string) (get_query_var('f365_edition') ?: ($_GET['edition'] ?? ''));
    $id = competition_post_id($fedcomp_id, $edition);
    return $id ? get_post($id) : null;
}

/** Resolve a Competition post by federation-competition id + edition (default: latest). */
function competition_post_id(int $fed_comp_id, string $edition = ''): ?int
{
    global $wpdb;

    static $memo = [];

    $key = "comp:{$fed_comp_id}:{$edition}";

    if (isset($memo[$key])) {
        return $memo[$key];
    }

    // fed_comp_id is the URL group key = competition_competition.federation_competition_id
    // (one row per edition). The chosen source row is mapped back to its post via the
    // 'django_competition_id' meta (= competition_competition.id). Edition values are
    // read from the cloned table; when none is requested we default to the latest year.
    $T_COMP = $wpdb->prefix . 'competition_competition';

    if ($edition !== '') {
        // A specific edition was selected through the filter.
        $id = $wpdb->get_var($wpdb->prepare(
            "SELECT p.ID
				FROM {$T_COMP} comp
				INNER JOIN {$wpdb->postmeta} dc ON dc.meta_key = 'django_competition_id' AND dc.meta_value = comp.id
				INNER JOIN {$wpdb->posts}    p  ON p.ID = dc.post_id AND p.post_type = 'f365_competition' AND p.post_status != 'trash'
				WHERE comp.federation_competition_id = %d AND comp.edition = %s
				LIMIT 1",
            $fed_comp_id,
            $edition
        ));
    } else {
        // Default: latest edition (highest year) for this competition group.
        $id = $wpdb->get_var($wpdb->prepare(
            "SELECT p.ID
				FROM {$T_COMP} comp
				INNER JOIN {$wpdb->postmeta} dc ON dc.meta_key = 'django_competition_id' AND dc.meta_value = comp.id
				INNER JOIN {$wpdb->posts}    p  ON p.ID = dc.post_id AND p.post_type = 'f365_competition' AND p.post_status != 'trash'
				WHERE comp.federation_competition_id = %d
				ORDER BY comp.edition+0 DESC
				LIMIT 1",
            $fed_comp_id
        ));
    }

    $memo[$key] = $id ? (int) $id : null;

    return $memo[$key];
}

/** Latest edition (highest year) for a competition group, or '' if none. */
function f365_competition_latest_edition(int $fed_comp_id): string
{
    if ($fed_comp_id <= 0) {
        return '';
    }

    static $memo = [];
    if (array_key_exists($fed_comp_id, $memo)) {
        return $memo[$fed_comp_id];
    }

    $cache_key = 'f365_comp_latest_edition_' . $fed_comp_id;
    $cached    = wp_cache_get($cache_key, 'f365');
    if ($cached !== false) {
        return $memo[$fed_comp_id] = $cached;
    }

    global $wpdb;
    $T_COMP = $wpdb->prefix . 'competition_competition';
    if (function_exists('f365_src_table_exists') && !f365_src_table_exists($T_COMP)) {
        return $memo[$fed_comp_id] = '';
    }

    $edition = $wpdb->get_var($wpdb->prepare(
        "SELECT comp.edition
			FROM {$T_COMP} comp
			WHERE comp.federation_competition_id = %d
			ORDER BY comp.edition+0 DESC
			LIMIT 1",
        $fed_comp_id
    ));

    $result = ($edition !== null) ? (string) $edition : '';
    wp_cache_set($cache_key, $result, 'f365', 12 * HOUR_IN_SECONDS);
    return $memo[$fed_comp_id] = $result;
}


/* 7. TEMPLATE LOADER ----------------------------------------------------- */
add_filter('template_include', 'f365_competition_template_include');
function f365_competition_template_include(string $template): string
{
    $fedcomp_id = get_query_var('f365_fedcomp_id');
    if (!$fedcomp_id) {
        return $template;
    }

    $section = get_query_var('f365_section') ?: 'resumo';
    $map = [
        'jogos' => 'f365-competition-jogos',
        'calendario' => 'f365-competition-calendario',
        'classificacao' => 'f365-competition-classificacao',
        'equipas' => 'f365-competition-equipas',
        'estatisticas' => 'f365-competition-estatisticas',
        'transferencias' => 'f365-competition-transferencias',
        'vencedores' => 'f365-competition-vencedores',
        'estadios' => 'f365-competition-estadios',
        'videos' => 'f365-competition-videos',
        'noticias' => 'f365-competition-noticias',
    ];
    $tpl = $map[$section] ?? 'f365-competition-resumo';
    $found = f365_locate_competition_template($tpl) ?: f365_locate_competition_template('single-f365_competition');
    return $found ?: $template;
}

/* 8. TEMPLATE LOCATOR ---------------------------------------------------- */
function f365_locate_competition_template(string $name): ?string
{
    $theme = locate_template(["template-parts/competition/{$name}.php", "{$name}.php"], false, false);
    if ($theme) {
        return $theme;
    }
    if (defined('F365_PLUGIN_DIR')) {
        $file = trailingslashit(F365_PLUGIN_DIR) . "templates/competition-template/{$name}.php";
        if (file_exists($file)) {
            return $file;
        }
    }
    return null;
}

/* =========================================================
 * YOAST SEO - COMPETITION TITLE + DESCRIPTION
 * ========================================================= */
/**
 * SEO source — the values the source templates use:
 *   competition.name, competition.season, competition.edition,
 *   competition.federation_shortname
 *
 * Read from the cloned tables (NOT post_title / wp_postmeta / taxonomy):
 *   name, season, edition   ← {prefix}competition_competition (keyed on django_competition_id)
 *   federation_shortname    ← {prefix}federation_federation.shortname, joined through
 *                             {prefix}federation_federationcompetition (federation_competition_id)
 *
 * Mirrors f365-competition-header.php: federation_shortname falls back to
 * 'Futebol 365' when the federation has no shortname / the join misses.
 *
 * @return array{name:string,season:string,edition:string,federation_competition_id:int,federation_shortname:string}
 */
function f365_competition_seo_source(int $post_id): array
{
    static $memo = [];

    $empty = ['name' => '', 'season' => '', 'edition' => '', 'federation_competition_id' => 0, 'federation_shortname' => 'Futebol 365'];

    if ($post_id <= 0) {
        return $empty;
    }
    if (array_key_exists($post_id, $memo)) {
        return $memo[$post_id];
    }

    // 1. Check WordPress object cache first (by post_id)
    $cache_key = 'f365_competition_seo_source_' . $post_id;
    $cached    = wp_cache_get($cache_key, 'f365');
    if (is_array($cached)) {
        return $memo[$post_id] = $cached;
    }

    $post = get_post($post_id);
    if (!$post) {
        wp_cache_set($cache_key, $empty, 'f365', 12 * HOUR_IN_SECONDS);
        return $memo[$post_id] = $empty;
    }

    // 2. Resolve Django competition ID (Try legacy_id property first, fallback to postmeta)
    $django_id = 0;
    if (isset($post->legacy_id) && (int) $post->legacy_id > 0) {
        $django_id = (int) $post->legacy_id;
    } else {
        $django_id = (int) get_post_meta($post_id, 'django_competition_id', true);
    }

    if ($django_id <= 0) {
        wp_cache_set($cache_key, $empty, 'f365', 12 * HOUR_IN_SECONDS);
        return $memo[$post_id] = $empty;
    }

    global $wpdb;
    $T_COMP = $wpdb->prefix . 'competition_competition';
    if (function_exists('f365_src_table_exists') && !f365_src_table_exists($T_COMP)) {
        wp_cache_set($cache_key, $empty, 'f365', 12 * HOUR_IN_SECONDS);
        return $memo[$post_id] = $empty;
    }

    $T_FC = $wpdb->prefix . 'federation_federationcompetition';
    $T_FED = $wpdb->prefix . 'federation_federation';

    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT comp.name, comp.season, comp.edition, comp.federation_competition_id, f.shortname AS federation_shortname
           FROM {$T_COMP} comp
           LEFT JOIN {$T_FC}  fc ON fc.id = comp.federation_competition_id
           LEFT JOIN {$T_FED} f  ON f.id  = fc.federation_id
          WHERE comp.id = %d
          LIMIT 1",
        $django_id
    ), ARRAY_A);

    if ($wpdb->last_error !== '' || !is_array($row)) {
        return $memo[$post_id] = $empty; // do not cache failed lookups
    }

    $fed = (isset($row['federation_shortname']) && $row['federation_shortname'] !== null && $row['federation_shortname'] !== '')
        ? (string) $row['federation_shortname']
        : '- Futebol 365';

    $data = [
        'name' => isset($row['name']) ? (string) $row['name'] : '',
        'season' => (isset($row['season']) && $row['season'] !== null) ? (string) $row['season'] : '',
        'edition' => (isset($row['edition']) && $row['edition'] !== null) ? (string) $row['edition'] : '',
        'federation_competition_id' => isset($row['federation_competition_id']) ? (int) $row['federation_competition_id'] : 0,
        'federation_shortname' => $fed,
    ];

    wp_cache_set($cache_key, $data, 'f365', 12 * HOUR_IN_SECONDS);
    return $memo[$post_id] = $data;
}

/**
 * Build the {title, desc} pair for a competition page+section, exactly mirroring
 * the source Django title/description blocks.
 *
 * Base (block.super) = "{name} {season} - {federation_shortname}".
 * Sections without their own {% block description %} (classificacao, estatisticas,
 * vencedores, resumo) inherit the base description. 'vencedores' is the only
 * section with a custom title that drops the season.
 *
 * @return array{title:string,desc:string}
 */
function f365_get_competition_seo_data($post_id, $section): array
{
    $src = f365_competition_seo_source((int) $post_id);
    $name = $src['name'] ?: get_the_title((int) $post_id);
    $season = $src['season'];
    $fed = $src['federation_shortname'];
    $section = $section ?: 'resumo';

    // block.super
    $base = trim($name . ' ' . $season) . ' - ' . $fed;

    /* ---------------- TITLE ---------------- */
    switch ($section) {
        case 'jogos':
            $title = "{$base} - Jogos";
            break;
        case 'calendario':
            $title = "{$base} - Calendário";
            break;
        case 'classificacao':
            $title = "{$base} - Classificação";
            break;
        case 'equipas':
            $title = "{$base} - Equipas";
            break;
        case 'estatisticas':
            // Source: "{block.super} - Estatísticas - {{ title }}". {{ title }} is the
            // active stat tab; the /estatisticas/ route defaults to "Vitórias".
            $stat = (string) (get_query_var('f365_stat') ?: 'Vitórias');
            $title = "{$base} - Estatísticas - {$stat}";
            break;
        case 'transferencias':
            $title = "{$base} - Transferências";
            break;
        case 'estadios':
            $title = "{$base} - Estádios";
            break;
        case 'noticias':
            $title = "{$base} - Notícias";
            break;
        case 'videos':
            $title = "{$base} - Vídeos";
            break;
        case 'vencedores':
            // Custom source title: name - federation_shortname - Vencedores (no season).
            $title = "{$name} - {$fed} - Vencedores";
            break;
        case 'resumo':
        default:
            $title = $base;
            break;
    }
    $title .= ' - Futebol 365';

    /* ---------------- DESCRIPTION ---------------- */
    switch ($section) {
        case 'jogos':
            $desc = "Calendário de jogos {$base}.";
            break;
        case 'calendario':
            $desc = "Calendário completo {$base}. Confira todos os jogos e resultados da competição.";
            break;
        case 'equipas':
            $desc = "Todas as equipas {$base}. Confira a lista completa de todos as equipas que participam na competição.";
            break;
        case 'transferencias':
            $desc = "Todas as transferências {$base}. Confira todas as contratações das equipas da competição.";
            break;
        case 'estadios':
            $desc = "Todos os estádios {$base}. Confira a lista completa de todos os estádios da competição.";
            break;
        case 'noticias':
            $desc = "Notícias sobre {$base}. Confira todas as novidades da competição.";
            break;
        case 'videos':
            $desc = "Os vídeos sobre {$base}. Veja e reveja os melhores momentos da competição.";
            break;

        // No {% block description %} in source → inherit the base (resumo) description.
        case 'resumo':
        case 'classificacao':
        case 'estatisticas':
        case 'vencedores':
        default:
            $desc = "Toda a informação sobre {$base}. Jogos, jogadores, equipas, calendário, estatísticas, transferências, vencedores, notícias, vídeos e muito mais.";
            break;
    }

    return [
        'title' => $title,
        'desc' => $desc,
    ];
}

function f365_competition_wpseo_title(string $title): string
{
    $post = f365_get_current_competition_post();
    if (!$post)
        return $title;

    $section = get_query_var('f365_section') ?: 'resumo';

    $seo = f365_get_competition_seo_data($post->ID, $section);

    return $seo['title'];
}
add_filter('wpseo_title', 'f365_competition_wpseo_title', 20);
add_filter('wpseo_opengraph_title', 'f365_competition_wpseo_title', 20);

function f365_competition_wpseo_metadesc(string $desc): string
{
    $post = f365_get_current_competition_post();
    if (!$post)
        return $desc;

    $section = get_query_var('f365_section') ?: 'resumo';

    $seo = f365_get_competition_seo_data($post->ID, $section);

    return $seo['desc'];
}
add_filter('wpseo_metadesc', 'f365_competition_wpseo_metadesc', 20);
add_filter('wpseo_opengraph_desc', 'f365_competition_wpseo_metadesc', 20);

/**
 * Canonical — build it from the source ids (fed_comp_id + section) instead of
 * letting the generic f365_yoast_canonical_override() append the section to
 * Yoast's (wrong) base canonical, which produced /competicao/{section}/ with
 * the id dropped.
 *
 * ?edition= is only carried when the current request actually included it; the
 * value is taken from the resolved post (not echoed from raw input).
 *
 * Runs at priority 30 so it authoritatively replaces the generic override
 * (priority 20) for competition pages only.
 */

function f365_competition_wpseo_canonical($canonical)
{

    $post = f365_get_current_competition_post();
    if (!$post) {
        return $canonical;
    }

    $section = (string) get_query_var('f365_section');
    if ('resumo' === $section) {
        $section = ''; // base competition URL
    }

    // Canonical is keyed by the competition group (federation_competition_id). The
    // latest edition is the default, so it canonicalizes to the clean URL; a non-latest
    // edition carries an explicit ?edition.
    $src = f365_competition_seo_source($post->ID);
    $fed_comp_id = (int) $src['federation_competition_id'];
    $edition = (string) $src['edition'];
    $final_id = $fed_comp_id ?: $post->ID;

    $path = 'competicao/' . $final_id;
    if ('' !== $section) {
        $path .= '/' . trim($section, '/');
    }
    $url = user_trailingslashit(home_url($path));

    return $url ?: $canonical;
}

add_filter('wpseo_canonical', 'f365_competition_wpseo_canonical', 30);
add_filter('wpseo_opengraph_url', 'f365_competition_wpseo_canonical', 30);

<?php
/**
 * The template for displaying search results pages
 * Modified to fetch data directly from custom database tables matching the Python codebase.
 *
 * @package Futebol365
 */

get_header();

// ----------------------------------------------------
// Custom Table Search Configuration & Helpers
// ----------------------------------------------------


/**
 * Extract words >= 3 characters for MySQL Full-Text Search
 */
if (!function_exists('f365_get_fts_terms')) {
    function f365_get_fts_terms($clean_term) {
        $words = preg_split('/\s+/', $clean_term);
        $fts_words = array();
        foreach ($words as $word) {
            $word = trim($word);
            if (strlen($word) >= 3) {
                $fts_words[] = '+' . $word;
            }
        }
        return implode(' ', $fts_words);
    }
}

/**
 * Clean and normalize search terms (remove accents, replace hyphens, lowercase)
 */
if (!function_exists('f365_remove_accents')) {
    function f365_remove_accents($string) {
        if (empty($string)) {
            return $string;
        }
        if (function_exists('transliterator_transliterate')) {
            $string = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $string);
        } else {
            $accent_map = array(
                'á'=>'a', 'à'=>'a', 'â'=>'a', 'ã'=>'a', 'ä'=>'a', 'å'=>'a', 'æ'=>'ae', 'ç'=>'c',
                'é'=>'e', 'è'=>'e', 'ê'=>'e', 'ë'=>'e', 'í'=>'i', 'ì'=>'i', 'î'=>'i', 'ï'=>'i',
                'ñ'=>'n', 'ó'=>'o', 'ò'=>'o', 'ô'=>'o', 'õ'=>'o', 'ö'=>'o', 'œ'=>'oe', 'ú'=>'u',
                'ù'=>'u', 'û'=>'u', 'ü'=>'u', 'ý'=>'y', 'ÿ'=>'y',
                'Á'=>'A', 'À'=>'A', 'Â'=>'A', 'Ã'=>'A', 'Ä'=>'A', 'Å'=>'A', 'Æ'=>'AE', 'Ç'=>'C',
                'É'=>'E', 'È'=>'E', 'Ê'=>'E', 'Ë'=>'E', 'Í'=>'I', 'Ì'=>'I', 'Î'=>'I', 'Ï'=>'I',
                'Ñ'=>'N', 'Ó'=>'O', 'Ò'=>'O', 'Ô'=>'O', 'Õ'=>'O', 'Ö'=>'O', 'Œ'=>'OE', 'Ú'=>'U',
                'Ù'=>'U', 'Û'=>'U', 'Ü'=>'U', 'Ý'=>'Y', 'ß'=>'ss'
            );
            $string = strtr($string, $accent_map);
            $string = strtolower($string);
        }
        return $string;
    }
}

// Media assets helper paths (adjust paths if they differ in your media setup)
if (!function_exists('f365_get_image_fallback_url')) {
    function f365_get_image_fallback_url() {
        return get_theme_file_uri('assets/images/image-fallback.jpg');
    }
}

if (!function_exists('f365_get_team_logo_url')) {
    // Team logo: /media/images/logotipo/100x100/{team_id}.gif
    function f365_get_team_logo_url($team_id) {
        $path = 'media/images/logotipo/100x100/' . $team_id . '.gif';
        if (defined('ABSPATH') && file_exists(ABSPATH . $path)) {
            return home_url('/' . $path);
        }
        return f365_get_image_fallback_url();
    }
}
if (!function_exists('f365_get_player_photo_url')) {
    // Player photo: /media/images/person/{person_id}.jpg
    function f365_get_player_photo_url($person_id) {
        $path = 'media/images/person/' . $person_id . '.jpg';
        if (defined('ABSPATH') && file_exists(ABSPATH . $path)) {
            return home_url('/' . $path);
        }
        return f365_get_image_fallback_url();
    }
}
if (!function_exists('f365_get_coach_photo_url')) {
    // Coach photo: /media/images/person/{person_id}_coach.jpg
    function f365_get_coach_photo_url($person_id) {
        $path = 'media/images/person/' . $person_id . '_coach.jpg';
        if (defined('ABSPATH') && file_exists(ABSPATH . $path)) {
            return home_url('/' . $path);
        }
        return f365_get_image_fallback_url();
    }
}

/**
 * Helper to compute age from birth date
 */
if (!function_exists('f365_get_age')) {
    function f365_get_age($birth_date) {
        if (empty($birth_date)) {
            return '';
        }
        try {
            $birth = new DateTime($birth_date);
            $today = new DateTime('today');
            return $birth->diff($today)->y;
        } catch (Exception $e) {
            return '';
        }
    }
}

/**
 * Translate position names to Portuguese
 */
if (!function_exists('f365_get_position_name')) {
    function f365_get_position_name($pos) {
        $positions = array(
            'goalkeeper' => 'Guarda-redes',
            'defender'   => 'Defesa',
            'midfielder' => 'Médio',
            'forward'    => 'Avançado'
        );
        return isset($positions[$pos]) ? $positions[$pos] : ucfirst($pos);
    }
}

// ----------------------------------------------------
// Page setup / headers
// ----------------------------------------------------

$search_term = get_search_query();
if (empty($search_term) && isset($_GET['q'])) {
    $search_term = sanitize_text_field($_GET['q']);
}
$mod = isset($_GET['mod']) ? sanitize_key($_GET['mod']) : '';

$banner_pages = get_field('add_pages', 'option'); 
$current_url  = home_url(add_query_arg([], $GLOBALS['wp']->request));
$bg_url = get_the_post_thumbnail_url($post->ID ?? 0, 'full');

$bg_url = $bg_url ?: ($row['bg_image']['url'] ?? null);

$bg_url = $bg_url ?: (function_exists('futebol365_fallback_image')
    ? futebol365_fallback_image()
    : '');
?>
    <div class="ink-grid vertical-space" source="adsense">
        <?php get_template_part('template-parts/template-offer-widget'); ?>
        <div class="ink-header-f365 multimedia" style="background-image: linear-gradient(to left, rgba(250, 250, 250, 0) 40%, rgba(0, 0, 0, 1)), url(<?php echo $bg_url?>);" >
            <div class="column-group">
                <div class="align-left">
                    <div class="headerdata">
                        <h1>Pesquisa</h1>
                    </div>
                </div>
            </div>
        </div>

        <div class="nav-trigger" id="nav-trigger">
            <span>Menu</span>
        </div>

        <nav class="nav-main" id="nav-main">
            <ul>
                <li>
                    <a class="<?php echo empty( $mod ) ? 'active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 's', $search_term, home_url( '/' ) ) ); ?>">
                        Resumo
                    </a>
                </li>
                <li>
                    <a class="<?php echo ( $mod === 'news' ) ? 'active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 's' => $search_term, 'mod' => 'news' ), home_url( '/' ) ) ); ?>">
                        Notícias
                    </a>
                </li>
                <li>
                    <a class="<?php echo ( $mod === 'video' ) ? 'active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 's' => $search_term, 'mod' => 'video' ), home_url( '/' ) ) ); ?>">
                        Vídeos
                    </a>
                </li>
                <li>
                    <a class="<?php echo ( $mod === 'player' ) ? 'active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 's' => $search_term, 'mod' => 'player' ), home_url( '/' ) ) ); ?>">
                        Jogadores
                    </a>
                </li>
                <li>
                    <a class="<?php echo ( $mod === 'coach' ) ? 'active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 's' => $search_term, 'mod' => 'coach' ), home_url( '/' ) ) ); ?>">
                        Treinadores
                    </a>
                </li>
                <li>
                    <a class="<?php echo ( $mod === 'team' ) ? 'active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 's' => $search_term, 'mod' => 'team' ), home_url( '/' ) ) ); ?>">
                        Equipas
                    </a>
                </li>
            </ul>
        </nav>
    </div>

    <div class="ink-grid futebol365-container">
        <section class="left-column-block">
            <div class="breadcrumbs-f365">
                <div class="ink-navigation breadcrumbs">
                    <?php yoast_breadcrumb(); ?>
                </div>
            </div>
            <div class="column-group double-bottom-space search-form-block">
                <form role="search" method="get" class="search-form" action="<?php echo esc_url( home_url( '/' ) ); ?>">
                    <input type="search" class="search-field" placeholder="Pesquisar..." value="<?php echo esc_attr( $search_term ); ?>" name="s" />
                    <button type="submit" class="search-submit">Pesquisar</button>
                </form>
            </div>
            
            <?php
            
            // Normalize and clean term matching the Python backend logic
            $clean_term = f365_remove_accents($search_term);
            $clean_term = str_replace('-', ' ', $clean_term);
            $clean_term = trim($clean_term);
            
            $paged = get_query_var('paged') ? get_query_var('paged') : 1;
            
            // Django/Haystack limits and page sizes
            $per_page = ($mod === '') ? 25 : 10;
            $is_valid_query = (!empty($clean_term) && strlen($clean_term) >= 3);
            
            if ($is_valid_query) {
                // Execute Custom DB Tables Query
                global $wpdb;
                $prefix = $wpdb->prefix;
                $offset = ($paged - 1) * $per_page;
                $like_term = '%' . $wpdb->esc_like($clean_term) . '%';
                
                $grouped_posts = array();
                $total_count = 0;
                
                // Group labels mapping
                $post_types = array(
                    'news'   => array('label' => 'Notícias'),
                    'video'  => array('label' => 'Vídeos'),
                    'player' => array('label' => 'Jogadores'),
                    'coach'  => array('label' => 'Treinadores'),
                    'team'   => array('label' => 'Equipas'),
                );
                
                // Fetch News
                if ($mod === 'news' || ($mod === '' && count($grouped_posts) < 5)) {
                    $fts_terms = f365_get_fts_terms($clean_term);
                    
                    // Set up limit and offset
                    $limit_val = ($mod === '') ? 5 : $per_page;
                    $offset_val = ($mod === '') ? 0 : $offset;
                    
                    if (!empty($fts_terms)) {
                        // Hybrid search: FTS narrow down + LIKE exact match with Deferred Join optimization
                        $sql = "SELECT t1.id, t1.title, t1.summary, t1.description, t1.datetime, t1.slug, t1.image 
                                FROM {$prefix}publish_news t1
                                INNER JOIN (
                                    SELECT id 
                                    FROM {$prefix}publish_news 
                                    WHERE is_published IN (1, 't', 'true') AND datetime <= NOW() 
                                      AND MATCH(title) AGAINST(%s IN BOOLEAN MODE)
                                      AND (title LIKE %s)
                                    ORDER BY datetime DESC
                                    LIMIT %d OFFSET %d
                                ) AS t2 ON t1.id = t2.id
                                ORDER BY t1.datetime DESC";
                        $prep_args = array($fts_terms, $like_term, $like_term, $limit_val, $offset_val);
                    } else {
                        // Fallback to simple LIKE if no words are >= 3 characters (using Deferred Join)
                        $sql = "SELECT t1.id, t1.title, t1.summary, t1.description, t1.datetime, t1.slug, t1.image 
                                FROM {$prefix}publish_news t1
                                INNER JOIN (
                                    SELECT id 
                                    FROM {$prefix}publish_news 
                                    WHERE is_published IN (1, 't', 'true') AND datetime <= NOW() 
                                      AND (title LIKE %s)
                                    ORDER BY datetime DESC
                                    LIMIT %d OFFSET %d
                                ) AS t2 ON t1.id = t2.id
                                ORDER BY t1.datetime DESC";
                        $prep_args = array($like_term, $like_term, $limit_val, $offset_val);
                    }
                    
                    if ($mod === '') {
                        $sql_exec = $wpdb->prepare($sql, ...$prep_args);
                        $rows = $wpdb->get_results($sql_exec);
                    } else {
                        // Optimize count query by removing subquery and sorting
                        if (!empty($fts_terms)) {
                            $sql_count = "SELECT COUNT(*) 
                                          FROM {$prefix}publish_news 
                                          WHERE is_published IN (1, 't', 'true') AND datetime <= NOW() 
                                            AND MATCH(title) AGAINST(%s IN BOOLEAN MODE)
                                            AND (title LIKE %s)";
                            $count_args = array($fts_terms, $like_term, $like_term);
                        } else {
                            $sql_count = "SELECT COUNT(*) 
                                          FROM {$prefix}publish_news 
                                          WHERE is_published IN (1, 't', 'true') AND datetime <= NOW() 
                                            AND (title LIKE %s)";
                            $count_args = array($like_term, $like_term);
                        }
                        
                        $total_count = (int) $wpdb->get_var($wpdb->prepare($sql_count, ...$count_args));
                        
                        $sql_exec = $wpdb->prepare($sql, ...$prep_args);
                        $rows = $wpdb->get_results($sql_exec);
                    }
                    
                    foreach ($rows as $row) {
                        $thumb = f365_get_image_fallback_url();
                        if (!empty($row->image)) {
                            $path = 'media/' . $row->image;
                            if (defined('ABSPATH') && file_exists(ABSPATH . $path)) {
                                $thumb = home_url('/' . $path);
                            }
                        }
                        $grouped_posts['news'][] = array(
                            'title'          => $row->title,
                            'permalink'      => home_url("/noticias/{$row->slug}/"),
                            'thumb'          => $thumb,
                            'date_formatted' => date('d-m-Y · H:i', strtotime($row->datetime))
                        );
                    }
                }

                
                // Fetch Videos
                if ($mod === 'video' || ($mod === '' && count($grouped_posts) < 5)) {
                    $sql = "SELECT id, title, datetime, url, image 
                            FROM {$prefix}multimedia_video 
                            WHERE title LIKE %s
                            ORDER BY datetime DESC";
                    
                    if ($mod === '') {
                        $sql_exec = $wpdb->prepare($sql . " LIMIT 5", $like_term);
                        $rows = $wpdb->get_results($sql_exec);
                    } else {
                        // Optimize count query by eliminating temporary subquery and sorting
                        $sql_count = $wpdb->prepare("SELECT COUNT(*) FROM {$prefix}multimedia_video WHERE title LIKE %s", $like_term);
                        $total_count = (int) $wpdb->get_var($sql_count);
                        
                        $sql_exec = $wpdb->prepare($sql . " LIMIT %d OFFSET %d", $like_term, $per_page, $offset);
                        $rows = $wpdb->get_results($sql_exec);
                    }
                    
                    foreach ($rows as $row) {
                        $thumb = f365_get_image_fallback_url();
                        if (!empty($row->image)) {
                            $path = 'media/' . $row->image;
                            if (defined('ABSPATH') && file_exists(ABSPATH . $path)) {
                                $thumb = home_url('/' . $path);
                            }
                        }
                        $grouped_posts['video'][] = array(
                            'title'          => $row->title,
                            'permalink'      => home_url("/multimedia/videos/{$row->id}/"),
                            'thumb'          => $thumb,
                            'date_formatted' => date('d-m-Y', strtotime($row->datetime))
                        );
                    }
                }
                
                // Fetch Players
                if ($mod === 'player' || ($mod === '' && count($grouped_posts) < 5)) {
                    $sql = "SELECT p.id as player_id, per.id as person_id, per.shortname, per.longname, per.birth_date, per.death_date, per.birth_place, p.position, per.nationality_id, cnt.shortname as nationality_name
                            FROM {$prefix}person_player p
                            INNER JOIN {$prefix}person_person per ON p.person_id = per.id
                            LEFT JOIN {$prefix}country_country cnt ON per.nationality_id = cnt.id
                            WHERE per.shortname LIKE %s OR per.longname LIKE %s
                            ORDER BY per.shortname ASC";
                    
                    if ($mod === '') {
                        $sql_exec = $wpdb->prepare($sql . " LIMIT 5", $like_term, $like_term);
                        $rows = $wpdb->get_results($sql_exec);
                    } else {
                        // Optimize count query by eliminating temporary subquery and sorting
                        $sql_count = $wpdb->prepare("SELECT COUNT(*) 
                                                      FROM {$prefix}person_player p
                                                      INNER JOIN {$prefix}person_person per ON p.person_id = per.id
                                                      WHERE per.shortname LIKE %s OR per.longname LIKE %s", $like_term, $like_term);
                        $total_count = (int) $wpdb->get_var($sql_count);
                        
                        $sql_exec = $wpdb->prepare($sql . " LIMIT %d OFFSET %d", $like_term, $like_term, $per_page, $offset);
                        $rows = $wpdb->get_results($sql_exec);
                    }
                    
                    foreach ($rows as $row) {
                        // Query player's current team from squads
                        $team_query = $wpdb->prepare("SELECT t.id, t.shortname FROM {$prefix}team_team t
                                                      INNER JOIN {$prefix}team_teamstep ts ON ts.team_id = t.id
                                                      INNER JOIN {$prefix}team_squad s ON s.team_id = ts.id
                                                      WHERE s.player_id = %d ORDER BY s.edition DESC, s.order ASC LIMIT 1", $row->player_id);
                        $team_row = $wpdb->get_row($team_query);
                        
                        $grouped_posts['player'][] = array(
                            'shortname'        => $row->shortname,
                            'longname'         => $row->longname,
                            'permalink'        => home_url("/jogadores/{$row->player_id}/"),
                            'thumb'            => f365_get_player_photo_url($row->person_id),
                            'death_date'       => $row->death_date,
                            'age'              => f365_get_age($row->birth_date),
                            'position_name'    => f365_get_position_name($row->position),
                            'team_shortname'   => $team_row ? $team_row->shortname : '',
                            'team_permalink'   => $team_row ? home_url("/equipas/{$team_row->id}/") : '',
                            'nationality_id'   => $row->nationality_id,
                            'nationality_name' => $row->nationality_name,
                            'birth_place'      => $row->birth_place
                        );
                    }
                }
                
                // Fetch Coaches
                if ($mod === 'coach' || ($mod === '' && count($grouped_posts) < 5)) {
                    $sql = "SELECT c.id as coach_id, per.id as person_id, c.coach_name, per.shortname, per.longname, per.birth_date, per.death_date, per.birth_place, per.nationality_id, cnt.shortname as nationality_name
                            FROM {$prefix}person_coach c
                            INNER JOIN {$prefix}person_person per ON c.person_id = per.id
                            LEFT JOIN {$prefix}country_country cnt ON per.nationality_id = cnt.id
                            WHERE per.shortname LIKE %s OR per.longname LIKE %s OR c.coach_name LIKE %s
                            ORDER BY c.coach_name ASC";
                    
                    if ($mod === '') {
                        $sql_exec = $wpdb->prepare($sql . " LIMIT 5", $like_term, $like_term, $like_term);
                        $rows = $wpdb->get_results($sql_exec);
                    } else {
                        // Optimize count query by eliminating temporary subquery and sorting
                        $sql_count = $wpdb->prepare("SELECT COUNT(*) 
                                                      FROM {$prefix}person_coach c
                                                      INNER JOIN {$prefix}person_person per ON c.person_id = per.id
                                                      WHERE per.shortname LIKE %s OR per.longname LIKE %s OR c.coach_name LIKE %s", $like_term, $like_term, $like_term);
                        $total_count = (int) $wpdb->get_var($sql_count);
                        
                        $sql_exec = $wpdb->prepare($sql . " LIMIT %d OFFSET %d", $like_term, $like_term, $like_term, $per_page, $offset);
                        $rows = $wpdb->get_results($sql_exec);
                    }
                    
                    foreach ($rows as $row) {
                        // Query coach's current team from teamsteps
                        $team_query = $wpdb->prepare("SELECT t.id, t.shortname FROM {$prefix}team_team t
                                                      INNER JOIN {$prefix}team_teamstep ts ON ts.team_id = t.id
                                                      INNER JOIN {$prefix}team_teamstepcoach c ON c.team_id = ts.id
                                                      WHERE c.coach_id = %d ORDER BY c.edition DESC, c.order ASC LIMIT 1", $row->coach_id);
                        $team_row = $wpdb->get_row($team_query);
                        
                        $grouped_posts['coach'][] = array(
                            'coach_name'       => $row->coach_name,
                            'longname'         => $row->longname,
                            'permalink'        => home_url("/treinadores/{$row->coach_id}/"),
                            'thumb'            => f365_get_coach_photo_url($row->person_id),
                            'death_date'       => $row->death_date,
                            'age'              => f365_get_age($row->birth_date),
                            'team_shortname'   => $team_row ? $team_row->shortname : '',
                            'team_permalink'   => $team_row ? home_url("/equipas/{$team_row->id}/") : '',
                            'nationality_id'   => $row->nationality_id,
                            'nationality_name' => $row->nationality_name,
                            'birth_place'      => $row->birth_place
                        );
                    }
                }
                
                // Fetch Teams
                if ($mod === 'team' || ($mod === '' && count($grouped_posts) < 5)) {
                    $sql = "SELECT t.id, t.shortname, t.longname, t.city, t.country_id, cnt.shortname as country_name 
                            FROM {$prefix}team_team t
                            LEFT JOIN {$prefix}country_country cnt ON t.country_id = cnt.id
                            WHERE t.shortname LIKE %s OR t.longname LIKE %s
                            ORDER BY t.shortname ASC";
                    
                    if ($mod === '') {
                        $sql_exec = $wpdb->prepare($sql . " LIMIT 5", $like_term, $like_term);
                        $rows = $wpdb->get_results($sql_exec);
                    } else {
                        // Optimize count query by eliminating temporary subquery and sorting
                        $sql_count = $wpdb->prepare("SELECT COUNT(*) 
                                                      FROM {$prefix}team_team t
                                                      WHERE t.shortname LIKE %s OR t.longname LIKE %s", $like_term, $like_term);
                        $total_count = (int) $wpdb->get_var($sql_count);
                        
                        $sql_exec = $wpdb->prepare($sql . " LIMIT %d OFFSET %d", $like_term, $like_term, $per_page, $offset);
                        $rows = $wpdb->get_results($sql_exec);
                    }
                    
                    foreach ($rows as $row) {
                        $grouped_posts['team'][] = array(
                            'shortname'    => $row->shortname,
                            'longname'     => $row->longname,
                            'permalink'    => home_url("/equipas/{$row->id}/"),
                            'thumb'        => f365_get_team_logo_url($row->id),
                            'country_id'   => $row->country_id,
                            'country_name' => $row->country_name,
                            'city'         => $row->city
                        );
                    }
                }
                
                // Clean empty groups
                $grouped_posts = array_filter($grouped_posts);
            }
            
            if ($is_valid_query && !empty($grouped_posts)) :
                
                foreach ( $grouped_posts as $post_type => $posts ) :
                    ?>
                    <div class="search-group search-group-<?php echo esc_attr( $post_type ); ?>">

                        <div class="silver-bar-width-titles bottom-space">
                            <div class="column-group">
                                <div class="all-50 condensed-700"><?php echo esc_html( $post_types[ $post_type ]['label'] ); ?></div>
                                <?php if ( empty( $mod ) ) : ?>
                                <div class="all-50 condensed-400 medium align-right">
                                    <a href="<?php echo esc_url(
                                    add_query_arg(
                                        array(
                                            's'   => $search_term,
                                            'mod' => $post_type,
                                        ),
                                        home_url('/')
                                    )
                                ); ?>">ver todos »</a>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="search-result">
                        <?php
                        foreach ( $posts as $post ) :
                            
                            // Render based on custom table schema fields
                            if ($post_type === 'news') :
                                ?>
                                <div class="column-group vertical-gutters news-list">
                                    <div class="xlarge-15 large-20 medium-20 small-30 tiny-100 img-block">
                                        <a href="<?php echo esc_url($post['permalink']); ?>">
                                            <img src="<?php echo esc_url($post['thumb']); ?>" width="120" alt="<?php echo esc_attr($post['title']); ?>" title="<?php echo esc_attr($post['title']); ?>" />
                                        </a>
                                    </div>
                                    <div class="xlarge-85 large-80 medium-80 small-70 tiny-100">
                                        <div class="condensed-700 large quarter-bottom-space">
                                            <a href="<?php echo esc_url($post['permalink']); ?>"><?php echo esc_html($post['title']); ?></a>
                                        </div>
                                        <div class="uppercase condensed-400 medium">
                                            <?php echo esc_html($post['date_formatted']); ?>
                                        </div>
                                    </div>
                                </div>
                                <?php
                            elseif ($post_type === 'video') :
                                ?>
                                <div class="column-group vertical-gutters news-list">
                                    <div class="xlarge-15 large-20 medium-20 small-30 tiny-100 img-block">
                                        <a href="<?php echo esc_url($post['permalink']); ?>">
                                            <img src="<?php echo esc_url($post['thumb']); ?>" width="120" alt="<?php echo esc_attr($post['title']); ?>" title="<?php echo esc_attr($post['title']); ?>" />
                                        </a>
                                    </div>
                                    <div class="xlarge-85 large-80 medium-80 small-70 tiny-100">
                                        <div class="condensed-700 large quarter-bottom-space">
                                            <a href="<?php echo esc_url($post['permalink']); ?>"><?php echo esc_html($post['title']); ?></a>
                                        </div>
                                        <div class="uppercase condensed-400 medium">
                                            <?php echo esc_html($post['date_formatted']); ?>
                                        </div>
                                    </div>
                                </div>
                                <?php
                            elseif ($post_type === 'team') :
                                ?>
                                <div class="column-group vertical-gutters news-list">
                                    <div class="xlarge-15 large-20 medium-20 small-30 tiny-100 img-block">
                                        <a href="<?php echo esc_url($post['permalink']); ?>">
                                            <img width="100" title="<?php echo esc_attr($post['shortname']); ?>" alt="<?php echo esc_attr($post['shortname']); ?>" src="<?php echo esc_url($post['thumb']); ?>">
                                        </a>
                                    </div>
                                    <div class="xlarge-85 large-80 medium-80 small-70 tiny-100">
                                        <div class="condensed-700 large quarter-bottom-space">
                                            <a href="<?php echo esc_url($post['permalink']); ?>"><?php echo esc_html($post['shortname']); ?></a>
                                        </div>
                                        <div class="uppercase condensed-400 medium quarter-bottom-space">
                                            <a href="<?php echo esc_url($post['permalink']); ?>"><?php echo esc_html($post['longname']); ?></a>
                                        </div>
                                        <div class="condensed-400 medium">
                                            <i class="flag-icon quarter-right-space flag-icon-<?php echo esc_attr($post['country_id']); ?>" title="<?php echo esc_attr($post['country_name']); ?>"></i>
                                            <?php echo esc_html($post['country_name']); ?>
                                            <?php if (!empty($post['city'])) : ?> - <?php echo esc_html($post['city']); ?><?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <?php
                            elseif ($post_type === 'player') :
                                ?>
                                <div class="column-group vertical-gutters news-list">
                                    <div class="xlarge-15 large-20 medium-20 small-30 tiny-100 img-block">
                                        <img src="<?php echo esc_url($post['thumb']); ?>" alt="<?php echo esc_attr($post['shortname']); ?>" title="<?php echo esc_attr($post['shortname']); ?>" width="100" />
                                    </div>
                                    <div class="xlarge-85 large-80 medium-80 small-70 tiny-100">
                                        <div class="condensed-700 large quarter-bottom-space">
                                            <a href="<?php echo esc_url($post['permalink']); ?>"><?php echo esc_html($post['shortname']); ?></a>
                                        </div>
                                        <div class="uppercase condensed-400 medium quarter-bottom-space">
                                            <?php echo esc_html($post['longname']); ?>
                                        </div>
                                        <div class="condensed-400 medium quarter-bottom-space">
                                            <?php if ($post['death_date']) : ?>
                                                Falecido
                                            <?php else : ?>
                                                <?php echo esc_html($post['age']); ?> anos
                                            <?php endif; ?>
                                            - <?php echo esc_html($post['position_name']); ?>
                                            <?php if ($post['team_shortname']) : ?>
                                                - <a href="<?php echo esc_url($post['team_permalink']); ?>"><?php echo esc_html($post['team_shortname']); ?></a>
                                            <?php endif; ?>
                                        </div>
                                        <div class="condensed-400 medium">
                                            <i class="flag-icon quarter-right-space flag-icon-<?php echo esc_attr($post['nationality_id']); ?>" title="<?php echo esc_attr($post['nationality_name']); ?>"></i>
                                            <?php echo esc_html($post['nationality_name']); ?>
                                            <?php if (!empty($post['birth_place'])) : ?> - <?php echo esc_html($post['birth_place']); ?><?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <?php
                            elseif ($post_type === 'coach') :
                                ?>
                                <div class="column-group vertical-gutters news-list">
                                    <div class="xlarge-15 large-20 medium-20 small-30 tiny-100 img-block">
                                        <img src="<?php echo esc_url($post['thumb']); ?>" alt="<?php echo esc_attr($post['coach_name']); ?>" title="<?php echo esc_attr($post['coach_name']); ?>" width="100" />
                                    </div>
                                    <div class="xlarge-85 large-80 medium-80 small-70 tiny-100">
                                        <div class="condensed-700 large quarter-bottom-space">
                                            <a href="<?php echo esc_url($post['permalink']); ?>"><?php echo esc_html($post['coach_name']); ?></a>
                                        </div>
                                        <div class="uppercase condensed-400 medium quarter-bottom-space">
                                            <?php echo esc_html($post['longname']); ?>
                                        </div>
                                        <div class="condensed-400 medium quarter-bottom-space">
                                            <?php if ($post['death_date']) : ?>
                                                Falecido
                                            <?php else : ?>
                                                <?php echo esc_html($post['age']); ?> anos
                                            <?php endif; ?>
                                            <?php if ($post['team_shortname']) : ?>
                                                - <a href="<?php echo esc_url($post['team_permalink']); ?>"><?php echo esc_html($post['team_shortname']); ?></a>
                                            <?php endif; ?>
                                        </div>
                                        <div class="condensed-400 medium">
                                            <i class="flag-icon quarter-right-space flag-icon-<?php echo esc_attr($post['nationality_id']); ?>" title="<?php echo esc_attr($post['nationality_name']); ?>"></i>
                                            <?php echo esc_html($post['nationality_name']); ?>
                                            <?php if (!empty($post['birth_place'])) : ?> - <?php echo esc_html($post['birth_place']); ?><?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <?php
                            endif;

                        endforeach;
                        ?>
                        </div>
                    </div>
                <?php endforeach; ?>

                <?php if ( ! empty( $mod ) && $total_count > $per_page ) : ?>
                    <div class="search-pagination">
                        <?php 

                        // Custom table-based pagination output without mocking global $wp_query
                        echo futebol365_render_pagination( $paged, ceil($total_count / $per_page) );
                        
                        ?>
                    </div>
                <?php endif; ?>

            <?php else : ?>
                <?php 
                // Validation error or empty results layout
                if (!$is_valid_query && !empty($search_term)) {
                    ?>
                    <div class="double-bottom-space">
                        <strong>Não foi possível obter resultados na sua pesquisa porque:</strong>
                        <ul>
                            <li>O termo de pesquisa é menor que 3 caracteres.</li>
                        </ul>
                    </div>
                    <?php
                } else {
                    get_template_part( 'template-parts/content', 'none' ); 
                }
                ?>
            <?php endif; ?>
            <?php get_template_part('template-parts/template-front-page-posts'); ?>
        </section>
        <section class="right-column-block">
            <?php get_sidebar();?>
        </section>
    </div>

<?php
get_footer();
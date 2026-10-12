<?php
/**
 * Team Vídeos template — /equipa/{id}/videos/
 *
 * @package Futebol365
 */

defined('ABSPATH') || exit;

get_header();
global $wpdb;

$post_id     = $args['post_id'] ?? get_the_ID();
$request_uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
$team_id     = 0;

if (preg_match('#/equipa/(\d+)/#', $request_uri, $matches)) {
    $team_id = (int) $matches[1];
}
if (function_exists('f365_get_current_team_post')) {
    $t = f365_get_current_team_post();
    if ($t) {
        $post_id = $t->ID;
    }
}

$current_section = get_query_var('section', 'videos') ?: 'videos';
$sections        = function_exists('futebol365_get_team_sections') ? futebol365_get_team_sections() : [];
if ($sections && ! isset($sections[$current_section])) {
    $current_section = 'videos';
}

$per_page = 20;
$page     = isset( $_GET['pagina'] ) ? max( 1, absint( $_GET['pagina'] ) ) : 1;
$offset   = ( $page - 1 ) * $per_page;

/* ============================================================
 * Cached Relational Fetch Pass — 24 Hours Object Cache
 * ============================================================ */
$cache_key   = 'f365_team_videos_' . $team_id . '_p_' . $page;
$cache_group = 'futebol365_media';
$cached_data = wp_cache_get($cache_key, $cache_group);

if (false === $cached_data) {
    $video_table       = $wpdb->prefix . 'multimedia_video';
    $video_teams_table = $wpdb->prefix . 'multimedia_video_teams';

    // 1. High Performance Count using Primary Keys
    $total_videos = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(1) FROM {$video_teams_table} WHERE team_id = %d",
            $team_id
        )
    );
    $num_pages = ( $total_videos > 0 ) ? (int) ceil( $total_videos / $per_page ) : 0;

    $videos = array();
    if ( $total_videos > 0 ) {
        // 2. TRUE Deferred Join Optimization:
        // Finds the exact 20 IDs first inside the mapping index table, THEN fetches video rows.
        $videos = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT v.id, v.title, v.url, v.image, v.datetime, v.`length`
                 FROM {$video_table} v
                 INNER JOIN (
                     SELECT video_id
                     FROM {$video_teams_table}
                     WHERE team_id = %d
                     ORDER BY video_id DESC
                     LIMIT %d OFFSET %d
                 ) vt ON v.id = vt.video_id
                 ORDER BY v.datetime DESC",
                $team_id,
                $per_page,
                $offset
            ),
            OBJECT
        );
    }

    $cached_data = [
        'total_videos' => $total_videos,
        'num_pages'    => $num_pages,
        'videos'       => $videos
    ];

    wp_cache_set($cache_key, $cached_data, $cache_group, DAY_IN_SECONDS);
}

// Unpack cached states
$total_videos = (int) $cached_data['total_videos'];
$num_pages    = (int) $cached_data['num_pages'];
$videos       = (array) $cached_data['videos'];
?>

<main id="primary" class="site-main single-article-page">

   <?php
   if ($sections) {
      get_template_part('template-parts/team/f365-team-header', null, [
         'post_id'         => $post_id,
         'current_section' => $current_section,
         'sections'        => $sections,
         'team_id'         => $team_id,
      ]);
   }
   ?>

   <div class="ink-grid futebol365-container">
      <section class="left-column-block">

         <div class="breadcrumbs-f365">
            <div class="ink-navigation breadcrumbs">
               <?php if (function_exists('yoast_breadcrumb')) { yoast_breadcrumb(); } ?>
            </div>
         </div>

         <h5 class="condensed-400 headerTitle blue">Vídeos</h5>

         <?php if (!empty($videos)) : ?>
            <div class="column-group gutters">
               <?php foreach ( $videos as $video ) :
                  $video_url   = esc_url( home_url( '/multimedia/videos/' . intval( $video->id ) . '/' ) );
                  $video_title = esc_attr( $video->title );
                  $video_date  = ! empty( $video->datetime ) ? mysql2date( 'd-m-Y', $video->datetime ) : '';
                  $video_image = esc_url( home_url( 'media/' . $video->image ) );
               ?>
                  <div class="all-25 small-50 tiny-100">
                     <figure class="ink-image half-bottom-space my-thumb-16-9">
                        <a href="<?php echo $video_url; ?>">
                           <img class="lazyload-item my-thumb-image loaded"
                                src="<?php echo $video_image; ?>"
                                title="<?php echo $video_title; ?>"
                                alt="<?php echo $video_title; ?>"
                                onerror="this.onerror=null; this.src='<?php echo esc_url( futebol365_fallback_image() ); ?>';">
                        </a>
                        <?php if ( ! empty( $video->length ) ) : ?>
                           <figcaption class="over-bottom dark figcaption-videos"><?php echo esc_html( $video->length ); ?></figcaption>
                        <?php endif; ?>
                     </figure>
                     <h3 class="quarter-vertical-space medium uppercase condensed-400"><?php echo esc_html( $video_date ); ?></h3>
                     <h2 class="large condensed-700"><a href="<?php echo $video_url; ?>"><?php echo esc_html( $video->title ); ?></a></h2>
                  </div>
               <?php endforeach; ?>
            </div>

            <div class="all-100">
               <?php
               if ( $num_pages > 1 ) :
                  $base_url = remove_query_arg('pagina');

                  $visible_pages = 3;
                  $first = ( $page - $visible_pages ) < 0 ? 0 : ( $page - $visible_pages );
                  $last  = ( $page + ( $visible_pages - 1 ) ) > ( $num_pages - 1 ) ? $num_pages : ( $page + ( $visible_pages - 1 ) );

                  $page_ranges = array();
                  for ( $i = $first; $i < $last; $i++ ) {
                     $page_ranges[] = $i + 1;
                  }

                  $page_limit_sup = $num_pages - $visible_pages;
                  ?>
                  <div class="paginacao-new">
                     <nav class="ink-navigation">
                        <ul class="pagination">

                           <?php if ( $page > 1 ) : ?>
                              <li class="hide-tiny hide-small"><a href="<?php echo esc_url( add_query_arg( 'pagina', $page - 1, $base_url ) ); ?>">&laquo; Anterior</a></li>
                           <?php endif; ?>

                           <?php if ( $page > 3 ) : ?>
                              <li><a href="<?php echo esc_url( add_query_arg( 'pagina', 1, $base_url ) ); ?>">1</a></li>
                           <?php endif; ?>

                           <?php if ( $page > 4 ) : ?>
                              <li><span>...</span></li>
                           <?php endif; ?>

                           <?php foreach ( $page_ranges as $pr ) : ?>
                              <?php if ( (int) $pr === (int) $page ) : ?>
                                 <li><span class="active"><?php echo esc_html( $pr ); ?></span></li>
                              <?php else : ?>
                                 <li><a href="<?php echo esc_url( add_query_arg( 'pagina', $pr, $base_url ) ); ?>"><?php echo esc_html( $pr ); ?></a></li>
                              <?php endif; ?>
                           <?php endforeach; ?>

                           <?php if ( $page < $page_limit_sup ) : ?>
                              <li><span>...</span></li>
                              <li><a href="<?php echo esc_url( add_query_arg( 'pagina', $num_pages, $base_url ) ); ?>"><?php echo esc_html( $num_pages ); ?></a></li>
                           <?php endif; ?>

                           <?php if ( $page < $num_pages ) : ?>
                              <li class="hide-tiny hide-small"><a href="<?php echo esc_url( add_query_arg( 'pagina', $page + 1, $base_url ) ); ?>">Seguinte &raquo;</a></li>
                           <?php endif; ?>

                        </ul>
                     </nav>
                  </div>
               <?php endif; ?>
            </div>

         <?php else : ?>
            <h6 class="no-info">Sem informação disponível</h6>
         <?php endif; ?>

         <?php get_template_part('template-parts/template-front-page-posts'); ?>
      </section>

      <section class="right-column-block">
         <?php
         if (is_active_sidebar('post-sidebar')) {
            dynamic_sidebar('post-sidebar');
         } else {
            get_sidebar();
         }
         ?>
      </section>
   </div>
</main>

<?php
get_footer();

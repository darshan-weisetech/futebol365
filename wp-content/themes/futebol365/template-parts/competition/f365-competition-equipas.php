<?php
/**
 * Competition — Equipas
 * @package Futebol365
 */

defined('ABSPATH') || exit;

get_header();
global $wpdb;

$current_post_id = get_the_ID();
$current_section = 'equipas';
$sections = futebol365_get_competition_sections();

//$final_competition_id = get_post_meta($current_post_id, 'django_competition_id', true);
$final_competition_id = get_legacy_competition_id( $current_post_id );
$competition_id = intval($final_competition_id);

$comp_table = $wpdb->prefix . 'competition_competition';
$phase_table = $wpdb->prefix . 'competition_competitionphase';
$match_table = $wpdb->prefix . 'competition_match';
$ts_table = $wpdb->prefix . 'team_teamstep';
$team_table = $wpdb->prefix . 'team_team';

if (!function_exists('f365_comp_media_url')) {
   function f365_comp_media_url($path = '')
   {
      return '/media/' . ltrim((string) $path, '/');
   }
}

if (!function_exists('f365_comp_team_logo_url')) {
   function f365_comp_team_logo_url($team_id)
   {
      return f365_comp_media_url('images/logotipo/100x100/' . intval($team_id) . '.gif');
   }
}

if (!function_exists('f365_comp_fallback_image')) {
   function f365_comp_fallback_image()
   {
      if (function_exists('futebol365_fallback_image')) {
         return futebol365_fallback_image();
      }

      return f365_comp_media_url('images/logotipo/100x100/no-image.gif');
   }
}

$comp_row = $wpdb->get_row(
   $wpdb->prepare(
      "SELECT federation_competition_id, edition FROM {$comp_table} WHERE id = %d",
      $competition_id
   ),
   OBJECT
);


$fed_comp_id = $comp_row ? intval($comp_row->federation_competition_id) : 0;
$comp_edition = $comp_row ? (string) $comp_row->edition : '';

$teams_base_url = home_url('/competicao/' . intval($fed_comp_id) . '/equipas/');

if (!function_exists('f365_teamstep_url')) {
   function f365_teamstep_url($team_id, $edition, $modality, $gender, $step)
   {
      $qs = http_build_query(
         array(
            'edition' => $edition,
            'modality' => $modality,
            'gender' => $gender,
            'step' => $step,
         )
      );

      return home_url('/equipa/' . intval($team_id) . '/jogos/?' . $qs);
   }
}

if (!function_exists('f365_truncatechars')) {
   function f365_truncatechars($text, $limit)
   {
      $text = (string) $text;

      if (function_exists('mb_strlen')) {
         if (mb_strlen($text) <= $limit) {
            return $text;
         }

         return rtrim(mb_substr($text, 0, max(0, $limit - 1))) . '…';
      }

      if (strlen($text) <= $limit) {
         return $text;
      }

      return rtrim(substr($text, 0, max(0, $limit - 1))) . '…';
   }
}

$phase_choices = $wpdb->get_results(
   $wpdb->prepare(
      "SELECT id, name, phase_type FROM {$phase_table}
		  WHERE competition_id = %d
		    AND phase_type NOT IN ( 'single_round', 'single_round_with_groups' )
		  ORDER BY `order` ASC",
      $competition_id
   ),
   OBJECT
);

$has_phases = !empty($phase_choices);

$sel_phase = (isset($_GET['competition_phase']) && '' !== $_GET['competition_phase'] && 'all' !== $_GET['competition_phase'])
   ? sanitize_text_field(wp_unslash($_GET['competition_phase']))
   : null;

$sel_group = (isset($_GET['group']) && '' !== $_GET['group'] && 'all' !== $_GET['group'])
   ? sanitize_text_field(wp_unslash($_GET['group']))
   : null;

$sel_phase_id = null;
$sel_phase_type = null;

if ($sel_phase && $has_phases) {
   foreach ($phase_choices as $ph) {
      if ($ph->name === $sel_phase) {
         $sel_phase_id = intval($ph->id);
         $sel_phase_type = $ph->phase_type;
         break;
      }
   }

   if (null === $sel_phase_id) {
      $sel_phase = null;
   }
}

$group_choices = array();

if ($sel_phase_id) {
   $group_choices = $wpdb->get_col(
      $wpdb->prepare(
         "SELECT DISTINCT group_home_team FROM {$match_table}
			  WHERE competition_id = %d
			    AND competition_phase_id = %d
			    AND group_home_team IS NOT NULL
			    AND group_home_team <> ''
			  ORDER BY group_home_team ASC",
         $competition_id,
         $sel_phase_id
      )
   );
}

$show_group = !empty($group_choices);

if (!$show_group) {
   $sel_group = null;
}

$match_where = 'competition_id = %d';
$match_params = array($competition_id);

if ($sel_phase_id) {
   $match_where .= ' AND competition_phase_id = %d';
   $match_params[] = $sel_phase_id;
}

if ($sel_group) {
   $match_where .= ' AND ( group_home_team = %s OR group_visitor_team = %s )';
   $match_params[] = $sel_group;
   $match_params[] = $sel_group;
}

$union_params = array_merge($match_params, $match_params);

$participants = $wpdb->get_results(
   $wpdb->prepare(
      "SELECT ts.id AS teamstep_id, ts.team_id, ts.step, ts.gender, ts.modality, ts.edition,
		        t.shortname, t.city, t.foundation, t.team_type
		   FROM (
		       SELECT home_team_id AS tsid FROM {$match_table} WHERE {$match_where}
		       UNION
		       SELECT visitor_team_id AS tsid FROM {$match_table} WHERE {$match_where}
		   ) mt
		   JOIN {$ts_table} ts ON mt.tsid = ts.id
		   JOIN {$team_table} t ON ts.team_id = t.id
		  ORDER BY t.shortname ASC",
      $union_params
   ),
   OBJECT
);

get_template_part(
   'template-parts/competition/f365-competition-header',
   null,
   array(
      'post_id' => $current_post_id,
      'sections' => $sections,
      'competition_id' => $final_competition_id,
      'current_section' => $current_section,
   )
);
?>

<main id="primary" class="site-main single-article-page" <?php echo intval($current_post_id); ?>>
   <div class="ink-grid futebol365-container">
      <section class="left-column-block">
         <div class="breadcrumbs-f365">
            <div class="ink-navigation breadcrumbs">
               <?php if (function_exists('yoast_breadcrumb')) {
                  yoast_breadcrumb();
               } ?>
            </div>
         </div>

         <?php if ($has_phases): ?>
            <form class="ink-form ink-form-option" method="get" name="teams_level2_form" id="teams_level2_form"
               action="<?php echo esc_url($teams_base_url); ?>">
               <div class="column-group">
                  <div class="control-group all-33 tiny-100 small-100">
                     <div class="control">
                        <select name="competition_phase" id="id_competition_phase">
                           <option value="all" <?php selected(null === $sel_phase); ?>><?php esc_html_e( 'Fase', 'futebol365' ); ?></option>
                           <?php foreach ($phase_choices as $ph): ?>
                              <option value="<?php echo esc_attr($ph->name); ?>" <?php selected($sel_phase, $ph->name); ?>>
                                 <?php echo esc_html($ph->name); ?>
                              </option>
                           <?php endforeach; ?>
                        </select>
                     </div>
                  </div>

                  <?php if ($show_group): ?>
                     <div class="control-group all-33 tiny-100 small-100">
                        <div class="control">
                           <select name="group" id="id_group">
                              <option value="all" <?php selected(null === $sel_group); ?>><?php echo esc_html__( 'Grupos', 'futebol365' ); ?></option>
                              <?php foreach ($group_choices as $g): ?>
                                 <option value="<?php echo esc_attr($g); ?>" <?php selected($sel_group, $g); ?>>
                                    <?php echo esc_html($g); ?>
                                 </option>
                              <?php endforeach; ?>
                           </select>
                        </div>
                     </div>
                  <?php endif; ?>
               </div>
            </form>
         <?php endif; ?>

         <h3 class="condensed-400 headerTitle blue"><?php echo esc_html__( 'Equipas', 'futebol365' ); ?></h3>

         <?php if (!empty($participants)): ?>
            <div class="column-group half-gutters">
               <?php foreach ($participants as $p): ?>
                  <?php
                  $team_url = f365_teamstep_url($p->team_id, $p->edition, $p->modality, $p->gender, $p->step);

                  if ('national' === $p->team_type) {
                     $team_url .= '&competition=' . intval($competition_id);
                  }

                  $team_url = esc_url($team_url);
                  $logo_url = f365_comp_team_logo_url($p->team_id);
                  $logo = get_the_post_thumbnail_url( $p->team_id, 'post-thumbnail' );
                  if ( ! $logo && function_exists( 'get_image_in_path' ) ) {
                        $logo = get_image_in_path( 'images/logotipo/100x100/',  $p->team_id, 'gif' );
                  }
                  $team_name = $p->shortname;
                  $city = f365_truncatechars($p->city, 32);
                  $found_year = !empty($p->foundation) ? mysql2date('Y', $p->foundation) : '';
                  ?>
                  <div class="all-50 xlarge-33 small-100 tiny-100">
                     <div class="obj-right-div">
                        <div class="obj-image-team">
                           <figure class="ink-image ink-lazyload" data-delta="100">
                              <a href="<?php echo $team_url; ?>">
                                 <img src="<?php echo esc_url( home_url( $logo_url ) ); ?>" alt="<?php echo esc_attr($team_name); ?>"
                                    title="<?php echo esc_attr($team_name); ?>"
                                    onerror="this.onerror=null;this.src='<?php echo esc_url( futebol365_fallback_image() ); ?>';"
                                    />
                              </a>
                           </figure>
                        </div>

                        <div class="obj-detail">
                           <div class="obj-name">
                              <a href="<?php echo $team_url; ?>"><?php echo esc_html($team_name); ?></a>
                           </div>

                           <div class="obj-link"><?php echo esc_html($city); ?></div>

                           <?php if (!empty($found_year)): ?>
                              <div class="obj-link"><?php echo esc_html__( 'Fundação:', 'futebol365' ); ?> <?php echo esc_html($found_year); ?></div>
                           <?php endif; ?>
                        </div>
                     </div>
                  </div>
               <?php endforeach; ?>
            </div>
         <?php else: ?>
            <h5 class="no-info"><?php echo esc_html__( 'Sem informação disponível', 'futebol365' ); ?></h5>
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

<script type="text/javascript">
   (function () {
      var baseUrl = '<?php echo esc_url($teams_base_url); ?>';

      function editionVal(p) {
         var val = '';
         var el = document.getElementById('edition');

         if (el && el.value) {
            val = el.value;
         } else {
            val = p.get('edition') || '<?php echo esc_js($comp_edition); ?>';
         }

         if (val.indexOf('http') !== -1) {
            var match = val.match(/[?&]edition=(\d{4})/);
            if (match && match[1]) {
               return match[1];
            }
         }

         return val;
      }

      var phaseSel = document.getElementById('id_competition_phase');
      var groupSel = document.getElementById('id_group');

      function rebuild(changedName) {
         var p = new URLSearchParams(window.location.search);

         p.set('edition', editionVal(p));
         p.set('competition_phase', phaseSel ? phaseSel.value : 'all');
         p.set('group', groupSel ? groupSel.value : 'all');

         if ('competition_phase' === changedName) {
            p.set('group', 'all');
         }

         var qs = p.toString();
         window.location.href = qs ? (baseUrl + '?' + qs) : baseUrl;
      }

      if (phaseSel) {
         phaseSel.addEventListener('change', function () {
            rebuild('competition_phase');
         });
      }

      if (groupSel) {
         groupSel.addEventListener('change', function () {
            rebuild('group');
         });
      }
   })();
</script>

<?php
get_footer();
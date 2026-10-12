<?php
/**
 * Team Comparar-com (Frente-a-frente) ROUTER — /equipa/{id}/comparar-com/{id2}/[section/]
 *
 * FIXED: Palmarés matching exact Python aggregation framework rules.
 *
 * @package Futebol365
 */

defined('ABSPATH') || exit;

global $wpdb;

$sections_file = __DIR__ . '/f365-team-compare-sections.php';
if (file_exists($sections_file)) {
   require_once $sections_file;
}

$current_section = get_query_var('section', '');
if ('' === $current_section) {
   $current_section = get_query_var('f365_section', '');
}
if ('' === $current_section) {
   $current_section = 'palmares';
}

$compare_sections = function_exists('futebol365_get_team_compare_sections')
   ? futebol365_get_team_compare_sections()
   : array(
      'resumo' => 'Resumo',
      'confrontos' => 'Confrontos',
      'forma' => 'Forma',
      'palmares' => 'Palmar&eacute;s',
      'estadios' => 'Est&aacute;dios',
   );

if (!isset($compare_sections[$current_section])) {
   $current_section = 'palmares';
}

if (!function_exists('f365_compare_request_ids')) {
   function f365_compare_request_ids(): array
   {
      $first = (int) (get_query_var('f365_team_id') ?: get_query_var('team_id'));
      $second = (int) (get_query_var('f365_team2_id') ?: get_query_var('second_team_id'));

      if ((!$first || !$second) && !empty($_SERVER['REQUEST_URI'])) {
         $path = wp_parse_url(esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])), PHP_URL_PATH);
         if (preg_match('#/equipa/(\d+)/comparar-com/(\d+)(?:/|$)#', (string) $path, $m)) {
            $first = (int) $m[1];
            $second = (int) $m[2];
         }
      }

      return array($first, $second);
   }
}

if (!function_exists('f365_compare_team_url')) {
   function f365_compare_team_url($team_id, $teamstep): string
   {
      $path = add_query_arg(
         array(
            'edition' => $teamstep->edition ?? '',
            'modality' => $teamstep->modality ?? 'football',
            'gender' => $teamstep->gender ?? 'male',
            'step' => $teamstep->step ?? 'principal',
         ),
         '/equipa/' . (int) $team_id . '/jogos/'
      );

      return site_url($path);
   }
}

if (!function_exists('f365_compare_team_logo_html')) {
   function f365_compare_team_logo_html($team_id, $team_name = ''): string
   {
      $team_id = absint($team_id);
      $wp_id = function_exists('f365_team_resolve_id') ? (int) f365_team_resolve_id($team_id) : 0;
      
      if (function_exists('f365_cmp_team_logo')) {
         $logo_url = f365_cmp_team_logo($wp_id, $team_id);
      } else {
         $logo_url = $wp_id ? (string) get_the_post_thumbnail_url($wp_id, 'full') : '';
      }
      
      if (empty($logo_url)) {
         $logo_url = function_exists('futebol365_fallback_image') ? (string) futebol365_fallback_image() : get_template_directory_uri() . '/assets/img/no-image.gif';
      }

      return sprintf(
         '<img class="team-logo" src="%s" alt="%s" title="%s" loading="lazy">',
         esc_url($logo_url),
         esc_attr($team_name),
         esc_attr($team_name)
      );
   }
}

if (!function_exists('f365_compare_format_teamstep_name')) {
   function f365_compare_format_teamstep_name($team, $teamstep): string
   {
      $name = $team->shortname ?? '';
      $suffix = '';

      if ('futsal' === ($teamstep->modality ?? '')) {
         $suffix .= ' FS';
      }
      if ('female' === ($teamstep->gender ?? '')) {
         $suffix .= ' FM';
      }

      if ('b_team' === ($teamstep->step ?? '')) {
         $suffix .= ' B';
      } elseif ('c_team' === ($teamstep->step ?? '')) {
         $suffix .= ' C';
      } elseif ('veterans' === ($teamstep->step ?? '')) {
         $suffix .= ' VET';
      } elseif ('reservations' === ($teamstep->step ?? '')) {
         $suffix .= ' RES';
      }

      return trim($name . $suffix);
   }
}

if (!function_exists('f365_compare_get_teamstep')) {
   function f365_compare_get_teamstep(int $team_id, string $edition, string $step, string $modality, string $gender)
   {
      global $wpdb;

      $teamstep_tbl = $wpdb->prefix . 'team_teamstep';

      $teamstep = $wpdb->get_row(
         $wpdb->prepare(
            "SELECT *
             FROM {$teamstep_tbl}
             WHERE team_id = %d
               AND edition = %s
               AND step = %s
               AND modality = %s
               AND gender = %s
             LIMIT 1",
            $team_id,
            $edition,
            $step,
            $modality,
            $gender
         )
      );

      if ($teamstep) {
         return $teamstep;
      }

      return $wpdb->get_row(
         $wpdb->prepare(
            "SELECT *
             FROM {$teamstep_tbl}
             WHERE team_id = %d
               AND step = %s
               AND modality = %s
               AND gender = %s
             ORDER BY edition DESC
             LIMIT 1",
            $team_id,
            $step,
            $modality,
            $gender
         )
      );
   }
}

if (!function_exists('f365_compare_count_awards_for_team')) {
   function f365_compare_count_awards_for_team(int $fed_comp_id, int $team_id): int
   {
      global $wpdb;

      $competition_tbl = $wpdb->prefix . 'competition_competition';
      $winner_tbl = $wpdb->prefix . 'competition_competitionphasewinners';
      $teamstep_tbl = $wpdb->prefix . 'team_teamstep';

      return (int) $wpdb->get_var(
         $wpdb->prepare(
            "SELECT COUNT(DISTINCT c.id)
             FROM {$competition_tbl} c
             LEFT JOIN {$winner_tbl} cpw ON cpw.competition_id = c.id
             LEFT JOIN {$teamstep_tbl} cpw_ts ON cpw_ts.id = cpw.first_place_teamstep_id
             LEFT JOIN {$teamstep_tbl} c_ts ON c_ts.id = c.first_place_teamstep_id
             WHERE c.federation_competition_id = %d
               AND (
                  cpw_ts.team_id = %d
                  OR c_ts.team_id = %d
               )",
            $fed_comp_id,
            $team_id,
            $team_id
         )
      );
   }
}

if (!function_exists('f365_compare_get_awards_table')) {
   function f365_compare_get_awards_table(int $first_team_id, int $second_team_id, array $steps, array $genders, array $modalities): array
   {
      global $wpdb;

      $fed_comp_tbl = $wpdb->prefix . 'federation_federationcompetition';
      $federation_tbl = $wpdb->prefix . 'federation_federation';
      $competition_tbl = $wpdb->prefix . 'competition_competition';
      $winner_tbl = $wpdb->prefix . 'competition_competitionphasewinners';
      $teamstep_tbl = $wpdb->prefix . 'team_teamstep';

      $team_ids = array($first_team_id, $second_team_id);

      $team_placeholders = implode(',', array_fill(0, count($team_ids), '%d'));
      $step_placeholders = implode(',', array_fill(0, count($steps), '%s'));
      $gender_placeholders = implode(',', array_fill(0, count($genders), '%s'));
      $modality_placeholders = implode(',', array_fill(0, count($modalities), '%s'));

      $params = array_merge(
         $team_ids,
         $steps,
         $genders,
         $modalities,
         $team_ids,
         $steps,
         $genders,
         $modalities
      );

      $fed_comps = $wpdb->get_results(
         $wpdb->prepare(
            "SELECT *
             FROM (
               SELECT
                  fc.id,
                  fc.shortname,
                  fc.`order`,
                  fc.scope,
                  fc.step,
                  fc.gender,
                  fc.modality,
                  f.shortname AS federation_shortname,
                  f.federation_type
                FROM {$fed_comp_tbl} fc
                INNER JOIN {$federation_tbl} f ON f.id = fc.federation_id
                INNER JOIN {$competition_tbl} c ON c.federation_competition_id = fc.id
                INNER JOIN {$winner_tbl} cpw ON cpw.competition_id = c.id
                INNER JOIN {$teamstep_tbl} cpw_ts ON cpw_ts.id = cpw.first_place_teamstep_id
                WHERE cpw_ts.team_id IN ({$team_placeholders})
                  AND fc.step IN ({$step_placeholders})
                  AND fc.gender IN ({$gender_placeholders})
                  AND fc.modality IN ({$modality_placeholders})

                UNION

                SELECT
                  fc.id,
                  fc.shortname,
                  fc.`order`,
                  fc.scope,
                  fc.step,
                  fc.gender,
                  fc.modality,
                  f.shortname AS federation_shortname,
                  f.federation_type
                FROM {$fed_comp_tbl} fc
                INNER JOIN {$federation_tbl} f ON f.id = fc.federation_id
                INNER JOIN {$competition_tbl} c ON c.federation_competition_id = fc.id
                INNER JOIN {$teamstep_tbl} c_ts ON c_ts.id = c.first_place_teamstep_id
                WHERE c_ts.team_id IN ({$team_placeholders})
                  AND fc.step IN ({$step_placeholders})
                  AND fc.gender IN ({$gender_placeholders})
                  AND fc.modality IN ({$modality_placeholders})
             ) fed_comps
             ORDER BY
               CASE
                  WHEN scope = 'international_national_team' THEN 0
                  WHEN scope = 'international_clubs' THEN 1
                  WHEN scope = 'national_championship' THEN 2
                  WHEN scope = 'national_cup' THEN 3
                  WHEN scope = 'national_league_cup' THEN 4
                  WHEN scope = 'national_supercup' THEN 5
                  ELSE 99
               END,
               CASE
                  WHEN federation_shortname = 'FIFA' THEN 0
                  WHEN federation_type = 'confederation' THEN 1
                  WHEN federation_type = 'federation' THEN 2
                  WHEN federation_type = 'association' THEN 3
                  ELSE 4
               END,
               `order` ASC,
               shortname ASC",
            $params
         )
      );

      if (!empty($wpdb->last_error)) {
         echo '<pre style="background:#fee;padding:10px;">SQL Error: ' . esc_html($wpdb->last_error) . '</pre>';
      }

      $rows = array();

      if (!empty($fed_comps)) {
         $fed_comp_ids = wp_list_pluck($fed_comps, 'id');
         $placeholders = implode(',', array_fill(0, count($fed_comp_ids), '%d'));
         $results = $wpdb->get_results(
            $wpdb->prepare(
               "SELECT
                  c.federation_competition_id AS fed_comp_id,
                  COUNT(DISTINCT CASE WHEN cpw_ts.team_id = %d OR c_ts.team_id = %d THEN c.id END) AS first_team_wins,
                  COUNT(DISTINCT CASE WHEN cpw_ts.team_id = %d OR c_ts.team_id = %d THEN c.id END) AS second_team_wins
                FROM {$competition_tbl} c
                LEFT JOIN {$winner_tbl} cpw ON cpw.competition_id = c.id
                LEFT JOIN {$teamstep_tbl} cpw_ts ON cpw_ts.id = cpw.first_place_teamstep_id
                LEFT JOIN {$teamstep_tbl} c_ts ON c_ts.id = c.first_place_teamstep_id
                WHERE c.federation_competition_id IN ({$placeholders})
                GROUP BY c.federation_competition_id",
               array_merge(
                  array($first_team_id, $first_team_id, $second_team_id, $second_team_id),
                  $fed_comp_ids
               )
            )
         );

         $wins_map = array();
         foreach ($results as $row) {
            $wins_map[(int) $row->fed_comp_id] = array(
               'first' => (int) $row->first_team_wins,
               'second' => (int) $row->second_team_wins,
            );
         }

         foreach ($fed_comps as $fed_comp) {
            $fed_id = (int) $fed_comp->id;
            $first_wins = $wins_map[$fed_id]['first'] ?? 0;
            $second_wins = $wins_map[$fed_id]['second'] ?? 0;

            if (0 === $first_wins && 0 === $second_wins) {
               continue;
            }

            $rows[] = (object) array(
               'fed_comp_id' => $fed_id,
               'fed_comp_name' => $fed_comp->shortname,
               'first_team_wins' => $first_wins,
               'second_team_wins' => $second_wins,
            );
         }
      }

      return $rows;
   }
}

list($first_team_id, $second_team_id) = f365_compare_request_ids();

$edition = isset($_GET['edition']) ? sanitize_text_field(wp_unslash($_GET['edition'])) : wp_date('Y');
$step1 = isset($_GET['step1']) ? sanitize_text_field(wp_unslash($_GET['step1'])) : 'principal';
$step2 = isset($_GET['step2']) ? sanitize_text_field(wp_unslash($_GET['step2'])) : 'principal';
$gender1 = isset($_GET['gender1']) ? sanitize_text_field(wp_unslash($_GET['gender1'])) : 'male';
$gender2 = isset($_GET['gender2']) ? sanitize_text_field(wp_unslash($_GET['gender2'])) : 'male';
$modality1 = isset($_GET['modality1']) ? sanitize_text_field(wp_unslash($_GET['modality1'])) : 'football';
$modality2 = isset($_GET['modality2']) ? sanitize_text_field(wp_unslash($_GET['modality2'])) : 'football';

$team_tbl = $wpdb->prefix . 'team_team';

$first_team = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$team_tbl} WHERE id = %d", $first_team_id));
$second_team = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$team_tbl} WHERE id = %d", $second_team_id));

$teamstep1 = f365_compare_get_teamstep($first_team_id, $edition, $step1, $modality1, $gender1);
$teamstep2 = f365_compare_get_teamstep($second_team_id, $edition, $step2, $modality2, $gender2);

$awards_table = array();
$first_team_total = 0;
$second_team_total = 0;

if ($first_team && $second_team) {
   $awards_table = f365_compare_get_awards_table(
      $first_team_id,
      $second_team_id,
      array_unique(array($step1, $step2)),
      array_unique(array($gender1, $gender2)),
      array_unique(array($modality1, $modality2))
   );

   foreach ($awards_table as $awards_line) {
      $first_team_total += (int) $awards_line->first_team_wins;
      $second_team_total += (int) $awards_line->second_team_wins;
   }
}

get_header();
?>
<main id="primary" class="site-main single-article-page">
   <?php
   get_template_part(
      'template-parts/team/f365-team-compare-header',
      null,
      array(
         'sections' => $compare_sections,
         'current_section' => $current_section,
      )
   );
   ?>

   <div class="ink-grid futebol365-container" style="height: auto !important;">
      <section class="left-column-block" style="height: auto !important;">
         <div class="breadcrumbs-f365">
            <div class="ink-navigation breadcrumbs">
               <?php
               if (function_exists('yoast_breadcrumb')) {
                  yoast_breadcrumb();
               }
               ?>
            </div>
         </div>

         <?php if (!$first_team || !$second_team): ?>
            <h5 class="no-info">N&atilde;o foi poss&iacute;vel encontrar as equipas seleccionadas.</h5>
         <?php else: ?>
            <?php
            $teamstep1_name = $teamstep1 ? f365_compare_format_teamstep_name($first_team, $teamstep1) : ($first_team->shortname ?? '');
            $teamstep2_name = $teamstep2 ? f365_compare_format_teamstep_name($second_team, $teamstep2) : ($second_team->shortname ?? '');

            $teamstep1_url_obj = $teamstep1 ?: (object) array(
               'edition' => $edition,
               'modality' => $modality1,
               'gender' => $gender1,
               'step' => $step1,
            );

            $teamstep2_url_obj = $teamstep2 ?: (object) array(
               'edition' => $edition,
               'modality' => $modality2,
               'gender' => $gender2,
               'step' => $step2,
            );
            ?>

            <div class="column-group match-div">
               <div class="all-40 small-30 tiny-30 align-center">
                  <a href="<?php echo esc_url(f365_compare_team_url($first_team->id, $teamstep1_url_obj)); ?>">
                     <h5 class="match-team-name"><?php echo esc_html($teamstep1_name); ?></h5>
                  </a>
               </div>

               <div class="all-20 small-40 tiny-40 align-center"></div>

               <div class="all-40 small-30 tiny-30 align-center">
                  <a href="<?php echo esc_url(f365_compare_team_url($second_team->id, $teamstep2_url_obj)); ?>">
                     <h5 class="match-team-name"><?php echo esc_html($teamstep2_name); ?></h5>
                  </a>
               </div>

               <div class="all-40 small-30 tiny-30 align-center">
                  <a href="<?php echo esc_url(f365_compare_team_url($first_team->id, $teamstep1_url_obj)); ?>">
                     <?php echo f365_compare_team_logo_html((int) $first_team->id, $teamstep1_name); ?>
                  </a>
               </div>

               <div class="all-20 small-40 tiny-40 align-center">
                  <h2 class="match-score"><span class="ink-badge black no-margin quarter-vertical-padding">VS</span></h2>
               </div>

               <div class="all-40 small-30 tiny-30 align-center">
                  <a href="<?php echo esc_url(f365_compare_team_url($second_team->id, $teamstep2_url_obj)); ?>">
                     <?php echo f365_compare_team_logo_html((int) $second_team->id, $teamstep2_name); ?>
                  </a>
               </div>
            </div>

            <h5 class="condensed-400 headerTitle blue">Palmar&eacute;s</h5>

            <div class="column-group">
               <table class="ink-table ink-table-f365 alternating">
                  <thead>
                     <tr>
                        <th class="align-center all-33"><?php echo esc_html($teamstep1_name); ?></th>
                        <th class="align-center all-33"></th>
                        <th class="align-center all-33"><?php echo esc_html($teamstep2_name); ?></th>
                     </tr>
                  </thead>
                  <tbody>
                     <?php if (!empty($awards_table)): ?>
                        <?php foreach ($awards_table as $awards_line): ?>
                           <tr>
                              <td class="align-center"><?php echo esc_html((string) $awards_line->first_team_wins); ?></td>
                              <td class="align-center">
                                 <a
                                    href="<?php echo esc_url(site_url('/competicao/' . (int) $awards_line->fed_comp_id . '/')); ?>">
                                    <?php echo esc_html($awards_line->fed_comp_name); ?>
                                 </a>
                              </td>
                              <td class="align-center"><?php echo esc_html((string) $awards_line->second_team_wins); ?></td>
                           </tr>
                        <?php endforeach; ?>

                        <tr>
                           <td class="align-center"><?php echo esc_html((string) $first_team_total); ?></td>
                           <td class="align-center">Total</td>
                           <td class="align-center"><?php echo esc_html((string) $second_team_total); ?></td>
                        </tr>
                     <?php else: ?>
                        <tr>
                           <td class="align-center" colspan="3">Sem dados de palmar&eacute;s.</td>
                        </tr>
                     <?php endif; ?>
                  </tbody>
               </table>
            </div>
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
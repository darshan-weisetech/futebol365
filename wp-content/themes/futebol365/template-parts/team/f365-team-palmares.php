
<?php
/**
 * Team Palmarés template — /equipa/{id}/palmares/
 *
 * Highly Optimized Custom Relational SQL Engine:
 * - Directly references custom database tables: wp_team_team, wp_team_teamstep, wp_competition_competition, wp_federation_federationcompetition.
 * - Extracts clean historical names from wp_competition_competition.name natively.
 * - Sorted by Competition Federation ID ascending.
 *
 * @package Futebol365
 */

defined('ABSPATH') || exit;
global $wpdb;
$current_post_id = get_the_ID();
$current_section = 'palmares';
$sections        = function_exists('futebol365_get_team_sections') ? futebol365_get_team_sections() : [];
$team_id         = get_post_meta( $current_post_id, 'django_id', true );

/* ============================================================
 * Helpers
 * ============================================================ */

if (! function_exists('f365_country_flag_url')) :
   function f365_country_flag_url(int $term_id): string {
      static $cache = [];
      if (! $term_id || ! function_exists('get_field')) {
         return '';
      }
      if (isset($cache[$term_id])) {
         return $cache[$term_id];
      }
      $flag = get_field('country_flag', 'term_' . $term_id);
      if (is_numeric($flag)) {
         return $cache[$term_id] = (string) wp_get_attachment_image_url($flag, 'full');
      }
      if (is_array($flag) && ! empty($flag['url'])) {
         return $cache[$term_id] = $flag['url'];
      }
      if (is_string($flag)) {
         return $cache[$term_id] = $flag;
      }
      return $cache[$term_id] = '';
   }
endif;

if (! function_exists('f365_get_team_flag_url')) :
   /**
    * Gets the flag URL directly assigned to the target Team Post ID
    */
   function f365_get_team_flag_url(int $team_post_id): string {
      $terms = get_the_terms($team_post_id, 'f365_country');
      if ($terms && ! is_wp_error($terms)) {
         $term = reset($terms);
         if ($term) {
            return f365_country_flag_url((int) $term->term_id);
         }
      }
      return '';
   }
endif;

if (! function_exists('f365_get_team_palmares')) :
/**
 * Optimized Palmares Engine connecting Team -> Teamstep -> Competition -> Federation custom tables
 */
function f365_get_team_palmares(int $team_custom_id, string $step, string $modality, string $gender, string $team_flag = ''): array {

    global $wpdb;

    $team_table            = $wpdb->prefix . 'team_team';
    $teamstep_table        = $wpdb->prefix . 'team_teamstep';
    $competition_table     = $wpdb->prefix . 'competition_competition';
    $fed_competition_table = $wpdb->prefix . 'federation_federationcompetition';

    // Core execution query linking custom tables natively via first_place_teamstep_id + federation join
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "
            SELECT
                c.federation_competition_id AS fed_comp_id,
                c.id AS comp_id,
                c.name AS comp_name,
                c.edition AS edition,
                c.season AS season,
                fc.shortname AS fed_shortname
            FROM {$competition_table} c
            INNER JOIN {$teamstep_table} ts
                ON c.first_place_teamstep_id = ts.id
            INNER JOIN {$team_table} t
                ON ts.team_id = t.id
            LEFT JOIN {$fed_competition_table} fc
                ON c.federation_competition_id = fc.id
            WHERE t.id = %d
              AND ts.step = %s
              AND ts.modality = %s
              AND ts.gender = %s
            ORDER BY c.edition DESC
            ",
            $team_custom_id,
            $step,
            $modality,
            $gender
        )
    );

    if (empty($rows)) {
        return [];
    }

    // Process relational display fields in memory loop mappings
    $groups = [];
    foreach ($rows as $r) {

        $fed_id = (int) $r->fed_comp_id;
        if (! $fed_id) {
            continue;
        }

        $edition = (string) $r->edition;
        $season  = (string) ($r->season ?: $edition);
        $name    = (string) $r->comp_name;

        if (! isset($groups[$fed_id])) {
            $groups[$fed_id] = [
                'fed_comp'      => $fed_id,
                'name'          => $name,
                'fed_shortname' => (string) $r->fed_shortname,
                'total'         => 0,
                'seasons'       => []
            ];
        }

        $groups[$fed_id]['total']++;
        $groups[$fed_id]['seasons'][$edition] = [
            'label'   => $season ?: $edition,
            'comp_id' => (int) $r->comp_id,
            'edition' => $edition,
        ];
    }

    $out = [];
    foreach ($groups as $fed_id => $data) {

        $seasons = array_values($data['seasons']);
        usort($seasons, static function ($a, $b) {
            return strcmp((string) $b['edition'], (string) $a['edition']);
        });

        $out[] = [
            'fed_comp'      => $fed_id,
            'name'          => $data['name'] ?: ($data['fed_shortname'] ?: 'Competição ' . $fed_id),
            'fed_shortname' => $data['fed_shortname'],
            'flag'          => $team_flag, // Directly utilizes the verified assigned team flag
            'total'         => $data['total'],
            'seasons'       => $seasons,
        ];
    }

    // SORT BY COMPETITION ID (Ascending order)
    usort($out, static function ($a, $b) {
        return $a['fed_comp'] <=> $b['fed_comp'];
    });

    return $out;
}
endif;

/* ============================================================
 * Resolve team + filters + data
 * ============================================================ */

get_header();

$post_id = $args['post_id'] ?? get_the_ID();
if (function_exists('f365_get_current_team_post')) {
   $t = f365_get_current_team_post();
   if ($t) { $post_id = $t->ID; }
}

// Extract our actual Django Custom Table ID from metadata to filter on t.id directly
$django_team_id = (int) get_post_meta($post_id, 'django_id', true);

// Pre-fetch the explicit country flag assigned to this team post context
$team_assigned_flag = f365_get_team_flag_url((int) $post_id);

$step     = isset($_GET['step'])     ? sanitize_text_field(wp_unslash($_GET['step']))     : 'principal';
$modality = isset($_GET['modality']) ? sanitize_text_field(wp_unslash($_GET['modality'])) : 'football';
$gender   = isset($_GET['gender'])   ? sanitize_text_field(wp_unslash($_GET['gender']))   : 'male';

$current_section = get_query_var('section', 'palmares') ?: 'palmares';
if ($sections && ! isset($sections[$current_section])) {
   $current_section = 'palmares';
}

// Fetch records passing our clean custom table ID parameter and the team flag
$palmares = $django_team_id ? f365_get_team_palmares($django_team_id, $step, $modality, $gender, $team_assigned_flag) : [];
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
               <?php yoast_breadcrumb(); ?>
            </div>
         </div>

         <h4 class="condensed-400 headerTitle blue">Palmarés</h4>

         <?php if (empty($palmares)) : ?>
            <h5 class="no-info">Sem informação disponível</h5>
         <?php else : ?>
            <div class="overflow-auto">
               <table class="ink-table alternating ink-table-f365 all-100">
               <thead>
                  <tr>
                     <th class="align-left all-30">Competição</th>
                     <th class="align-center all-10 small-15">Total</th>
                     <th class="align-left">Edições</th>
                  </tr>
               </thead>
               <tbody>
                  <?php foreach ($palmares as $row) :
                     $fed_url = home_url('/competicao/' . $row['fed_comp']);
                     ?>
                     <tr>
                        <td class="align-left">
                           <?php if (! empty($row['flag'])) : ?>
                              <i title="<?php echo esc_attr($row['name']); ?>" class="flag-bg" style="background-image:url(<?php echo esc_url($row['flag']); ?>);background-size:cover;background-position:center;background-repeat:no-repeat;vertical-align:middle;margin-right:6px;"></i>
                           <?php endif; ?>

                           <a class="fed-name" href="<?php echo esc_url($fed_url); ?>">
                              <?php echo ! empty($row['fed_shortname']) ? esc_html($row['fed_shortname']) : esc_html($row['name']); ?>
                           </a>
                        </td>
                        <td class="align-center"><?php echo (int) $row['total']; ?></td>
                        <td class="align-left">
                           <?php
                           $links = [];
                           foreach ($row['seasons'] as $s) {
                              $url = add_query_arg('edition', $s['edition'], $fed_url);
                              $links[] = '<a href="' . esc_url($url) . '">' . esc_html($s['label']) . '</a>';
                           }
                           echo implode(', ', $links);
                           ?>
                        </td>
                     </tr>
                  <?php endforeach; ?>
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

<?php
/**
 * Player Transfers template
 */


get_header();

$post_id = $args['post_id'] ?? null;

if (!$post_id) {
   $django_player_id = '';

   if (isset($_SERVER['REQUEST_URI']) && preg_match('#/jogador/([0-9]+)#i', $_SERVER['REQUEST_URI'], $matches)) {
      $django_player_id = $matches[1];
   } elseif (get_query_var('f365_player')) {
      $django_player_id = get_query_var('f365_player');
   } else {
      $django_player_id = get_query_var('name');
   }

   if (!empty($django_player_id) && is_numeric($django_player_id)) {
      $cache_key = 'f365_player_post_id_' . $django_player_id;
      $found_id = wp_cache_get($cache_key, 'f365_player');

      if (false === $found_id) {
         $found_id = $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE legacy_id = %d AND post_type = 'f365_player' LIMIT 1",
            $django_player_id
         ));
         wp_cache_set($cache_key, $found_id ?: 0, 'f365_player', HOUR_IN_SECONDS);
      }

      $post_id = $found_id ? (int) $found_id : get_the_ID();
   } else {
      $post_id = get_the_ID();
   }
}
?>

<style>
   .f365-transfer-flag {
      width: 18px;
      height: 14px;
      margin-right: 8px;
      vertical-align: middle;
      display: inline-block;
      background-size: cover;
      background-position: center;
      line-height: 1;
   }
</style>

<main id="primary" class="site-main single-article-page">
   <?php
   get_template_part(
      'template-parts/player/f365-player-header',
      null,
      ['post_id' => $post_id]
   );
   ?>

   <div class="ink-grid futebol365-container">
      <section class="left-column-block">
         <div class="breadcrumbs-f365">
            <div class="ink-navigation breadcrumbs">
               <?php yoast_breadcrumb(); ?>
            </div>
         </div>

         <h4 class="condensed-400 headerTitle top-space">
            <?php esc_html_e('Transferências', 'f365'); ?>
            <span class="headerTitle-border"></span>
         </h4>

         <?php
         global $wpdb;

         $django_player_id = get_post_meta($post_id, 'django_player_id', true);
         $transfers = [];

         if (!empty($django_player_id) && is_numeric($django_player_id)) {
            $transfer_table = $wpdb->prefix . 'transfer_transfer';
            $teamstep_table = $wpdb->prefix . 'team_teamstep';
            $team_table = $wpdb->prefix . 'team_team';

            $transfers = $wpdb->get_results($wpdb->prepare("
               SELECT
                  tr.id AS transfer_id,
                  tr.transfer_date,
                  tr.team_origin_id AS origin_id,
                  tr.team_destination_id AS dest_id,

                  origin_ts.team_id AS origin_real_team_id,
                  dest_ts.team_id AS dest_real_team_id,

                  origin_ts.edition AS origin_edition,
                  origin_ts.modality AS origin_modality,
                  origin_ts.gender AS origin_gender,
                  origin_ts.step AS origin_step,

                  dest_ts.edition AS dest_edition,
                  dest_ts.modality AS dest_modality,
                  dest_ts.gender AS dest_gender,
                  dest_ts.step AS dest_step,

                  COALESCE(origin_team.shortname, tmp_origin_team.shortname, '') AS origin_name,
                  dest_team.shortname AS dest_name,

                  COALESCE(origin_team.country_id, tmp_origin_team.country_id, 0) AS origin_country_id,
                  COALESCE(dest_team.country_id, 0) AS dest_country_id,

                  tr.value,
                  tr.transfer_type
               FROM {$transfer_table} tr
               LEFT JOIN {$teamstep_table} origin_ts
                  ON origin_ts.id = tr.team_origin_id
               LEFT JOIN {$team_table} origin_team
                  ON origin_team.id = origin_ts.team_id
               LEFT JOIN {$team_table} tmp_origin_team
                  ON tmp_origin_team.id = tr.tmp_origin_team_id
               LEFT JOIN {$teamstep_table} dest_ts
                  ON dest_ts.id = tr.team_destination_id
               LEFT JOIN {$team_table} dest_team
                  ON dest_team.id = dest_ts.team_id
               WHERE tr.player_id = %d
               ORDER BY tr.transfer_date DESC, tr.id DESC
               LIMIT 300
            ", (int) $django_player_id));
         }
         ?>

         <?php if (empty($transfers)): ?>
            <h6 class="no-info"><?php esc_html_e('Sem informação disponível', 'f365'); ?></h6>
         <?php else: ?>
            <div class="overflow-auto">
               <table class="ink-table ink-table-f365 alternating all-100">
                  <thead>
                     <tr>
                        <th class="align-left all-15 hide-small hide-tiny"><?php esc_html_e('Data', 'f365'); ?></th>
                        <th class="align-left all-30"><?php esc_html_e('Origem', 'f365'); ?></th>
                        <th class="align-left all-30"><?php esc_html_e('Destino', 'f365'); ?></th>
                        <th class="align-left all-10 hide-small hide-tiny"></th>
                        <th class="align-left all-15 hide-small hide-tiny"><?php esc_html_e('Valor', 'f365'); ?></th>
                     </tr>
                  </thead>

                  <tbody>
                     <?php foreach ($transfers as $r): ?>
                        <?php
                        $date = !empty($r->transfer_date) ? date('d-m-Y', strtotime($r->transfer_date)) : '';

                        $origin_fallback_edition = !empty($r->transfer_date) ? date('Y', strtotime($r->transfer_date)) : '';
                        $dest_fallback_edition = !empty($r->transfer_date) ? date('Y', strtotime($r->transfer_date)) : '';

                        $origin_url = !empty($r->origin_real_team_id)
                           ? add_query_arg([
                              'edition' => !empty($r->origin_edition) ? $r->origin_edition : $origin_fallback_edition,
                              'modality' => !empty($r->origin_modality) ? $r->origin_modality : 'football',
                              'gender' => !empty($r->origin_gender) ? $r->origin_gender : 'male',
                              'step' => !empty($r->origin_step) ? $r->origin_step : 'principal',
                           ], home_url('/equipa/' . (int) $r->origin_real_team_id . '/jogos/'))
                           : '';

                        $dest_url = !empty($r->dest_real_team_id)
                           ? add_query_arg([
                              'edition' => !empty($r->dest_edition) ? $r->dest_edition : $dest_fallback_edition,
                              'modality' => !empty($r->dest_modality) ? $r->dest_modality : 'football',
                              'gender' => !empty($r->dest_gender) ? $r->dest_gender : 'male',
                              'step' => !empty($r->dest_step) ? $r->dest_step : 'principal',
                           ], home_url('/equipa/' . (int) $r->dest_real_team_id . '/jogos/'))
                           : '';

                        ob_start();

                        if (!empty($r->value)) {
                           if (is_numeric($r->value)) {
                              echo esc_html(number_format((float) $r->value, 0, ',', '.')) . '&nbsp;&euro;';
                           } else {
                              echo esc_html($r->value);
                           }
                        }

                        $value_html = ob_get_clean();
                        ?>

                        <tr>
                           <td class="align-left hide-small hide-tiny">
                              <?php echo esc_html($date); ?>
                           </td>

                           <td class="align-left">
                              <?php if (!empty($r->origin_country_id)): ?>
                                 <i
                                    class="f365-transfer-flag flag-icon flag-icon-<?php echo esc_attr((int) $r->origin_country_id); ?>"></i>
                              <?php endif; ?>

                              <?php if (!empty($origin_url)): ?>
                                 <a href="<?php echo esc_url($origin_url); ?>"><?php echo esc_html($r->origin_name); ?></a>
                              <?php else: ?>
                                 <?php echo esc_html($r->origin_name); ?>
                              <?php endif; ?>

                              <div class="medium hide-xlarge hide-large hide-medium">
                                 <?php echo esc_html($date); ?>
                              </div>
                           </td>

                           <td class="align-left">
                              <?php if (!empty($r->dest_country_id)): ?>
                                 <i
                                    class="f365-transfer-flag flag-icon flag-icon-<?php echo esc_attr((int) $r->dest_country_id); ?>"></i>
                              <?php endif; ?>

                              <?php if (!empty($dest_url)): ?>
                                 <a href="<?php echo esc_url($dest_url); ?>"><?php echo esc_html($r->dest_name); ?></a>
                              <?php else: ?>
                                 <?php echo esc_html($r->dest_name); ?>
                              <?php endif; ?>

                              <div class="medium hide-xlarge hide-large hide-medium">
                                 <?php echo esc_html($r->transfer_type); ?>
                                 <?php if (!empty($value_html)): ?>
                                    - <?php echo $value_html; ?>
                                 <?php endif; ?>
                              </div>
                           </td>

                           <td class="align-left hide-small hide-tiny">
                              <?php echo esc_html($r->transfer_type); ?>
                           </td>

                           <td class="align-left hide-small hide-tiny">
                              <?php echo $value_html; ?>
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

<?php get_footer(); ?>
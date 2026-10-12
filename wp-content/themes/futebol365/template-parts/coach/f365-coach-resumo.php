<?php
/**
 * Coach Resumo Template Part
 *
 * File: template-parts/coach/f365-coach-resumo.php
 *
 * Displays career history: club teamsteps and national teamsteps.
 * Mirrors Django: coach-resume.html
 *
 * Data loaded via f365_get_coach_resume() — no queries in this file.
 *
 * Receives via get_template_part() $args:
 *   $args['coach'] array — from f365_get_coach_data()
 *
 * @package Futebol365
 */

defined( 'ABSPATH' ) || exit;

$coach   = isset( $args['coach'] ) && is_array( $args['coach'] ) ? $args['coach'] : [];
$post_id = (int) ( $coach['post_id'] ?? 0 );

$resume        = $post_id > 0 ? f365_get_coach_resume( $post_id ) : [ 'club' => [], 'national' => [] ];
$club_rows     = (array) ( $resume['club']     ?? [] );
$national_rows = (array) ( $resume['national'] ?? [] );
?>

<?php // ── Club teamsteps ─────────────────────────────────────────────────── ?>
<?php if ( ! empty( $club_rows ) ) : ?>

    <h5 class="condensed-400 headerTitle yellow">
        <?php esc_html_e( 'Clubes', 'futebol365' ); ?>
    </h5>

    <table class="ink-table ink-table-f365 alternating all-100">
        <thead>
            <tr>
                <th class="align-left all-20 small-25 tiny-25"><?php esc_html_e( 'Época',  'futebol365' ); ?></th>
                <th class="align-left"><?php esc_html_e( 'Clube', 'futebol365' ); ?></th>
                <th class="align-left all-30 hide-tiny hide-small"><?php esc_html_e( 'País',   'futebol365' ); ?></th>
                <th class="align-left all-25 small-30 tiny-30"><?php esc_html_e( 'Função', 'futebol365' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ( $club_rows as $row ) :
               $season_label = str_replace('/', '-', $row['season_label']);
                ?>
                <tr>
                    <td class="align-left"><?php echo esc_html( $season_label ?: $row['edition'] ?: '—' ); ?></td>
                    <td class="align-left">
                        <?php if ( $row['team_url'] ) : ?>
                            <a href="<?php echo esc_url( $row['team_url'] ); ?>">
                                <?php echo esc_html( $row['team_name'] ); ?>
                            </a>
                        <?php else : ?>
                            <?php echo esc_html( $row['team_name'] ); ?>
                        <?php endif; ?>
                    </td>
                    <td class="align-left hide-tiny hide-small">
                        <?php if ( $row['country_flag'] ) : ?>
                            <i class="flag-bg quarter-right-space"
                               style="background-image: url(<?php echo esc_url( $row['country_flag'] ); ?>);"
                               title="<?php echo esc_attr( $row['country_name'] ); ?>"></i>
                        <?php endif; ?>
                        <?php if ( ! empty( $row['federation_url'] ) ) : ?>
                            <a href="<?php echo esc_url( $row['federation_url'] ); ?>">
                                <?php echo esc_html( $row['country_name'] ); ?>
                            </a>
                        <?php else : ?>
                            <?php echo esc_html( $row['country_name'] ); ?>
                        <?php endif; ?>
                    </td>
                    <td class="align-left"><?php echo esc_html( $row['coach_type_label'] ); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

<?php endif; ?>

<?php // ── National teamsteps ─────────────────────────────────────────────── ?>
<?php if ( ! empty( $national_rows ) ) : ?>

    <h5 class="condensed-400 headerTitle yellow">
        <?php esc_html_e( 'Seleções', 'futebol365' ); ?>
    </h5>

    <table class="ink-table ink-table-f365 alternating all-100">
        <thead>
            <tr>
                <th class="align-left all-20 small-25 tiny-25"><?php esc_html_e( 'Edição',     'futebol365' ); ?></th>
                <th class="align-left"><?php esc_html_e( 'Seleção',    'futebol365' ); ?></th>
                <th class="align-left all-30 hide-tiny hide-small"><?php esc_html_e( 'Competição', 'futebol365' ); ?></th>
                <th class="align-left all-25 small-30 tiny-30"><?php esc_html_e( 'Função',     'futebol365' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ( $national_rows as $row ) :
                // If competitions exist: one row per competition (mirrors Django nested loop)
                // If no competitions: one row without competition
                $competitions = (array) ( $row['competitions'] ?? [] );
                if ( ! empty( $competitions ) ) :
                    foreach ( $competitions as $comp ) :
                ?>
                    <tr>
                        <td class="align-left"><?php echo esc_html( $row['edition'] ?: '—' ); ?></td>
                        <td class="align-left">
                            <?php
                            $team_squad_url = '';
                            if ( ! empty( $row['team_wp_id'] ) ) {
                                // team_wp_id carries the legacy team id; build the source URL directly: /equipa/{id}/plantel/
                                $team_squad_url = user_trailingslashit( home_url( 'equipa/' . (int) $row['team_wp_id'] . '/plantel' ) );

                                $args_to_add = [
                                    'edition'  => $row['edition'] ?: '',
                                    'modality' => $row['modality'] ?: 'football',
                                    'gender'   => $row['gender'] ?: 'male',
                                    'step'     => $row['step'] ?: 'principal',
                                ];
                                if ( ! empty( $comp['wp_id'] ) ) {
                                    $args_to_add['competition'] = (int) $comp['wp_id'];
                                }
                                $team_squad_url = add_query_arg( $args_to_add, $team_squad_url );
                            }
                            ?>
                            <?php if ( $team_squad_url ) : ?>
                                <a href="<?php echo esc_url( $team_squad_url ); ?>">
                                    <?php echo esc_html( $row['team_name'] ); ?>
                                </a>
                            <?php else : ?>
                                <?php echo esc_html( $row['team_name'] ); ?>
                            <?php endif; ?>
                        </td>
                        <td class="align-left hide-tiny hide-small">
                            <?php if ( ! empty( $comp['flag'] ) ) : ?>
                                <i class="flag-bg quarter-right-space"
                                   style="background-image: url(<?php echo esc_url( $comp['flag'] ); ?>);"
                                   title="<?php echo esc_attr( $comp['name'] ); ?>"></i>
                            <?php endif; ?>
                            <?php if ( ! empty( $comp['url'] ) ) : ?>
                                <a href="<?php echo esc_url( $comp['url'] ); ?>">
                                    <?php echo esc_html( $comp['name'] ); ?>
                                </a>
                            <?php else : ?>
                                <?php echo esc_html( $comp['name'] ); ?>
                            <?php endif; ?>
                        </td>
                        <td class="align-left"><?php echo esc_html( $row['coach_type_label'] ); ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php else : ?>
                    <tr>
                        <td class="align-left"><?php echo esc_html( $row['edition'] ?: '—' ); ?></td>
                        <td class="align-left">
                            <?php
                            $team_squad_url = '';
                            if ( ! empty( $row['team_wp_id'] ) ) {
                                // team_wp_id carries the legacy team id; build the source URL directly: /equipa/{id}/plantel/
                                $team_squad_url = user_trailingslashit( home_url( 'equipa/' . (int) $row['team_wp_id'] . '/plantel' ) );

                                $args_to_add = [
                                    'edition'  => $row['edition'] ?: '',
                                    'modality' => $row['modality'] ?: 'football',
                                    'gender'   => $row['gender'] ?: 'male',
                                    'step'     => $row['step'] ?: 'principal',
                                ];
                                $team_squad_url = add_query_arg( $args_to_add, $team_squad_url );
                            }
                            ?>
                            <?php if ( $team_squad_url ) : ?>
                                <a href="<?php echo esc_url( $team_squad_url ); ?>">
                                    <?php echo esc_html( $row['team_name'] ); ?>
                                </a>
                            <?php else : ?>
                                <?php echo esc_html( $row['team_name'] ); ?>
                            <?php endif; ?>
                        </td>
                        <td class="align-left hide-tiny hide-small">—</td>
                        <td class="align-left"><?php echo esc_html( $row['coach_type_label'] ); ?></td>
                    </tr>
                <?php endif; ?>
            <?php endforeach; ?>
        </tbody>
    </table>

<?php endif; ?>

<?php if ( empty( $club_rows ) && empty( $national_rows ) ) : ?>
    <p><?php esc_html_e( 'Sem informação disponível.', 'futebol365' ); ?></p>
<?php endif; ?>
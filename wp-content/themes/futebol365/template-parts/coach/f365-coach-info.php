<?php
/**
 * Coach Info Template Part — Dados Pessoais
 *
 * File: template-parts/coach/f365-coach-info.php
 *
 * Mirrors Django: coach-info.html
 * All data from $args['coach'] — no queries here.
 *
 * Receives via get_template_part() $args:
 *   $args['coach'] array — from f365_get_coach_data()
 *
 * @package Futebol365
 */

defined( 'ABSPATH' ) || exit;

$coach = isset( $args['coach'] ) && is_array( $args['coach'] ) ? $args['coach'] : [];

if ( empty( $coach ) ) {
    echo '<p>' . esc_html__( 'Sem informação disponível.', 'futebol365' ) . '</p>';
    return; // returns from get_template_part() only — header/sidebar already rendered
}
?>

<h5 class="condensed-400 headerTitle yellow">
    <?php esc_html_e( 'Dados Pessoais', 'futebol365' ); ?>
</h5>

<table class="ink-table ink-table-f365 alternating all-100">
    <tbody>

        <?php // Full name ──────────────────────────────────────────────── ?>
        <tr>
            <td class="align-right all-35 font-blue"><?php esc_html_e( 'Nome:', 'futebol365' ); ?></td>
            <td class="all-65">
                <?php
                $full_name = $coach['longname'] !== '' ? $coach['longname'] : $coach['shortname'];
                echo esc_html( $full_name ?: '—' );
                ?>
            </td>
        </tr>

        <?php // Birth date ─────────────────────────────────────────────── ?>
        <?php if ( $coach['birth_date'] !== '' ) : ?>
            <tr>
                <td class="align-right font-blue"><?php esc_html_e( 'Data Nascimento:', 'futebol365' ); ?></td>
                <td>
                    <?php
                    $ts = strtotime( $coach['birth_date'] );
                    echo $ts ? esc_html( date_i18n( 'd-m-Y', $ts ) ) : esc_html( $coach['birth_date'] );
                    ?>
                </td>
            </tr>
        <?php endif; ?>

        <?php // Age or date of death ─────────────────────────────────── ?>
        <tr>
            <?php if ( $coach['is_deceased'] && $coach['death_date'] !== '' ) : ?>
                <td class="align-right font-blue"><?php esc_html_e( 'Falecido a:', 'futebol365' ); ?></td>
                <td>
                    <?php
                    $ts = strtotime( $coach['death_date'] );
                    echo $ts ? esc_html( date_i18n( 'd-m-Y', $ts ) ) : esc_html( $coach['death_date'] );
                    ?>
                </td>
            <?php elseif ( $coach['age'] !== null ) : ?>
                <td class="align-right font-blue"><?php esc_html_e( 'Idade:', 'futebol365' ); ?></td>
                <td><?php printf( esc_html__( '%d anos', 'futebol365' ), (int) $coach['age'] ); ?></td>
            <?php else : ?>
                <td class="align-right font-blue"><?php esc_html_e( 'Idade:', 'futebol365' ); ?></td>
                <td>—</td>
            <?php endif; ?>
        </tr>

        <?php // Naturality (country of origin) ─────────────────────── ?>
        <?php if ( ! empty( $coach['naturality']['name'] ) ) : ?>
            <tr>
                <td class="align-right font-blue"><?php esc_html_e( 'Nascido em:', 'futebol365' ); ?></td>
                <td><?php echo esc_html( $coach['naturality']['name'] ); ?></td>
            </tr>
        <?php endif; ?>

        <?php // Birth place ──────────────────────────────────────────── ?>
        <?php if ( $coach['birth_place'] !== '' ) : ?>
            <tr>
                <td class="align-right font-blue"><?php esc_html_e( 'Local Nascimento:', 'futebol365' ); ?></td>
                <td><?php echo esc_html( $coach['birth_place'] ); ?></td>
            </tr>
        <?php endif; ?>

        <?php // Nationality + naturality ────────────────────────────── ?>
        <?php if ( ! empty( $coach['nationality']['name'] ) ) : ?>
            <tr>
                <td class="align-right font-blue"><?php esc_html_e( 'Nacionalidade:', 'futebol365' ); ?></td>
                <td>
                    <?php $nat = $coach['nationality']; ?>
                    <?php if ( ! empty( $nat['flag_url'] ) ) : ?>
                        <i class="flag-bg quarter-right-space"
                           style="background-image: url(<?php echo esc_url( $nat['flag_url'] ); ?>);"
                           title="<?php echo esc_attr( $nat['name'] ); ?>"></i>
                    <?php endif; ?>
                    <?php echo esc_html( $nat['name'] ); ?>

                    <?php if ( $coach['show_nationality_row'] && ! empty( $coach['naturality']['name'] ) ) : ?>
                        <?php $natu = $coach['naturality']; ?>
                        <br>
                        <?php if ( ! empty( $natu['flag_url'] ) ) : ?>
                            <i class="flag-bg quarter-right-space quarter-top-space"
                               style="background-image: url(<?php echo esc_url( $natu['flag_url'] ); ?>);"
                               title="<?php echo esc_attr( $natu['name'] ); ?>"></i>
                        <?php endif; ?>
                        <?php echo esc_html( $natu['name'] ); ?>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endif; ?>



        <?php // Social links ─────────────────────────────────────────── ?>
        <?php if ( ! empty( $coach['social_links'] ) || $coach['website_url'] !== '' ) : ?>
            <tr>
                <td class="align-right font-blue"><?php esc_html_e( 'Redes Sociais:', 'futebol365' ); ?></td>
                <td>
                    <?php foreach ( $coach['social_links'] as $link ) :
                        $l_url   = (string) ( $link['url']   ?? '' );
                        $l_label = (string) ( $link['label'] ?? '' );
                        $l_icon  = (string) ( $link['icon']  ?? '' );
                        if ( ! $l_url || ! $l_icon ) continue;
                    ?>
                        <a href="<?php echo esc_url( $l_url ); ?>"
                           target="_blank" rel="noopener noreferrer"
                           aria-label="<?php echo esc_attr( $l_label ); ?>">
                            <svg class="quarter-right-space" viewBox="0 0 16 16"
                                 width="1em" height="1em" aria-hidden="true" focusable="false">
                                <use href="#<?php echo esc_attr( $l_icon ); ?>"></use>
                            </svg>
                        </a>
                    <?php endforeach; ?>

                    <?php if ( $coach['website_url'] !== '' ) : ?>
                        <a href="<?php echo esc_url( $coach['website_url'] ); ?>"
                           target="_blank" rel="noopener noreferrer"
                           aria-label="<?php esc_attr_e( 'Website', 'futebol365' ); ?>">
                            <svg class="quarter-right-space" viewBox="0 0 16 16"
                                 width="1em" height="1em" aria-hidden="true" focusable="false">
                                <use href="#icon-link"></use>
                            </svg>
                        </a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endif; ?>

        <?php // Cross-role: same person was also a player ───────────── ?>
        <?php if ( $coach['linked_player_id'] > 0 && $coach['linked_player_url'] !== '' ) : ?>
            <tr>
                <td class="align-right font-blue"><?php esc_html_e( 'Outra Função:', 'futebol365' ); ?></td>
                <td>
                    <a href="<?php echo esc_url( $coach['linked_player_url'] ); ?>"
                       title="<?php esc_attr_e( 'Jogador', 'futebol365' ); ?>">
                        <?php esc_html_e( 'Jogador', 'futebol365' ); ?>
                    </a>
                </td>
            </tr>
        <?php endif; ?>

    </tbody>
</table>
<?php
/**
 * Federation — Dados Gerais (general data)
 * @package Futebol365
 */

defined( 'ABSPATH' ) || exit;

$current_post_id = get_the_ID();

//$django_federation_id = get_post_meta( $current_post_id, 'django_federation_id', true );

$django_federation_id = get_legacy_competition_id( $current_post_id );
$federation_id        = intval( $django_federation_id );

$fed_table   = $wpdb->prefix . 'federation_federation';
$country_tbl = $wpdb->prefix . 'country_country';
$pres_tbl    = $wpdb->prefix . 'person_president';
$person_tbl  = $wpdb->prefix . 'person_person';

$fed = $wpdb->get_row(
	$wpdb->prepare(
		"SELECT f.id, f.shortname, f.longname, f.url, f.facebook, f.twitter, f.youtube,
		        f.instagram, f.googleplus, f.country_id, f.city, f.foundation,
		        f.president_id, f.federation_id, f.federation_type,
		        c.longname   AS country_longname,
		        c.capital    AS country_capital,
		        c.city       AS country_city,
		        c.language   AS country_language,
		        c.area       AS country_area,
		        c.population AS country_population,
		        c.coin       AS country_coin,
		        pf.country_id AS parent_country_id,
		        pp.shortname AS president_name
		   FROM {$fed_table} f
		   LEFT JOIN {$country_tbl} c  ON f.country_id    = c.id
		   LEFT JOIN {$fed_table}   pf ON f.federation_id = pf.id
		   LEFT JOIN {$pres_tbl}    pr ON f.president_id  = pr.id
		   LEFT JOIN {$person_tbl}  pp ON pr.person_id    = pp.id
		  WHERE f.id = %d",
		$federation_id
	),
	OBJECT
);

if ( ! function_exists( 'f365_federation_flag_id' ) ) {
	/** Federation.get_flag_id(). */
	function f365_federation_flag_id( $fed ) {
		if ( 'association' === $fed->federation_type ) {
			return ( null !== $fed->parent_country_id && '' !== $fed->parent_country_id ) ? intval( $fed->parent_country_id ) : '';
		}
		if ( 'federation' === $fed->federation_type ) {
			return ( null !== $fed->country_id && '' !== $fed->country_id ) ? intval( $fed->country_id ) : '';
		}
		return 'conf_' . intval( $fed->id );
	}
}

if ( $fed ) {
	$flag_id     = f365_federation_flag_id( $fed );
	$found_year  = ( ! empty( $fed->foundation ) ) ? mysql2date( 'Y', $fed->foundation ) : '';
	$has_social  = ( $fed->facebook || $fed->twitter || $fed->youtube || $fed->instagram || $fed->googleplus );
	$is_assoc    = ( 'association' === $fed->federation_type );
	$is_confed   = ( 'confederation' === $fed->federation_type );
}
?>


			<h4 class="condensed-400 headerTitle blue"><?php esc_html_e( 'Dados Gerais', 'futebol365' ); ?></h4>
			<table class="ink-table ink-table-f365 alternating">
				<tbody>
					<tr>
						<td class="align-right all-35 font-blue"><?php esc_html_e( 'Nome:', 'futebol365' ); ?></td>
						<td class="align-left"><?php echo esc_html( $fed->longname ); ?></td>
					</tr>
					<?php if ( ! empty( $fed->president_name ) ) : ?>
						<tr>
							<td class="align-right font-blue"><?php esc_html_e( 'Presidente:', 'futebol365' ); ?></td>
							<td class="align-left"><?php echo esc_html( $fed->president_name ); ?></td>
						</tr>
					<?php endif; ?>
					<tr>
						<td class="align-right font-blue"><?php esc_html_e( 'Ano de Fundação:', 'futebol365' ); ?></td>
						<td class="align-left"><?php echo esc_html( $found_year ); ?></td>
					</tr>
					<tr>
						<td class="align-right font-blue"><?php esc_html_e( 'Sede:', 'futebol365' ); ?></td>
						<td class="align-left"><?php echo esc_html( $fed->city ); ?></td>
					</tr>
					<?php if ( $fed->url ) : ?>
						<tr>
							<td class="align-right font-blue"><?php esc_html_e( 'Internet:', 'futebol365' ); ?></td>
							<td class="align-left"><a target="_blank" href="<?php echo esc_url( $fed->url ); ?>"><?php echo esc_html( $fed->url ); ?></a></td>
						</tr>
					<?php endif; ?>
					<?php if ( $has_social ) : ?>
						<tr>
							<td class="align-right font-blue"><?php esc_html_e( 'Redes Sociais:', 'futebol365' ); ?></td>
							<td>
								<?php if ( $fed->facebook ) : ?>
									<a href="https://www.facebook.com/<?php echo esc_attr( $fed->facebook ); ?>" target="_blank" aria-label="Facebook"><svg class="quarter-right-space" viewBox="0 0 16 16" width="1em" height="1em" aria-hidden="true" focusable="false"><use href="#social-facebook"></use></svg></a>
								<?php endif; ?>
								<?php if ( $fed->twitter ) : ?>
									<a href="https://x.com/<?php echo esc_attr( $fed->twitter ); ?>" target="_blank" aria-label="X (Twitter)"><svg class="quarter-right-space" viewBox="0 0 16 16" width="1em" height="1em" aria-hidden="true" focusable="false"><use href="#social-x"></use></svg></a>
								<?php endif; ?>
								<?php if ( $fed->youtube ) : ?>
									<a href="https://www.youtube.com/<?php echo esc_attr( $fed->youtube ); ?>" target="_blank" aria-label="YouTube"><svg class="quarter-right-space" viewBox="0 0 16 16" width="1em" height="1em" aria-hidden="true" focusable="false"><use href="#social-youtube"></use></svg></a>
								<?php endif; ?>
								<?php if ( $fed->instagram ) : ?>
									<a href="https://www.instagram.com/<?php echo esc_attr( $fed->instagram ); ?>" target="_blank" aria-label="Instagram"><svg class="quarter-right-space" viewBox="0 0 16 16" width="1em" height="1em" aria-hidden="true" focusable="false"><use href="#social-instagram"></use></svg></a>
								<?php endif; ?>
							</td>
						</tr>
					<?php endif; ?>
				</tbody>
			</table>

			<?php if ( ! $is_confed ) : ?>
				<h4 class="condensed-400 headerTitle blue"><?php esc_html_e( 'Dados do Pais', 'futebol365' ); ?></h4>
				<table class="ink-table ink-table-f365 alternating">
					<tbody>
						<tr>
							<td class="align-right all-35 font-blue"><?php esc_html_e( 'Nome:', 'futebol365' ); ?></td>
							<td class="align-left"><?php echo esc_html( $fed->country_longname ); ?></td>
						</tr>
						<tr>
							<td class="align-right font-blue"><?php esc_html_e( 'Capital:', 'futebol365' ); ?></td>
							<td class="align-left"><?php echo esc_html( $fed->country_capital ); ?></td>
						</tr>
						<?php if ( ! empty( $fed->country_city ) ) : ?>
							<tr>
								<td class="align-right font-blue"><?php esc_html_e( 'Cidade mais populosa:', 'futebol365' ); ?></td>
								<td class="align-left"><?php echo esc_html( $fed->country_city ); ?></td>
							</tr>
						<?php endif; ?>
						<tr>
							<td class="align-right font-blue"><?php esc_html_e( 'Língua oficial:', 'futebol365' ); ?></td>
							<td class="align-left"><?php echo esc_html( $fed->country_language ); ?></td>
						</tr>
						<tr>
							<td class="align-right font-blue"><?php esc_html_e( 'Área:', 'futebol365' ); ?></td>
							<td class="align-left"><?php echo esc_html( $fed->country_area ); ?> <?php esc_html_e( 'km²', 'futebol365' ); ?></td>
						</tr>
						<tr>
							<td class="align-right font-blue"><?php esc_html_e( 'População:', 'futebol365' ); ?></td>
							<td class="align-left"><?php echo esc_html( $fed->country_population ); ?> <?php esc_html_e( 'hab.', 'futebol365' ); ?></td>
						</tr>
						<tr>
							<td class="align-right font-blue"><?php esc_html_e( 'Moeda:', 'futebol365' ); ?></td>
							<td class="align-left"><?php echo esc_html( $fed->country_coin ); ?></td>
						</tr>
					</tbody>
				</table>
			<?php endif; ?>

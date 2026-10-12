<?php
/**
 * Federation — Competições Regionais
 *
 * Port of the 'regional-competitions' menu of federation/federation-competitions.html.
 * @package Futebol365
 */

defined( 'ABSPATH' ) || exit;

get_header();
global $wpdb;

$current_post_id = get_the_ID();

//$django_federation_id = get_post_meta( $current_post_id, 'django_federation_id', true );
$django_federation_id = get_legacy_competition_id( $current_post_id );
$federation_id        = intval( $django_federation_id );
$sections             = futebol365_get_federation_sections();

$fed_table   = $wpdb->prefix . 'federation_federation';
$country_tbl = $wpdb->prefix . 'country_country';
$fc_table    = $wpdb->prefix . 'federation_federationcompetition';
$comp_table  = $wpdb->prefix . 'competition_competition';

$fed = $wpdb->get_row(
	$wpdb->prepare(
		"SELECT f.id, f.shortname, f.longname, f.country_id, f.federation_id, f.federation_type,
		        pf.federation_type AS parent_type, pf.country_id AS parent_country_id
		   FROM {$fed_table} f
		   LEFT JOIN {$fed_table} pf ON f.federation_id = pf.id
		  WHERE f.id = %d",
		$federation_id
	),
	OBJECT
);

if ( ! function_exists( 'f365_federation_member_flag_id' ) ) {
	function f365_federation_member_flag_id( $type, $id, $country_id, $parent_country_id ) {
		if ( 'association' === $type ) {
			return ( null !== $parent_country_id && '' !== $parent_country_id ) ? intval( $parent_country_id ) : '';
		}
		if ( 'federation' === $type ) {
			return ( null !== $country_id && '' !== $country_id ) ? intval( $country_id ) : '';
		}
		return 'conf_' . intval( $id );
	}
}

if ( ! function_exists( 'f365_count_fed_comp_with_edition' ) ) {

	function f365_count_fed_comp_with_edition( $fed_id, $fed_type, $parent_type ) {
		global $wpdb;
		$fc_table   = $wpdb->prefix . 'federation_federationcompetition';
		$comp_table = $wpdb->prefix . 'competition_competition';

		$result = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(1) FROM {$fc_table} fc
				  WHERE fc.federation_id = %d
				    AND EXISTS ( SELECT 1 FROM {$comp_table} c WHERE c.federation_competition_id = fc.id )",
				intval( $fed_id )
			)
		);

		if ( 'confederation' === $fed_type && 'confederation' === $parent_type ) {
			$fed_table   = $wpdb->prefix . 'federation_federation';
			$country_tbl = $wpdb->prefix . 'country_country';
			$subs        = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT f.id, f.federation_type, pf.federation_type AS parent_type
					   FROM {$fed_table} f
					   LEFT JOIN {$fed_table} pf ON f.federation_id = pf.id
					   LEFT JOIN {$country_tbl} c ON f.country_id = c.id
					  WHERE f.federation_id = %d
					    AND ( c.id IS NULL OR CAST( c.is_extinct AS CHAR ) NOT IN ( '1', 't', 'true' ) )",
					intval( $fed_id )
				),
				OBJECT
			);
			foreach ( (array) $subs as $s ) {
				$result += f365_count_fed_comp_with_edition( $s->id, $s->federation_type, $s->parent_type );
			}
		}

		return $result;
	}
}

/* -------------------------------------------------------------------------
 * Data — descendents (get_descendents).
 * ---------------------------------------------------------------------- */
$descendents = array();

if ( $fed ) {
	$descendents = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT f.id, f.shortname, f.country_id, f.federation_type
			   FROM {$fed_table} f
			   LEFT JOIN {$country_tbl} c ON f.country_id = c.id
			  WHERE f.federation_id = %d
			    AND ( c.id IS NULL OR CAST( c.is_extinct AS CHAR ) NOT IN ( '1', 't', 'true' ) )
			  ORDER BY f.shortname ASC",
			$federation_id
		),
		OBJECT
	);
}
?>

<main id="primary" class="site-main single-article-page">
	<?php
	get_template_part(
		'template-parts/federation/f365-federation-header',
		null,
		array(
			'post_id'  => $current_post_id,
			'sections' => $sections,
		)
	);
	?>

	<div class="ink-grid futebol365-container">

		<section class="left-column-block">
			<div class="breadcrumbs-f365">
				<div class="ink-navigation breadcrumbs">
					<?php yoast_breadcrumb(); ?>
				</div>
			</div>

			<?php if ( ! empty( $descendents ) ) : ?>
				<?php if ( $fed && 'confederation' === $fed->federation_type ) : ?>
					<h3 class="condensed-400 headerTitle"><?php esc_html_e( 'Competições Nacionais de Clubes', 'futebol365' ); ?></h3>
				<?php elseif ( $fed && 'federation' === $fed->federation_type ) : ?>
					<h3 class="condensed-400 headerTitle"><?php esc_html_e( 'Associações Distritais', 'futebol365' ); ?></h3>
				<?php endif; ?>

				<div class="column-group half-gutters">
					<?php foreach ( $descendents as $federation_member ) :
						$m_flag  = f365_federation_member_flag_id( $federation_member->federation_type, $federation_member->id, $federation_member->country_id, $fed->country_id );
						$m_url   = esc_url( home_url( '/federacao/' . intval( $federation_member->id ) . '/competicoes' ) );
						$m_label = $federation_member->shortname;
						$m_count = f365_count_fed_comp_with_edition( $federation_member->id, $federation_member->federation_type, $fed->federation_type );
						// pluralize:"ão,ões" — singular for exactly 1, plural otherwise.
						$suffix  = ( 1 === $m_count ) ? 'ão' : 'ões';
						?>
						<div class="all-50 xlarge-33 small-100 tiny-100">
							<div class="obj-right-div">
								<div class="obj-flag">
									<a href="<?php echo $m_url; ?>"><i class="flag-icon flag-icon-<?php echo esc_attr( $m_flag ); ?>" title="<?php echo esc_attr( $m_label ); ?>"></i></a>
								</div>
								<div class="obj-detail">
									<div class="obj-name"><a href="<?php echo $m_url; ?>"><?php echo esc_html( $m_label ); ?></a></div>
									<div class="obj-link"><?php echo esc_html( $m_count ); ?> competiç<?php echo esc_html( $suffix ); ?></div>
								</div>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<?php get_template_part( 'template-parts/template-front-page-posts' ); ?>
		</section>

		<section class="right-column-block">
			<?php
			if ( is_active_sidebar( 'post-sidebar' ) ) {
				dynamic_sidebar( 'post-sidebar' );
			} else {
				get_sidebar();
			}
			?>
		</section>

	</div>
</main>

<?php
get_footer();

<?php
/**
 * Competition — Transferências   /competicao/{fed_comp_id}/transferencias/
 *
 * Port of competition/templates/competition/competition_transfers.html.
 * Reads from the Django mirror tables via F365_Transfer_Repository (player name
 * from person_person.shortname, flags from Country ids rendered as
 * `flag-icon flag-icon-{id}`, links from Django ids) — matching the Django
 * template markup exactly and the f365-match-preview data convention.
 *
 * @package Futebol365
 */

defined( 'ABSPATH' ) || exit;

get_header();

$current_post_id = get_the_ID();
$current_section = 'transferencias';
$sections        = futebol365_get_competition_sections();

//$final_competition_id = get_post_meta( $current_post_id, 'django_competition_id', true );
$final_competition_id = get_legacy_competition_id( $current_post_id );
$competition_id       = (int) $final_competition_id;
$level_1_title        = 'Transferências';

/* Transfer repository — self-contained (also exposes get_competition()). */
$repo = class_exists( 'F365_Transfer_Repository' ) ? new F365_Transfer_Repository() : null;

/* Authoritative edition: competition mirror row, falling back to ACF. */
$comp_edition = '';
if ( $repo && $competition_id ) {
	$comp_row = $repo->get_competition( $competition_id );
	if ( $comp_row && ! empty( $comp_row->edition ) ) {
		$comp_edition = (string) $comp_row->edition;
	}
}
if ( '' === $comp_edition ) {
	$comp_edition = (string) ( get_field( 'edition', $current_post_id ) ?: get_post_meta( $current_post_id, 'edition', true ) );
}

/* -------------------------------------------------------------------------
 * Render helpers
 * ---------------------------------------------------------------------- */

if ( ! function_exists( 'f365_flag_icon_html' ) ) {
	/** Country-id flag sprite, matching the Django `flag-icon flag-icon-{id}` markup. */
	function f365_flag_icon_html( $country_id, $title = '' ): string {
		$country_id = (int) $country_id;
		if ( ! $country_id ) {
			return '';
		}
		return sprintf(
			'<i class="hide-tiny flag-icon quarter-right-space flag-icon-%d" title="%s"></i>',
			$country_id,
			esc_attr( (string) $title )
		);
	}
}

if ( ! function_exists( 'f365_transfer_value_html' ) ) {
	function f365_transfer_value_html( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		if ( is_numeric( $value ) ) {
			return esc_html( number_format( (float) $value, 0, ',', '.' ) ) . '&nbsp;&euro;';
		}
		return esc_html( $value );
	}
}

if ( ! function_exists( 'f365_transfer_render_table' ) ) {
	/**
	 * Render one transfers table.
	 *
	 * @param object[] $transfers   Decorated transfer rows.
	 * @param string   $other_label 'Origem' or 'Destino'.
	 * @param string   $side        'origin' or 'dest'.
	 */
	function f365_transfer_render_table( array $transfers, string $other_label, string $side ): void {
		?>
		<table class="ink-table ink-table-f365 alternating all-100">
			<thead>
				<tr>
					<th class="align-left all-15 hide-tiny hide-small">Data</th>
					<th class="align-left all-30">Jogador</th>
					<th class="align-left all-30"><?php echo esc_html( $other_label ); ?></th>
					<th class="align-left all-10 hide-tiny hide-small">Tipo</th>
					<th class="align-left all-15 hide-tiny hide-small">Valor</th>
				</tr>
			</thead>
			<tbody>
				<?php
				foreach ( $transfers as $tr ) :
					$is_origin   = ( 'origin' === $side );
					$show_team   = $is_origin ? ! empty( $tr->origin_present ) : true;
					$team_label  = $is_origin ? (string) $tr->origin_label : (string) $tr->dest_label;
					$team_url    = $is_origin ? (string) $tr->origin_url : (string) $tr->dest_url;
					$team_cid    = $is_origin ? $tr->origin_country_id : $tr->dest_country_id;
					$team_cname  = $is_origin ? (string) $tr->origin_country_name : (string) $tr->dest_country_name;
					?>
					<tr>
						<td class="align-left hide-tiny hide-small"><?php echo esc_html( (string) $tr->date_fmt ); ?></td>
						<td class="align-left">
							<?php echo f365_flag_icon_html( $tr->player_country_id, $tr->player_country_name ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><a href="<?php echo esc_url( (string) $tr->player_url ); ?>"><?php echo esc_html( (string) $tr->player_name ); ?></a>
						</td>
						<td class="align-left">
							<?php if ( $show_team ) : ?>
								<?php echo f365_flag_icon_html( $team_cid, $team_cname ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><a href="<?php echo esc_url( $team_url ); ?>"><?php echo esc_html( $team_label ); ?></a>
							<?php endif; ?>
						</td>
						<td class="align-left hide-tiny hide-small"><?php echo esc_html( (string) $tr->transfer_type ); ?></td>
						<td class="align-left hide-tiny hide-small"><?php echo f365_transfer_value_html( (string) $tr->value ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}
}

/* -------------------------------------------------------------------------
 * Query parameters
 * ---------------------------------------------------------------------- */

$transfer_option = isset( $_GET['transfer_option'] ) ? sanitize_text_field( wp_unslash( $_GET['transfer_option'] ) ) : 'trans_op_all';
if ( ! in_array( $transfer_option, array( 'trans_op_all', 'trans_op_in', 'trans_op_out' ), true ) ) {
	$transfer_option = 'trans_op_all';
}

$transfer_type = isset( $_GET['transfer_type'] ) ? sanitize_text_field( wp_unslash( $_GET['transfer_type'] ) ) : 'trans_type_all';
if ( ! in_array( $transfer_type, array( 'trans_type_all', 'trans_type_definitive', 'trans_type_loan' ), true ) ) {
	$transfer_type = 'trans_type_all';
}

/* team_step value is a Django teamstep id (matches the Django form). */
$selected_team_step = isset( $_GET['team_step'] ) ? (int) $_GET['team_step'] : 0;

/* -------------------------------------------------------------------------
 * Build the view model & Base URLs
 * ---------------------------------------------------------------------- */

$teams          = array();
$dropdown_teams = array();

if ( $repo ) {
	$view  = $repo->get_competition_transfers_view( $competition_id, $comp_edition, $transfer_type, $selected_team_step );
	$teams = $view['teams'];

	// OPTIMIZATION: Avoid calling the repository twice if no team_step filter is running
	if ( $selected_team_step ) {
		$dropdown_view  = $repo->get_competition_transfers_view( $competition_id, $comp_edition, $transfer_type, 0 );
		$dropdown_teams = $dropdown_view['teams'];
	} else {
		$dropdown_teams = $teams;
	}
}

// Strip query arguments before modifying path structures
$raw_permalink = get_permalink( $current_post_id );
$clean_path    = strtok( $raw_permalink, '?' );
$base_url      = user_trailingslashit( trailingslashit( $clean_path ) . 'transferencias' );

// Safe execution target fallback for the GET form action to keep the slug intact
$form_action_url = ! empty( $_SERVER['REQUEST_URI'] ) ? strtok( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), '?' ) : $base_url;
?>

<main id="primary" class="site-main single-article-page">

	<?php
	get_template_part(
		'template-parts/competition/f365-competition-header',
		null,
		array(
			'post_id'         => $current_post_id,
			'current_section' => $current_section,
			'sections'        => $sections,
			'edition'         => $comp_edition,
			'competition_id'  => $final_competition_id,
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

			<?php if ( ! empty( $dropdown_teams ) ) : ?>
				<form class="ink-form ink-form-option" method="get" action="<?php echo esc_url( $form_action_url ); ?>">
					<div class="column-group">
						<div class="control-group all-33 tiny-100 small-100">
							<div class="control">
								<select name="team_step" id="id_team_step" onchange="if(this.form){this.form.submit();}">
									<option value=""><?php esc_html_e( 'Equipas', 'futebol365' ); ?></option>
									<?php foreach ( $dropdown_teams as $team ) : ?>
										<option value="<?php echo esc_attr( $team['teamstep_id'] ); ?>" <?php selected( $selected_team_step, $team['teamstep_id'] ); ?>><?php echo esc_html( $team['title'] ); ?></option>
									<?php endforeach; ?>
								</select>
							</div>
						</div>
						<div class="control-group all-33 tiny-100 small-100">
							<div class="control">
								<select name="transfer_option" id="id_transfer_option" onchange="if(this.form){this.form.submit();}">
									<option value="trans_op_all" <?php selected( $transfer_option, 'trans_op_all' ); ?>><?php esc_html_e( 'Opções', 'futebol365' ); ?></option>
									<option value="trans_op_in" <?php selected( $transfer_option, 'trans_op_in' ); ?>><?php esc_html_e( 'Entradas', 'futebol365' ); ?></option>
									<option value="trans_op_out" <?php selected( $transfer_option, 'trans_op_out' ); ?>><?php esc_html_e( 'Saídas', 'futebol365' ); ?></option>
								</select>
							</div>
						</div>
						<div class="control-group all-33 tiny-100 small-100">
							<div class="control">
								<select name="transfer_type" id="id_transfer_type" onchange="if(this.form){this.form.submit();}">
									<option value="trans_type_all" <?php selected( $transfer_type, 'trans_type_all' ); ?>><?php esc_html_e( 'Tipo', 'futebol365' ); ?></option>
									<option value="trans_type_definitive" <?php selected( $transfer_type, 'trans_type_definitive' ); ?>><?php esc_html_e( 'Definitivo', 'futebol365' ); ?></option>
									<option value="trans_type_loan" <?php selected( $transfer_type, 'trans_type_loan' ); ?>><?php esc_html_e( 'Empréstimo', 'futebol365' ); ?></option>
								</select>
							</div>
						</div>
					</div>
					<?php if ( $comp_edition ) : ?>
						<input type="hidden" name="edition" value="<?php echo esc_attr( $comp_edition ); ?>">
					<?php endif; ?>
				</form>
			<?php endif; ?>

			<?php if ( empty( $teams ) ) : ?>

				<h5 class="condensed-400 headerTitle blue"><?php echo esc_html( $level_1_title ); ?></h5>
				<h6 class="no-info"><?php esc_html_e( 'Sem informação disponível', 'futebol365' ); ?></h6>

			<?php else : ?>

				<?php foreach ( $teams as $team ) : ?>
					<?php
					$transfers_in  = $team['in'];
					$transfers_out = $team['out'];

					$show_in  = ( 'trans_op_all' === $transfer_option || 'trans_op_in' === $transfer_option ) && ! empty( $transfers_in );
					$show_out = ( 'trans_op_all' === $transfer_option || 'trans_op_out' === $transfer_option ) && ! empty( $transfers_out );

					if ( ! $show_in && ! $show_out ) {
						continue;
					}
					?>

					<h6 class="condensed-400 headerTitle blue">
						<a href="<?php echo esc_url( (string) $team['team_url'] ); ?>"><?php echo esc_html( (string) $team['title'] ); ?></a>
					</h6>

					<?php if ( $show_in ) : ?>
						<h5 class="headerTitleSimpleBackground"><?php esc_html_e( 'Entradas', 'futebol365' ); ?></h5>
						<?php f365_transfer_render_table( $transfers_in, 'Origem', 'origin' ); ?>
					<?php endif; ?>

					<?php if ( $show_out ) : ?>
						<h5 class="headerTitleSimpleBackground"><?php esc_html_e( 'Saídas', 'futebol365' ); ?></h5>
						<?php f365_transfer_render_table( $transfers_out, 'Destino', 'dest' ); ?>
					<?php endif; ?>

				<?php endforeach; ?>

			<?php endif; ?>
			<?php get_template_part('template-parts/template-front-page-posts'); ?>

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

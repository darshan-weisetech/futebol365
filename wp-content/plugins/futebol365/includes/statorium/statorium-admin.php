<?php
/**
 * Statorium — admin settings screen (legacy in-plugin module).
 * Only loads when the f365-statorium plugin is inactive (see loader).
 *
 * @package Futebol365
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', function () {
	add_options_page( 'Statorium Sync', 'Statorium Sync', 'manage_options', 'f365-statorium', 'f365_statorium_render_settings' );
} );

function f365_statorium_render_settings(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$settings = F365_Statorium_Importer::settings();

	if ( isset( $_GET['f365stat_msg'] ) ) {
		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html( sanitize_text_field( wp_unslash( $_GET['f365stat_msg'] ) ) )
		);
	}
	?>
	<div class="wrap">
		<h1>Statorium Sync <small>(legacy module)</small></h1>
		<p>The dedicated <code>f365-statorium</code> plugin is inactive — this in-plugin fallback is serving the integration.</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'f365stat_save' ); ?>
			<input type="hidden" name="action" value="f365stat_save">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="f365stat_key">API key</label></th>
					<td>
						<?php if ( defined( 'F365_STATORIUM_API_KEY' ) && F365_STATORIUM_API_KEY ) : ?>
							<code><?php echo esc_html( substr( F365_STATORIUM_API_KEY, 0, 6 ) ); ?>…</code>
							<p class="description">Defined in <code>wp-config.php</code>.</p>
						<?php else : ?>
							<input type="text" id="f365stat_key" name="api_key" class="regular-text code" value="<?php echo esc_attr( (string) $settings['api_key'] ); ?>">
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="f365stat_seasons">Season IDs</label></th>
					<td>
						<input type="text" id="f365stat_seasons" name="seasons" class="regular-text code" value="<?php echo esc_attr( (string) $settings['seasons'] ); ?>" placeholder="614">
					</td>
				</tr>
				<tr>
					<th scope="row">Legacy data policy</th>
					<td>
						<?php $mode = (string) ( $settings['update_mode'] ?? 'strict' ); ?>
						<label><input type="radio" name="update_mode" value="strict" <?php checked( 'strict', $mode ); ?>> <strong>Strict</strong> — never modify old data</label><br>
						<label><input type="radio" name="update_mode" value="fill-gaps" <?php checked( 'fill-gaps', $mode ); ?>> <strong>Fill gaps</strong> — fill empty fields only</label><br>
						<label><input type="radio" name="update_mode" value="full" <?php checked( 'full', $mode ); ?>> <strong>Full</strong> — Statorium overwrites</label>
					</td>
				</tr>
				<tr>
					<th scope="row">Automatic sync</th>
					<td>
						<label><input type="checkbox" name="cron" value="1" <?php checked( ! empty( $settings['cron'] ) ); ?>>
						Enable cron (hourly refresh + live ticker)</label>
					</td>
				</tr>
			</table>
			<?php submit_button( 'Save settings' ); ?>
		</form>

		<h2>Manual sync</h2>
		<?php
		$seasons = array_filter( array_map( 'intval', explode( ',', (string) $settings['seasons'] ) ) );
		foreach ( $seasons as $sid ) :
			$base = admin_url( 'admin-post.php?action=f365stat_sync&season=' . $sid );
			?>
			<p>
				<strong>Season <?php echo (int) $sid; ?>:</strong>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( $base . '&what=teams', 'f365stat_sync' ) ); ?>">Sync teams + players</a>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( $base . '&what=matches', 'f365stat_sync' ) ); ?>">Sync matches</a>
				<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( $base . '&what=all', 'f365stat_sync' ) ); ?>">Full sync</a>
			</p>
		<?php endforeach; ?>

		<p class="description">Log: <code>wp-content/uploads/f365-logs/f365-statorium-YYYY-MM-DD.log</code></p>
	</div>
	<?php
}

add_action( 'admin_post_f365stat_save', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Insufficient permissions.' );
	}
	check_admin_referer( 'f365stat_save' );

	$settings = F365_Statorium_Importer::settings();
	$mode     = (string) ( $_POST['update_mode'] ?? 'strict' );

	update_option( F365_Statorium_Importer::OPTION, [
		'api_key'     => isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['api_key'] ) ) : $settings['api_key'],
		'seasons'     => preg_replace( '/[^0-9,]/', '', (string) ( $_POST['seasons'] ?? '' ) ),
		'cron'        => empty( $_POST['cron'] ) ? 0 : 1,
		'update_mode' => in_array( $mode, [ 'strict', 'fill-gaps', 'full' ], true ) ? $mode : 'strict',
	], false );

	f365_statorium_reschedule_cron();

	wp_safe_redirect( admin_url( 'options-general.php?page=f365-statorium&f365stat_msg=' . rawurlencode( 'Settings saved.' ) ) );
	exit;
} );

add_action( 'admin_post_f365stat_sync', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Insufficient permissions.' );
	}
	check_admin_referer( 'f365stat_sync' );

	$season = (int) ( $_GET['season'] ?? 0 );
	$what   = sanitize_key( (string) ( $_GET['what'] ?? 'all' ) );

	@set_time_limit( 600 );
	$importer = new F365_Statorium_Importer();

	if ( 'all' === $what ) {
		$importer->import_season( $season );
	}
	if ( in_array( $what, [ 'teams', 'all' ], true ) ) {
		$importer->import_teams( $season, true );
	}
	if ( in_array( $what, [ 'matches', 'all' ], true ) ) {
		$importer->import_season( $season );
		$importer->import_matches( $season );
	}

	$msg = 'Sync done: ' . ( $importer->stats ? wp_json_encode( $importer->stats ) : 'nothing to do' );
	wp_safe_redirect( admin_url( 'options-general.php?page=f365-statorium&f365stat_msg=' . rawurlencode( $msg ) ) );
	exit;
} );

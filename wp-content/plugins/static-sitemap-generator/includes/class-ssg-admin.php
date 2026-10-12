<?php
/**
 * Settings + status screen under Settings > Static Sitemap.
 */
class SSG_Admin {

	/** @var SSG_Generator */
	private $gen;

	public function __construct( SSG_Generator $generator ) {
		$this->gen = $generator;
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'maybe_handle_post' ) );
		add_action( 'admin_notices', array( $this, 'notices' ) );
	}

	public function menu() {
		add_options_page( 'Static Sitemap', 'Static Sitemap', 'manage_options', 'ssg', array( $this, 'render' ) );
	}

	public function notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! $this->gen->yoast_ready() ) {
			echo '<div class="notice notice-error"><p><strong>Static Sitemap:</strong> Yoast SEO is required and was not detected. Activate it, then rebuild.</p></div>';
		}
		if ( ! is_writable( $this->gen->dir() ) && is_dir( $this->gen->dir() ) ) {
			echo '<div class="notice notice-error"><p><strong>Static Sitemap:</strong> the output directory <code>' . esc_html( $this->gen->dir() ) . '</code> is not writable.</p></div>';
		}
	}

	public function maybe_handle_post() {
		if ( empty( $_POST['ssg_action'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		check_admin_referer( 'ssg_admin' );
		$action = sanitize_text_field( wp_unslash( $_POST['ssg_action'] ) );

		if ( 'save' === $action ) {
			update_option(
				'ssg_settings',
				array(
					'post_types'   => isset( $_POST['post_types'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['post_types'] ) ) : array(),
					'taxonomies'   => isset( $_POST['taxonomies'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['taxonomies'] ) ) : array(),
					'gzip'         => empty( $_POST['gzip'] ) ? 0 : 1,
					'disable_core' => empty( $_POST['disable_core'] ) ? 0 : 1,
				),
				false
			);
			add_settings_error( 'ssg', 'saved', 'Settings saved.', 'updated' );
		}

		if ( 'index' === $action ) {
			$this->gen->build_index();
			add_settings_error( 'ssg', 'index', 'Sitemap index rebuilt.', 'updated' );
		}

		if ( 'rebuild_object' === $action ) {
			$kind   = isset( $_POST['kind'] ) ? sanitize_key( $_POST['kind'] ) : '';
			$object = isset( $_POST['object'] ) ? sanitize_key( $_POST['object'] ) : '';
			@set_time_limit( 0 );
			if ( 'post' === $kind && $object ) {
				$this->gen->generate_post_type( $object );
			} elseif ( 'tax' === $kind && $object ) {
				$this->gen->generate_taxonomy( $object );
			} elseif ( 'author' === $kind ) {
				$this->gen->generate_authors();
			}
			$this->gen->build_index();
			add_settings_error( 'ssg', 'rebuilt', 'Rebuilt: ' . ( $object ? $object : $kind ), 'updated' );
		}

		set_transient( 'settings_errors', get_settings_errors(), 30 );
		wp_safe_redirect( add_query_arg( array( 'page' => 'ssg', 'updated' => 'true' ), admin_url( 'options-general.php' ) ) );
		exit;
	}

	public function render() {
		$settings     = $this->gen->settings();
		$all_types    = get_post_types( array( 'public' => true ), 'objects' );
		unset( $all_types['attachment'] );
		$all_taxes    = get_taxonomies( array( 'public' => true ), 'objects' );
		$registry     = $this->gen->get_registry();
		$active_types = $this->gen->post_types();
		$active_taxes = $this->gen->taxonomies();
		?>
		<div class="wrap">
			<h1>Static Sitemap</h1>
			<?php settings_errors( 'ssg' ); ?>

			<p>
				Source: <strong>Yoast SEO noindex rules</strong> (no reindex needed)
				&nbsp;|&nbsp; Index: <a href="<?php echo esc_url( $this->gen->index_url() ); ?>" target="_blank"><code><?php echo esc_html( $this->gen->index_url() ); ?></code></a>
				&nbsp;|&nbsp; Dir: <code><?php echo esc_html( $this->gen->dir() ); ?></code>
				&nbsp;|&nbsp; Chunk: <code><?php echo (int) SSG_CHUNK; ?></code> URLs/file
			</p>

			<div class="notice notice-info inline" style="padding:8px 12px;">
				<strong>First build (millions of posts):</strong> run via WP-CLI, not this page.
				<pre style="margin:6px 0;">wp staticsitemap generate --all</pre>
				Files mirror Yoast naming (<code>post-sitemap.xml</code>, <code>category-sitemap.xml</code>, <code>author-sitemap.xml</code>) but are bucketed by ID range so single-file incremental updates stay correct.
			</div>

			<form method="post">
				<?php wp_nonce_field( 'ssg_admin' ); ?>
				<input type="hidden" name="ssg_action" value="save">
				<h2>Included content</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Post types</th>
						<td>
							<?php foreach ( $all_types as $name => $obj ) : ?>
								<label style="display:inline-block;margin:0 14px 6px 0;">
									<input type="checkbox" name="post_types[]" value="<?php echo esc_attr( $name ); ?>" <?php checked( in_array( $name, $active_types, true ) ); ?>>
									<?php echo esc_html( $obj->labels->singular_name ); ?> <code><?php echo esc_html( $name ); ?></code>
								</label>
							<?php endforeach; ?>
							<p class="description">Leave all unchecked to use the default (all public types except attachments). Types set to noindex in Yoast are skipped automatically.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Taxonomies</th>
						<td>
							<?php foreach ( $all_taxes as $name => $obj ) : ?>
								<label style="display:inline-block;margin:0 14px 6px 0;">
									<input type="checkbox" name="taxonomies[]" value="<?php echo esc_attr( $name ); ?>" <?php checked( in_array( $name, $active_taxes, true ) ); ?>>
									<?php echo esc_html( $obj->labels->singular_name ); ?> <code><?php echo esc_html( $name ); ?></code>
								</label>
							<?php endforeach; ?>
						</td>
					</tr>
					<tr>
						<th scope="row">Author sitemap</th>
						<td><?php echo $this->gen->authors_enabled() ? 'Enabled (follows Yoast author archive settings).' : 'Disabled in Yoast (author archives off / noindexed).'; ?></td>
					</tr>
					<tr>
						<th scope="row">Options</th>
						<td>
							<label style="display:block;margin-bottom:6px;"><input type="checkbox" name="gzip" value="1" <?php checked( ! empty( $settings['gzip'] ) ); ?>> Write gzipped files (<code>.xml.gz</code>). Toggling requires a full rebuild.</label>
							<label style="display:block;"><input type="checkbox" name="disable_core" value="1" <?php checked( ! empty( $settings['disable_core'] ) ); ?>> Disable WordPress core sitemaps (<code>/wp-sitemap.xml</code>).</label>
						</td>
					</tr>
				</table>
				<?php submit_button( 'Save settings' ); ?>
			</form>

			<hr>
			<h2>Generate / rebuild</h2>
			<p class="description">Every included object is listed below, even if it has never been built. A new post type appears here as soon as it is public and included above &mdash; click Generate to build it (use WP-CLI for large sets). "Rebuild index only" does not scan posts, so it cannot add a new object.</p>
			<table class="widefat striped" style="max-width:760px;">
				<thead><tr><th>Kind</th><th>Object</th><th>Files</th><th>URLs</th><th>Action</th></tr></thead>
				<tbody>
				<?php
				$total = 0;

				// Build the list of objects to show from the *current* configuration,
				// so newly registered/included types show up immediately (count 0).
				$objects = array();
				foreach ( $active_types as $pt ) {
					$objects[] = array( 'post', $pt );
				}
				foreach ( $active_taxes as $tx ) {
					$objects[] = array( 'tax', $tx );
				}
				if ( $this->gen->authors_enabled() ) {
					$objects[] = array( 'author', 'author' );
				}

				foreach ( $objects as $obj ) {
					list( $kind, $object ) = $obj;
					$buckets = isset( $registry[ $kind ][ $object ] ) ? $registry[ $kind ][ $object ] : array();
					$files   = count( $buckets );
					$urls    = $buckets ? array_sum( wp_list_pluck( $buckets, 'count' ) ) : 0;
					$total  += $urls;
					$label   = ( 0 === $files ) ? 'Generate' : 'Rebuild';
					?>
					<tr>
						<td><?php echo esc_html( $kind ); ?></td>
						<td><code><?php echo esc_html( $object ); ?></code></td>
						<td><?php echo (int) $files; ?></td>
						<td><?php echo esc_html( number_format_i18n( $urls ) ); ?></td>
						<td>
							<form method="post" style="display:inline;" onsubmit="return confirm('Foreground build can time out on large objects. Use WP-CLI for big sets. Continue?');">
								<?php wp_nonce_field( 'ssg_admin' ); ?>
								<input type="hidden" name="ssg_action" value="rebuild_object">
								<input type="hidden" name="kind" value="<?php echo esc_attr( $kind ); ?>">
								<input type="hidden" name="object" value="<?php echo esc_attr( $object ); ?>">
								<button class="button button-small"><?php echo esc_html( $label ); ?></button>
							</form>
						</td>
					</tr>
					<?php
				}
				?>
				</tbody>
				<tfoot><tr><th colspan="3">Total</th><th colspan="2"><?php echo esc_html( number_format_i18n( $total ) ); ?> URLs</th></tr></tfoot>
			</table>

			<p style="margin-top:14px;">
				<form method="post" style="display:inline;">
					<?php wp_nonce_field( 'ssg_admin' ); ?>
					<input type="hidden" name="ssg_action" value="index">
					<button class="button">Rebuild index only</button>
				</form>
			</p>
		</div>
		<?php
	}
}
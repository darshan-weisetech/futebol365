<?php
/**
 * Plugin Name: Yoast Keyset Sitemap
 * Description: Replaces Yoast's LIMIT/OFFSET sitemap paging with keyset (seek) pagination backed by a precomputed page-boundary cursor table. Deep sitemap pages stop scanning millions of rows. Built for very large sites.
 * Version: 1.0.0
 * Author: nishit-weisetech
 * Requires Plugins: wordpress-seo
 *
 * WHY
 * ---
 * Yoast pages its sitemaps with `ORDER BY post_modified ASC LIMIT 100 OFFSET n`,
 * and get_sitemap_links() issues TEN of those per sitemap page (steps of 100).
 * MySQL must walk and discard every row before the offset, so cost grows
 * linearly with page depth. Measured on 85k posts: OFFSET 84900 took 588ms and
 * touched 85,000 rows; the keyset equivalent took 0.6ms and touched 100.
 *
 * HOW
 * ---
 * A cursor table stores the (post_modified, ID) of each page boundary.
 * cursor[N] is the LAST row of page N-1, so page N is the key range
 * ( cursor[N], cursor[N+1] ]. Because those boundaries tile the whole keyspace
 * contiguously, every post falls in exactly one page even when the cursors are
 * stale -- staleness only makes page sizes uneven, it can never skip or
 * duplicate a URL. That is why pages are bounded by the NEXT cursor rather than
 * by LIMIT: a LIMIT would silently drop rows that drifted into a full range.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Yoast_Keyset_Sitemap {

	const VERSION          = '1.0.0';
	const TABLE            = 'yks_cursors';
	const OPTION_META      = 'yks_meta';
	const OPTION_TTL       = 'yks_ttl';
	const OPTION_DB_VER    = 'yks_db_version';
	const DB_VERSION       = 1;
	const DB_INDEX_NAME    = 'yks_type_status_gmt';
	const NONCE_ACTION     = 'yks_actions';
	const DEFAULT_TTL      = 1800; // 30 min: long enough to span a full LSM regeneration run.
	const BUILD_LOCK       = 'yks_building_';
	const CHUNK            = 100;  // Rows materialised into WP_Post objects at a time.

	/** @var Yoast_Keyset_Sitemap */
	private static $instance;

	/** @var array Per-request cache of cursor rows, keyed by post type. */
	private $cursor_cache = array();

	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Yoast builds its provider list on after_setup_theme:10 and the
		// `wpseo_sitemaps_providers` filter only APPENDS -- an appended provider
		// never wins handles_type() against the built-in one. So swap the
		// instance in the public $providers array afterwards instead.
		add_action( 'after_setup_theme', array( $this, 'swap_provider' ), 99 );

		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_notices', array( $this, 'index_missing_notice' ) );
		add_action( 'admin_post_yks_rebuild', array( $this, 'handle_rebuild' ) );
		add_action( 'admin_post_yks_create_index', array( $this, 'handle_create_index' ) );
		add_action( 'admin_post_yks_save_settings', array( $this, 'handle_save_settings' ) );

		// Public entry point so other code (e.g. a sitemap generator) can force
		// a rebuild at the start of its run: do_action( 'yks_rebuild_cursors' ).
		add_action( 'yks_rebuild_cursors', array( $this, 'rebuild_all' ) );
	}

	/* -------------------------------------------------------------------
	 * Bootstrap
	 * ---------------------------------------------------------------- */

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
				post_type varchar(32) NOT NULL,
				page_no int unsigned NOT NULL,
				post_modified datetime NOT NULL,
				post_modified_gmt datetime NOT NULL,
				post_id bigint(20) unsigned NOT NULL,
				PRIMARY KEY  (post_type,page_no)
			) {$charset};"
		);

		update_option( self::OPTION_DB_VER, self::DB_VERSION );

		// The index is deliberately NOT created here. On a multi-million row
		// wp_posts the ALTER can outlive a proxy timeout (Cloudflare cuts at
		// ~100s and returns 524), and activation happens in a browser request.
		// It is driven from the admin button / `wp yks index` instead, where
		// running long is expected. An admin notice nags until it exists.
	}

	/**
	 * Index that makes Yoast's "newest post per type" lookup indexable:
	 *
	 *   SELECT post_type, MAX(post_modified_gmt) FROM wp_posts
	 *   WHERE post_status IN (...) AND post_type IN (...) GROUP BY post_type
	 *
	 * WPSEO_Sitemaps::get_last_modified_gmt() runs that on every sitemap index
	 * build, and it is NOT covered by LSM's index -- that one ends in
	 * post_modified, this query aggregates post_modified_gmt. With 'inherit' in
	 * the status list it scans every attachment too. Measured here: 470,381 rows
	 * / 4115ms before, 4 rows / 0.85ms after, via a loose index skip-scan.
	 *
	 * There is no filter on that query, and the taxonomy provider calls the same
	 * method, so an index is the only way to fix it from outside Yoast.
	 *
	 * InnoDB builds this with ALGORITHM=INPLACE, LOCK=NONE -- reads and writes
	 * continue during the build. Took ~3.6s over 470k rows; expect roughly
	 * linear growth with table size.
	 *
	 * @return true|string true when created, message otherwise.
	 */
	public function ensure_db_index() {
		global $wpdb;

		if ( $this->db_index_exists() ) {
			return 'Index already exists.';
		}

		$wpdb->query(
			'ALTER TABLE ' . $wpdb->posts . ' ADD INDEX ' . self::DB_INDEX_NAME
			. ' (post_type, post_status, post_modified_gmt)'
		);

		if ( $wpdb->last_error ) {
			return 'Failed: ' . $wpdb->last_error;
		}

		return true;
	}

	/**
	 * Without this index the sitemap INDEX page (only that page -- no sub-page
	 * touches the query) runs an unindexed MAX(post_modified_gmt) scan and can
	 * exceed a proxy timeout entirely. Worth nagging about.
	 */
	public function index_missing_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && $screen->id === 'tools_page_yoast-keyset-sitemap' ) {
			return; // The settings page shows its own panel.
		}
		if ( $this->db_index_exists() ) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p><strong>Keyset Sitemap:</strong> the '
			. '<code>%s</code> index is missing, so <code>sitemap_index.xml</code> runs an '
			. 'unindexed scan over every post and attachment and may time out. '
			. '<a href="%s">Create it</a> (or run <code>wp yks index</code>).</p></div>',
			esc_html( self::DB_INDEX_NAME ),
			esc_url( admin_url( 'tools.php?page=yoast-keyset-sitemap' ) )
		);
	}

	public function db_index_exists() {
		global $wpdb;
		return (bool) $wpdb->get_var(
			$wpdb->prepare( "SHOW INDEX FROM {$wpdb->posts} WHERE Key_name = %s", self::DB_INDEX_NAME )
		);
	}

	public static function uninstall() {
		global $wpdb;
		$table = self::table();
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );

		// Drop our index, but only ours -- LSM's lsm_type_status_modified is
		// not ours to remove and Yoast still needs it.
		if ( self::instance()->db_index_exists() ) {
			$wpdb->query( 'ALTER TABLE ' . $wpdb->posts . ' DROP INDEX ' . self::DB_INDEX_NAME );
		}

		delete_option( self::OPTION_META );
		delete_option( self::OPTION_TTL );
		delete_option( self::OPTION_DB_VER );
	}

	/**
	 * Replace Yoast's post type provider with the keyset subclass, in place,
	 * so it keeps its position ahead of any third-party providers.
	 */
	public function swap_provider() {
		if ( empty( $GLOBALS['wpseo_sitemaps'] ) || ! isset( $GLOBALS['wpseo_sitemaps']->providers ) ) {
			return;
		}
		if ( ! class_exists( 'WPSEO_Post_Type_Sitemap_Provider' ) ) {
			return;
		}

		require_once __DIR__ . '/class-keyset-post-type-provider.php';

		foreach ( $GLOBALS['wpseo_sitemaps']->providers as $i => $provider ) {
			if ( $provider instanceof YKS_Post_Type_Sitemap_Provider ) {
				return; // Already swapped.
			}
			if ( $provider instanceof WPSEO_Post_Type_Sitemap_Provider ) {
				$GLOBALS['wpseo_sitemaps']->providers[ $i ] = new YKS_Post_Type_Sitemap_Provider();
				return;
			}
		}
	}

	/* -------------------------------------------------------------------
	 * Settings / meta
	 * ---------------------------------------------------------------- */

	public function ttl() {
		$v = (int) get_option( self::OPTION_TTL, self::DEFAULT_TTL );
		return ( $v >= 60 ) ? $v : self::DEFAULT_TTL;
	}

	/** @return array post_type => array{pages,total,built,max} */
	public function meta() {
		$m = get_option( self::OPTION_META, array() );
		return is_array( $m ) ? $m : array();
	}

	private function set_meta( $post_type, $data ) {
		$meta               = $this->meta();
		$meta[ $post_type ] = $data;
		update_option( self::OPTION_META, $meta, false );
	}

	/**
	 * Are the stored cursors usable for this post type right now?
	 *
	 * Note staleness is SAFE (the boundaries still tile the keyspace, so no URL
	 * is lost) -- this only decides when a refresh is worth the cost.
	 */
	public function cursors_are_fresh( $post_type, $max_entries ) {
		$meta = $this->meta();
		if ( empty( $meta[ $post_type ] ) ) {
			return false;
		}
		$m = $meta[ $post_type ];

		// Entries-per-page changed: every boundary is wrong.
		if ( (int) $m['max'] !== (int) $max_entries ) {
			return false;
		}

		return ( time() - (int) $m['built'] ) < $this->ttl();
	}

	/* -------------------------------------------------------------------
	 * Cursor building
	 * ---------------------------------------------------------------- */

	/**
	 * Walk the index recording each page boundary.
	 *
	 * Each step is `LIMIT 1 OFFSET max_entries-1` measured FROM the previous
	 * cursor, so it scans exactly one page worth of index entries (~1ms) --
	 * bounded, unlike the deep offsets this plugin exists to remove. One step
	 * per page: 86 queries for 86k posts, ~5000 for 5M.
	 *
	 * @param string $post_type   Post type to build cursors for.
	 * @param int    $max_entries Entries per sitemap page.
	 * @return array{pages:int,total:int}
	 */
	public function build_cursors( $post_type, $max_entries = null ) {
		global $wpdb;

		if ( null === $max_entries ) {
			$max_entries = $this->max_entries();
		}
		$max_entries = max( 1, (int) $max_entries );

		$provider = $this->provider();
		if ( ! $provider ) {
			return array(
				'pages' => 0,
				'total' => 0,
			);
		}

		$total = $provider->public_post_type_count( $post_type );
		$table = self::table();

		$rows   = array();
		$cursor = null;
		$page   = 1;

		// Stop before the boundary that would land on the very last row -- that
		// would open an empty final page.
		while ( ( $page * $max_entries ) < $total ) {
			$row = $provider->public_boundary_row( $post_type, $cursor, $max_entries - 1 );
			if ( ! $row ) {
				break;
			}
			++$page;
			$rows[] = $wpdb->prepare(
				'(%s,%d,%s,%s,%d)',
				$post_type,
				$page,
				$row->post_modified,
				$row->post_modified_gmt,
				$row->ID
			);
			$cursor = array(
				'm'  => $row->post_modified,
				'id' => (int) $row->ID,
			);
		}

		// Swap the boundary set atomically-ish: delete + insert in one go so a
		// concurrent reader never sees a half-written set of pages.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE post_type = %s", $post_type ) );

		foreach ( array_chunk( $rows, 500 ) as $chunk ) {
			$wpdb->query(
				"INSERT INTO {$table} (post_type,page_no,post_modified,post_modified_gmt,post_id) VALUES "
				. implode( ',', $chunk )
			);
		}

		// A post type with no entries has no pages at all -- $page starts at 1
		// because the walk counts boundaries, not content.
		$pages = ( $total > 0 ) ? $page : 0;

		$this->set_meta(
			$post_type,
			array(
				'pages' => $pages,
				'total' => $total,
				'built' => time(),
				'max'   => $max_entries,
			)
		);

		unset( $this->cursor_cache[ $post_type ] );

		return array(
			'pages' => $pages,
			'total' => $total,
		);
	}

	/**
	 * Rebuild cursors for a post type unless a concurrent build is running.
	 * If cursors already exist we happily keep serving the stale ones rather
	 * than stampeding -- they are still correct, just unevenly sized.
	 */
	public function maybe_build( $post_type, $max_entries ) {
		if ( $this->cursors_are_fresh( $post_type, $max_entries ) ) {
			return;
		}

		$lock = self::BUILD_LOCK . md5( $post_type );
		$meta = $this->meta();
		$have = ! empty( $meta[ $post_type ] );

		if ( get_transient( $lock ) ) {
			if ( $have ) {
				return; // Someone else is building; stale cursors are safe.
			}
		}

		set_transient( $lock, 1, 300 );
		try {
			$this->build_cursors( $post_type, $max_entries );
		} finally {
			delete_transient( $lock );
		}
	}

	public function rebuild_all() {
		$max     = $this->max_entries();
		$results = array();
		foreach ( $this->sitemap_post_types() as $post_type ) {
			$results[ $post_type ] = $this->build_cursors( $post_type, $max );
		}
		return $results;
	}

	/**
	 * Entries per sitemap page. Mirrors WPSEO_Sitemaps::get_entries_per_page()
	 * (class-sitemaps.php) -- there is no static property to read.
	 */
	public function max_entries() {
		return (int) apply_filters( 'wpseo_sitemap_entries_per_page', 1000 );
	}

	/**
	 * Post types Yoast actually emits sitemaps for.
	 *
	 * Filtered with is_valid_post_type(), matching get_index_links(). NOT
	 * handles_type(), which is only post_type_exists() and would return every
	 * registered type.
	 *
	 * @return string[]
	 */
	public function sitemap_post_types() {
		$provider = $this->provider();
		if ( ! $provider || ! class_exists( 'WPSEO_Post_Type' ) ) {
			return array();
		}
		$types = WPSEO_Post_Type::get_accessible_post_types();
		return array_values( array_filter( $types, array( $provider, 'is_valid_post_type' ) ) );
	}

	/** @return YKS_Post_Type_Sitemap_Provider|null */
	public function provider() {
		if ( ! empty( $GLOBALS['wpseo_sitemaps']->providers ) ) {
			foreach ( $GLOBALS['wpseo_sitemaps']->providers as $provider ) {
				if ( $provider instanceof YKS_Post_Type_Sitemap_Provider ) {
					return $provider;
				}
			}
		}

		// WP-CLI / activation can run before after_setup_theme has swapped the
		// provider in. Build a standalone one so cursors can still be rebuilt.
		if ( ! class_exists( 'WPSEO_Post_Type_Sitemap_Provider' ) ) {
			return null;
		}
		require_once __DIR__ . '/class-keyset-post-type-provider.php';
		return new YKS_Post_Type_Sitemap_Provider();
	}

	/* -------------------------------------------------------------------
	 * Cursor reads
	 * ---------------------------------------------------------------- */

	/** @return array page_no => array{m,gmt,id} */
	public function cursors( $post_type ) {
		global $wpdb;

		if ( isset( $this->cursor_cache[ $post_type ] ) ) {
			return $this->cursor_cache[ $post_type ];
		}

		$table = self::table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT page_no, post_modified, post_modified_gmt, post_id FROM {$table} WHERE post_type = %s ORDER BY page_no ASC",
				$post_type
			)
		);

		$out = array();
		foreach ( (array) $rows as $r ) {
			$out[ (int) $r->page_no ] = array(
				'm'   => $r->post_modified,
				'gmt' => $r->post_modified_gmt,
				'id'  => (int) $r->post_id,
			);
		}

		$this->cursor_cache[ $post_type ] = $out;
		return $out;
	}

	public function page_count( $post_type ) {
		$meta = $this->meta();
		return isset( $meta[ $post_type ]['pages'] ) ? (int) $meta[ $post_type ]['pages'] : 0;
	}

	public function total_count( $post_type ) {
		$meta = $this->meta();
		return isset( $meta[ $post_type ]['total'] ) ? (int) $meta[ $post_type ]['total'] : 0;
	}

	/* -------------------------------------------------------------------
	 * Admin UI
	 * ---------------------------------------------------------------- */

	public function admin_menu() {
		add_management_page(
			'Keyset Sitemap',
			'Keyset Sitemap',
			'manage_options',
			'yoast-keyset-sitemap',
			array( $this, 'render_page' )
		);
	}

	private function verify_request() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions.' );
		}
		check_admin_referer( self::NONCE_ACTION );
	}

	private function redirect_back() {
		wp_safe_redirect( admin_url( 'tools.php?page=yoast-keyset-sitemap' ) );
		exit;
	}

	public function handle_rebuild() {
		$this->verify_request();
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 );
		}
		$t       = microtime( true );
		$results = $this->rebuild_all();
		set_transient(
			'yks_notice',
			array(
				'results' => $results,
				'seconds' => microtime( true ) - $t,
			),
			60
		);
		$this->redirect_back();
	}

	public function handle_create_index() {
		$this->verify_request();
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 );
		}
		$r = $this->ensure_db_index();
		set_transient( 'yks_index_notice', ( true === $r ) ? 'Index created.' : $r, 60 );
		$this->redirect_back();
	}

	public function handle_save_settings() {
		$this->verify_request();
		$ttl = isset( $_POST['yks_ttl_min'] ) ? (int) $_POST['yks_ttl_min'] : 0;
		update_option( self::OPTION_TTL, ( $ttl >= 1 ) ? $ttl * 60 : self::DEFAULT_TTL );
		$this->redirect_back();
	}

	public function render_page() {
		$meta   = $this->meta();
		$notice = get_transient( 'yks_notice' );
		if ( $notice ) {
			delete_transient( 'yks_notice' );
		}
		$active = (bool) $this->provider();
		?>
		<div class="wrap">
			<h1>Keyset Sitemap</h1>

			<?php if ( ! $active ) : ?>
				<div class="notice notice-error"><p>
					The keyset provider is <strong>not active</strong>. Yoast SEO must be enabled
					and its sitemap feature turned on.
				</p></div>
			<?php else : ?>
				<div class="notice notice-success"><p>
					Keyset provider is active. Sitemap pages are served by seek queries instead of
					<code>LIMIT/OFFSET</code>.
				</p></div>
			<?php endif; ?>

			<?php if ( $notice ) : ?>
				<div class="notice notice-info"><p>
					Rebuilt cursors for <?php echo (int) count( $notice['results'] ); ?> post type(s)
					in <?php echo esc_html( number_format( $notice['seconds'], 2 ) ); ?>s.
				</p></div>
			<?php endif; ?>

			<?php
			$index_notice = get_transient( 'yks_index_notice' );
			if ( $index_notice ) {
				delete_transient( 'yks_index_notice' );
				echo '<div class="notice notice-info"><p>' . esc_html( $index_notice ) . '</p></div>';
			}
			?>

			<h2>Database index</h2>
			<?php if ( $this->db_index_exists() ) : ?>
				<p>
					<span class="dashicons dashicons-yes-alt" style="color:#00a32a"></span>
					<code><?php echo esc_html( self::DB_INDEX_NAME ); ?></code> is present on
					<code><?php echo esc_html( $GLOBALS['wpdb']->posts ); ?></code>.
				</p>
			<?php else : ?>
				<div class="notice notice-warning inline"><p>
					<strong>Missing <code><?php echo esc_html( self::DB_INDEX_NAME ); ?></code>.</strong>
					Without it, every sitemap <em>index</em> build runs an unindexed
					<code>MAX(post_modified_gmt)</code> scan across all post types (including every
					attachment). Measured on ~470k rows: 4115ms without, 0.85ms with.
				</p></div>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( self::NONCE_ACTION ); ?>
					<input type="hidden" name="action" value="yks_create_index">
					<?php submit_button( 'Create index', 'primary', 'submit', false ); ?>
					<p class="description">
						Built online (<code>ALGORITHM=INPLACE, LOCK=NONE</code>) &mdash; reads and
						writes continue while it runs. Roughly 3.6s per 470k rows.
					</p>
				</form>
			<?php endif; ?>

			<h2>Cursor status</h2>
			<table class="widefat striped">
				<thead>
					<tr>
						<th>Post type</th><th>Posts</th><th>Pages</th>
						<th>Entries/page</th><th>Built</th>
					</tr>
				</thead>
				<tbody>
				<?php if ( ! $meta ) : ?>
					<tr><td colspan="5">No cursors built yet. They are built automatically on the
						first sitemap request, or hit <em>Rebuild now</em>.</td></tr>
				<?php endif; ?>
				<?php foreach ( $meta as $post_type => $m ) : ?>
					<tr>
						<td><code><?php echo esc_html( $post_type ); ?></code></td>
						<td><?php echo esc_html( number_format( (int) $m['total'] ) ); ?></td>
						<td><?php echo esc_html( number_format( (int) $m['pages'] ) ); ?></td>
						<td><?php echo esc_html( (int) $m['max'] ); ?></td>
						<td><?php echo esc_html( human_time_diff( (int) $m['built'] ) ); ?> ago</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<p class="description" style="max-width:70em">
				Stale cursors are <strong>safe</strong>: the boundaries tile the whole
				<code>(post_modified, ID)</code> key space, so every post still appears on exactly
				one page. Staleness only makes page sizes drift from the target
				(e.g.&nbsp;1000&nbsp;&rarr;&nbsp;1050); it can never skip or duplicate a URL.
				Rebuilding just re-evens the pages.
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>
				<input type="hidden" name="action" value="yks_rebuild">
				<?php submit_button( 'Rebuild now', 'primary', 'submit', false ); ?>
			</form>

			<h2>Settings</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>
				<input type="hidden" name="action" value="yks_save_settings">
				<table class="form-table">
					<tr>
						<th scope="row"><label for="yks_ttl_min">Cursor lifetime</label></th>
						<td>
							<input name="yks_ttl_min" id="yks_ttl_min" type="number" min="1" step="1"
								value="<?php echo esc_attr( (int) round( $this->ttl() / 60 ) ); ?>" class="small-text"> minutes
							<p class="description">
								How long a cursor set is reused before the next sitemap request
								rebuilds it. Keep this comfortably longer than a full sitemap
								generation run so one run reuses a single consistent snapshot.
							</p>
						</td>
					</tr>
				</table>
				<?php submit_button( 'Save settings' ); ?>
			</form>
		</div>
		<?php
	}
}

register_activation_hook( __FILE__, array( 'Yoast_Keyset_Sitemap', 'install' ) );
register_uninstall_hook( __FILE__, array( 'Yoast_Keyset_Sitemap', 'uninstall' ) );

Yoast_Keyset_Sitemap::instance();

/* -----------------------------------------------------------------------
 * WP-CLI
 * -------------------------------------------------------------------- */
if ( defined( 'WP_CLI' ) && WP_CLI ) {

	/**
	 * Manage keyset sitemap cursors.
	 */
	class YKS_CLI_Command {

		/**
		 * Rebuild page-boundary cursors for every sitemap post type.
		 *
		 * ## EXAMPLES
		 *
		 *     wp yks rebuild
		 */
		public function rebuild() {
			$t       = microtime( true );
			$results = Yoast_Keyset_Sitemap::instance()->rebuild_all();

			if ( ! $results ) {
				WP_CLI::warning( 'No sitemap post types found. Is Yoast active with sitemaps enabled?' );
				return;
			}

			$rows = array();
			foreach ( $results as $post_type => $r ) {
				$rows[] = array(
					'post_type' => $post_type,
					'posts'     => $r['total'],
					'pages'     => $r['pages'],
				);
			}
			WP_CLI\Utils\format_items( 'table', $rows, array( 'post_type', 'posts', 'pages' ) );
			WP_CLI::success( sprintf( 'Rebuilt in %.2fs.', microtime( true ) - $t ) );
		}

		/**
		 * Create the post_modified_gmt index if missing.
		 *
		 * ## EXAMPLES
		 *
		 *     wp yks index
		 */
		public function index() {
			$r = Yoast_Keyset_Sitemap::instance()->ensure_db_index();
			if ( true === $r ) {
				WP_CLI::success( 'Index created.' );
			} else {
				WP_CLI::log( $r );
			}
		}

		/**
		 * Show cursor status.
		 *
		 * ## EXAMPLES
		 *
		 *     wp yks status
		 */
		public function status() {
			$plugin = Yoast_Keyset_Sitemap::instance();
			WP_CLI::log( 'db index: ' . ( $plugin->db_index_exists() ? 'present' : 'MISSING (run: wp yks index)' ) );
			$meta = $plugin->meta();
			if ( ! $meta ) {
				WP_CLI::log( 'No cursors built yet.' );
				return;
			}
			$rows = array();
			foreach ( $meta as $post_type => $m ) {
				$rows[] = array(
					'post_type'    => $post_type,
					'posts'        => $m['total'],
					'pages'        => $m['pages'],
					'entries_page' => $m['max'],
					'age'          => human_time_diff( (int) $m['built'] ) . ' ago',
				);
			}
			WP_CLI\Utils\format_items( 'table', $rows, array( 'post_type', 'posts', 'pages', 'entries_page', 'age' ) );
		}
	}

	WP_CLI::add_command( 'yks', 'YKS_CLI_Command' );
}

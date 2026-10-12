<?php
/**
 * Functions which enhance the theme by hooking into WordPress
 *
 * @package Futebol365
 */

/**
 * Adds custom classes to the array of body classes.
 *
 * @param array $classes Classes for the body element.
 * @return array
 */
function futebol365_body_classes($classes)
{
	// Adds a class of hfeed to non-singular pages.
	if (!is_singular()) {
		$classes[] = 'hfeed';
	}

	// Adds a class of no-sidebar when there is no sidebar present.
	if (!is_active_sidebar('sidebar-1')) {
		$classes[] = 'no-sidebar';
	}

	return $classes;
}
add_filter('body_class', 'futebol365_body_classes');

/**
 * Add a pingback url auto-discovery header for single posts, pages, or attachments.
 */
function futebol365_pingback_header()
{
	if (is_singular() && pings_open()) {
		printf('<link rel="pingback" href="%s">', esc_url(get_bloginfo('pingback_url')));
	}
}
add_action('wp_head', 'futebol365_pingback_header');

function futebol365_enqueue_assets()
{

	$theme_version = wp_get_theme()->get('Version');

	wp_enqueue_style(
		'futebol365-custom-style',
		get_template_directory_uri() . '/assets/css/custom-style.css',
		array(),
		$theme_version
	);

	wp_enqueue_script(
		'futebol365-custom-script',
		get_template_directory_uri() . '/assets/js/custom-script.js',
		array(),
		$theme_version,
		true
	);
}
add_action(
	'wp_enqueue_scripts',
	'futebol365_enqueue_assets'
);

function allow_svg_uploads($mimes)
{
	$mimes['svg'] = 'image/svg+xml';
	return $mimes;
}
add_filter('upload_mimes', 'allow_svg_uploads');

if (function_exists('acf_add_options_page')) {

	acf_add_options_page(array(
		'page_title' => 'Theme Settings',
		'menu_title' => 'Theme Settings',
		'menu_slug' => 'futebol365-theme-settings',
	));

	acf_add_options_sub_page(array(
		'page_title' => 'Header Settings',
		'menu_title' => 'Header',
		'parent_slug' => 'futebol365-theme-settings',
	));

	acf_add_options_sub_page(array(
		'page_title' => 'Footer Settings',
		'menu_title' => 'Footer',
		'parent_slug' => 'futebol365-theme-settings',
	));

	acf_add_options_sub_page(array(
		'page_title' => 'Global Settings',
		'menu_title' => 'Global Settings',
		'parent_slug' => 'futebol365-theme-settings',
		'menu_slug' => 'global-settings',
	));
}


function futebol365_get_menus()
{

	$menus = wp_get_nav_menus();
	$choices = [
		'' => '-- Select Menu --',
	];
	if (!empty($menus)) {
		foreach ($menus as $menu) {
			$choices[$menu->term_id] = $menu->name;
		}
	}
	return $choices;
}

register_nav_menus(
	array(
		'footer_menu' => 'Footer Menu',
		'top_second_menu' => 'Top Second Menu',
		'top_menu' => 'Top Menu',
	)
);

function futebol365_register_block_category($categories, $post)
{

	$exists = false;
	foreach ($categories as $category) {
		if ($category['slug'] === 'futebol365') {
			$exists = true;
			break;
		}
	}

	if (!$exists) {
		$categories[] = array(
			'slug' => 'futebol365',
			'title' => __('Futebol365', 'futebol365'),
			'icon' => 'star-filled',
		);
	}

	return $categories;
}
add_filter('block_categories_all', 'futebol365_register_block_category', 10, 2);

/** Top Navigation Walker*/
class Top_Nav_Walker extends Walker_Nav_Menu
{
	public function display_element($element, &$children_elements, $max_depth, $depth, $args, &$output)
	{
		if (!$element) {
			return;
		}

		$id = $element->ID;

		if (0 === $depth && !empty($children_elements[$id])) {
			$is_mega_menu = false;

			foreach ($children_elements[$id] as $child) {
				// If any child itself has children, we found grandchildren! It's a mega menu.
				if (!empty($children_elements[$child->ID])) {
					$is_mega_menu = true;
					break;
				}
			}

			if ($is_mega_menu) {
				$element->classes[] = 'mega-menu-parent';
				$element->is_mega_menu = true; // Custom flag used down below in start_lvl
			}
		}

		// --- FIXED: Safe check for whether $args[0] is an object or array ---
		$current_parent_is_mega = false;
		if (isset($args[0])) {
			if (is_object($args[0]) && isset($args[0]->current_parent_is_mega)) {
				$current_parent_is_mega = $args[0]->current_parent_is_mega;
			} elseif (is_array($args[0]) && isset($args[0]['current_parent_is_mega'])) {
				$current_parent_is_mega = $args[0]['current_parent_is_mega'];
			}
		}

		if ($depth > 0 && $current_parent_is_mega) {
			$element->belongs_to_mega = true;
		}

		// --- FIXED: Safe assignment back to $args[0] ---
		if (isset($args[0])) {
			$set_mega = (isset($element->is_mega_menu) && $element->is_mega_menu);

			if (is_object($args[0])) {
				if ($set_mega) {
					$args[0]->current_parent_is_mega = true;
				} elseif (0 === $depth) {
					$args[0]->current_parent_is_mega = false;
				}
			} elseif (is_array($args[0])) {
				if ($set_mega) {
					$args[0]['current_parent_is_mega'] = true;
				} elseif (0 === $depth) {
					$args[0]['current_parent_is_mega'] = false;
				}
			}
		}

		parent::display_element($element, $children_elements, $max_depth, $depth, $args, $output);
	}

	public function start_lvl(&$output, $depth = 0, $args = null)
	{
		$indent = str_repeat("\t", $depth);
		$classes = array('sub-menu');

		// --- FIXED: Safe check for $args (can be object or array) ---
		$current_parent_is_mega = false;
		if (is_object($args) && isset($args->current_parent_is_mega)) {
			$current_parent_is_mega = $args->current_parent_is_mega;
		} elseif (is_array($args) && isset($args['current_parent_is_mega'])) {
			$current_parent_is_mega = $args['current_parent_is_mega'];
		}

		// If we are opening the first submenu level under a mega-menu parent
		if (0 === $depth && $current_parent_is_mega) {
			$classes[] = 'mega-menu';
			$classes[] = ''; // Adds the 'active' class to the ul as requested
		}

		$class_names = implode(' ', $classes);
		$output .= "\n{$indent}<ul class=\"{$class_names}\">\n";
	}

	public function end_lvl(&$output, $depth = 0, $args = null)
	{
		$indent = str_repeat("\t", $depth);
		$output .= "{$indent}</ul>\n";
	}

	public function start_el(&$output, $item, $depth = 0, $args = null, $id = 0)
	{
		$indent = ($depth) ? str_repeat("\t", $depth) : '';
		$classes = empty($item->classes) ? array() : (array) $item->classes;

		$class_names = implode(' ', array_filter($classes));

		$output .= $indent . '<li';
		if (!empty($class_names)) {
			$output .= ' class="' . esc_attr($class_names) . '"';
		}
		$output .= '>';

		$attributes = '';
		$attributes .= !empty($item->url) ? ' href="' . esc_url($item->url) . '"' : '';
		$attributes .= ' title="' . esc_attr(wp_strip_all_tags($item->title)) . '"';

		// Add 'active' class to the anchor text if it's the main mega menu parent link
		if (0 === $depth && isset($item->is_mega_menu) && $item->is_mega_menu) {
			$attributes .= ' class=""';
		}

		$item_output = '<a' . $attributes . '>';
		$item_output .= wp_kses_post($item->title);
		$item_output .= '</a>';

		$output .= $item_output;
	}

	public function end_el(&$output, $item, $depth = 0, $args = null)
	{
		$output .= "</li>\n";
	}
}

/** Showing image in a menu */
function futebol365_add_menu_flag_icons($items, $args)
{
	foreach ($items as $item) {
		$flag_image_id = get_field('upload_flag', $item);

		if (!empty($flag_image_id)) {
			$flag_image = wp_get_attachment_image(
				$flag_image_id,
				'thumbnail',
				false,
				array(
					'class' => 'menu-flag-image',
					'alt' => 'futebol365',
					'title' => 'futebol365', // Forces WordPress to include the title
				)
			);

			$item->title = $flag_image . '<span>' . $item->title . '</span>';
		}
	}

	return $items;
}
add_filter('wp_nav_menu_objects', 'futebol365_add_menu_flag_icons', 10, 2);

/** Block Registration */
function futebol365_register_blocks()
{
	if (!function_exists('acf_register_block_type'))
		return;

	$blocks = [
		[
			'name' => 'futebol365-category-posts-block',
			'title' => __('Post by Category Block', 'futebol365'),
			'description' => __('A section displaying Posts by selected category.', 'futebol365'),
			'render_template' => get_template_directory() . '/blocks/post-category-block.php',
			'category' => 'futebol365',
			'icon' => 'list-view',
			'keywords' => ['widget', 'popular', 'casino'],
			'mode' => 'edit',
			'supports' => [
				'align' => false,
				'jsx' => false,
			],
		],
		[
			'name' => 'futebol365-main-posts-block',
			'title' => __('Main Posts Block', 'futebol365'),
			'description' => __('A section displaying Posts', 'futebol365'),
			'render_template' => get_template_directory() . '/blocks/main-post-block.php',
			'category' => 'futebol365',
			'icon' => 'list-view',
			'keywords' => ['widget', 'popular', 'casino'],
			'mode' => 'edit',
			'supports' => [
				'align' => false,
				'jsx' => false,
			],
		],
		[
			'name' => 'futebol365-video-posts-block',
			'title' => __('Video Posts Block', 'futebol365'),
			'description' => __('A section displaying Video Posts', 'futebol365'),
			'render_template' => get_template_directory() . '/blocks/video-post-block.php',
			'category' => 'futebol365',
			'icon' => 'list-view',
			'keywords' => ['widget', 'popular', 'casino'],
			'mode' => 'edit',
			'supports' => [
				'align' => false,
				'jsx' => false,
			],
		],
		[
			'name' => 'futebol365-latest-news-block',
			'title' => __('Latest News Block', 'futebol365'),
			'description' => __('A section displaying Latest News', 'futebol365'),
			'render_template' => get_template_directory() . '/blocks/widget-block/latest-news-block.php',
			'category' => 'futebol365',
			'icon' => 'list-view',
			'keywords' => ['widget', 'popular', 'casino'],
			'mode' => 'edit',
			'supports' => [
				'align' => false,
				'jsx' => false,
			],
		],
		[
			'name' => 'futebol365-competicao-posts-block',
			'title' => __('Latest competitions Block', 'futebol365'),
			'description' => __('A section displaying Latest competitions Posts', 'futebol365'),
			'render_template' => get_template_directory() . '/blocks/widget-block/latest-competicao-block.php',
			'category' => 'futebol365',
			'icon' => 'list-view',
			'keywords' => ['widget', 'competitions', 'competicao'],
			'mode' => 'edit',
			'supports' => [
				'align' => false,
				'jsx' => false,
			],
		],
		[
			'name' => 'futebol365-newsstand-posts-block',
			'title' => __('Latest Newsstand Block', 'futebol365'),
			'description' => __('A section displaying Latest Newsstand Posts', 'futebol365'),
			'render_template' => get_template_directory() . '/blocks/newsstand-post-block.php',
			'category' => 'futebol365',
			'icon' => 'list-view',
			'keywords' => ['widget', 'Newsstand', 'competicao'],
			'mode' => 'edit',
			'supports' => [
				'align' => false,
				'jsx' => false,
			],
		],

		[
			'name' => 'futebol365-text-img-info-block',
			'title' => __('Text Img Info Block', 'futebol365'),
			'description' => __('A section displaying Text Img Info', 'futebol365'),
			'render_template' => get_template_directory() . '/blocks/text-img-info-block.php',
			'category' => 'futebol365',
			'icon' => 'admin-links',
			'keywords' => ['Text Image', 'text', 'image', 'info'],
			'mode' => 'edit',
			'supports' => [
				'align' => false,
				'jsx' => false,
			],
		],
		[
			'name' => 'futebol365-special-heading-block',
			'title' => __('Special Heading Block', 'futebol365'),
			'description' => __('A section displaying Special Heading', 'futebol365'),
			'render_template' => get_template_directory() . '/blocks/widget-block/special-heading-block.php',
			'category' => 'futebol365',
			'icon' => 'admin-links',
			'keywords' => ['Special Heading', 'heading', 'color', 'text'],
			'mode' => 'edit',
			'supports' => [
				'align' => false,
				'jsx' => false,
			],
		],
	];

	foreach ($blocks as $block) {
		acf_register_block_type($block);
	}
}
add_action('acf/init', 'futebol365_register_blocks', 10);

/** Register Sidebar */
function futebol365_register_sidebars()
{
	register_sidebar(
		array(
			'name' => __('Post Sidebar', 'futebol365'),
			'id' => 'post-sidebar',
			'description' => __('Post sidebar for the theme', 'futebol365'),
			'before_widget' => '<section id="%1$s" class="widget %2$s">',
			'after_widget' => '</section>',
			'before_title' => '<h2 class="widget-title">',
			'after_title' => '</h2>',
		)
	);
}
add_action('widgets_init', 'futebol365_register_sidebars');

/*related news  in single post content*/

/**
 * Related Posts Pool
 */
function f365_related_pool( $post_id, $pool_size = 100 ) {

	static $pool = [];

	$post_id = (int) $post_id;

	if ( isset( $pool[ $post_id ] ) ) {
		return $pool[ $post_id ];
	}

	$cats = wp_get_post_categories(
		$post_id,
		[
			'fields' => 'ids',
		]
	);

	if ( empty( $cats ) ) {
		return [];
	}

	$primary_cat = (int) $cats[0];

	/*
	|--------------------------------------------------------------------------
	| Cache by category
	|--------------------------------------------------------------------------
	*/
	$cache_key = 'f365_related_cat_' . $primary_cat;

	$ids = wp_cache_get(
		$cache_key,
		'f365'
	);

	if ( false === $ids ) {

		$query = new WP_Query(
			[
				'post_type'              => 'post',
				'post_status'            => 'publish',
				'posts_per_page'         => $pool_size,
				'cat'                    => $primary_cat,
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'ignore_sticky_posts'    => true,
				'no_found_rows'          => true,
				'fields'                 => 'ids',
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			]
		);

		$ids = array_map(
			'intval',
			$query->posts
		);

		wp_cache_set(
			$cache_key,
			$ids,
			'f365',
			12 * HOUR_IN_SECONDS
		);
	}

	return $pool[ $post_id ] = $ids;
}

/**
 * Cached Related Posts
 */
function f365_get_cached_related_posts(
	$post_id,
	$limit = 8
) {
	static $used = [];
	$post_id = (int) $post_id;
	$limit   = max( 1, (int) $limit );

	$out = [];
	foreach ( f365_related_pool( $post_id ) as $id ) {
		if (
			$id === $post_id ||
			isset( $used[ $id ] )
		) {
			continue;
		}
		$used[ $id ] = true;
		$out[]       = $id;

		if ( count( $out ) >= $limit ) {
			break;
		}
	}

	return $out;
}

add_action(
	'save_post_post',
	function ( $post_id ) {
		$cats = wp_get_post_categories( $post_id );
		foreach ( $cats as $cat_id ) {
			wp_cache_delete(
				'f365_related_cat_' . (int) $cat_id,
				'f365'
			);
		}
	}
);
add_filter(
	'the_content',
	'f365_insert_blocks_on_content',
	20
);

function f365_insert_blocks_on_content( $content ) {

	if (
		! is_singular( 'post' ) ||
		! in_the_loop() ||
		! is_main_query()
	) {
		return $content;
	}

	$post_id = get_the_ID();

	if ( ! $post_id ) {
		return $content;
	}

	$related_ids = f365_get_cached_related_posts(
		$post_id,
		4
	);

	if ( empty( $related_ids ) ) {
		return $content;
	}

	ob_start();
	?>

	<div class="related_news_block">
		<b class="large">
			<?php esc_html_e(
				'Relacionadas',
				'futebol365'
			); ?>
		</b>
		<ul>
			<?php foreach ( $related_ids as $related_id ) : ?>
				<li>
					<a href="<?php echo esc_url( get_permalink( $related_id ) ); ?>">
						<?php echo esc_html( get_the_title( $related_id ) ); ?>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>

	</div>
	<?php
	$related_html = ob_get_clean();
	$paragraphs = explode(
		'</p>',
		wpautop( $content )
	);

	if ( count( $paragraphs ) >= 6 ) {

		array_splice(
			$paragraphs,
			4,
			0,
			[ $related_html ]
		);

		return implode(
			'</p>',
			$paragraphs
		);
	}
	return $content . $related_html;
}
/* yoast Breadcrumbs */
/** Image fallback */
function futebol365_fallback_image()
{
	return get_template_directory_uri() . '/assets/images/image-fallback.jpg';
}
/** date format */
function f365_format_date($value)
{
	if (empty($value)) {
		return '';
	}
	$timestamp = strtotime(trim($value));
	return $timestamp
		? wp_date('d-m-Y', $timestamp)
		: $value; // fallback to original string if parsing fails
}
/** Poll varifaction to show form */
function futebol365_has_user_voted_poll($poll_id)
{
	global $wpdb;
	$table_name = $wpdb->prefix . 'ayspoll_reports';

	$user_ip = class_exists('Poll_Maker_Ays_Public') && method_exists('Poll_Maker_Ays_Public', 'get_user_ip_validated')
		? Poll_Maker_Ays_Public::get_user_ip_validated()
		: (function_exists('futebol365_get_user_ip') ? futebol365_get_user_ip() : $_SERVER['REMOTE_ADDR']);

	$already_voted = $wpdb->get_var(
		$wpdb->prepare(
			"
SELECT 1
FROM {$table_name}
WHERE user_ip = %s
AND poll_id = %d
LIMIT 1
",
			$user_ip,
			$poll_id
		)
	);
	return !empty($already_voted);
}


if (!function_exists('f365_render_timezone_clock')) {
	function f365_render_timezone_clock()
	{
		$timezone = wp_timezone();
		$timezone_name = wp_timezone_string() ?: $timezone->getName();
		$location = $timezone->getLocation();
		$country_code = is_array($location) && !empty($location['country_code']) ? $location['country_code'] : '';
		$country_name = $country_code;

		if ($country_code && function_exists('locale_get_display_region')) {
			$display_region = locale_get_display_region('-' . $country_code, 'en');
			if (!empty($display_region)) {
				$country_name = $display_region;
			}
		}

		$timezone_parts = explode('/', str_replace('_', ' ', $timezone_name));
		$city_name = end($timezone_parts) ?: $timezone_name;

		if ('Lisbon' === $city_name) {
			$city_name = 'Lisboa';
		}

		$current_time = current_time('H:i:s');
		?>
				<span class="f365-timezone-clock" data-timezone="<?php echo esc_attr($timezone->getName()); ?>">
					<span class="f365-timezone-time"><?php echo esc_html($current_time); ?></span>
					<span class="f365-timezone-location"><a class="f365-timezone-city"
							href="<?php echo esc_url(home_url('/fusos-horarios/')); ?>"><?php echo esc_html($city_name); ?><span
							class="f365-timezone-country">, <?php echo esc_html($country_name); ?></span></a></span>
				</span>
				<script>
					(function () {
						var script = document.currentScript;
						var clock = script ? script.previousElementSibling : null;
						if (!clock || !window.Intl) {
							return;
						}
						var timeNode = clock.querySelector('.f365-timezone-time');
						var timezone = clock.getAttribute('data-timezone');
						var formatter;
						try {
							formatter = new Intl.DateTimeFormat('pt-PT', {
								hour: '2-digit',
								minute: '2-digit',
								second: '2-digit',
								hour12: false,
								timeZone: timezone
							});
						} catch (error) {
							return;
						}
						function updateClock() {
							timeNode.textContent = formatter.format(new Date());
						}
						updateClock();
						setInterval(updateClock, 1000);
					}());
				</script>
				<?php
	}
}
function futebol365_get_user_ip()
{
	if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
		return sanitize_text_field(
			$_SERVER['HTTP_CF_CONNECTING_IP']
		);
	}
	if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
		$ips = explode(
			',',
			$_SERVER['HTTP_X_FORWARDED_FOR']
		);
		return sanitize_text_field(
			trim($ips[0])
		);
	}
	return sanitize_text_field(
		$_SERVER['REMOTE_ADDR']
	);
}
/*Get multimedia views*/
function futebol365_get_post_views($post_id = 0)
{
	$post_id = $post_id
		? $post_id
		: get_the_ID();
	return (int) get_post_meta(
		$post_id,
		'views',
		true
	);
}

/* Increment multimedia views */
function futebol365_increment_post_views($post_id = 0)
{
	$post_id = $post_id
		? $post_id
		: get_the_ID();
	$cookie_key = 'f365_viewed_' . $post_id;
	if (isset($_COOKIE[$cookie_key])) {
		return;
	}
	$views = (int) get_post_meta(
		$post_id,
		'views',
		true
	);
	$views++;
	update_post_meta(
		$post_id,
		'views',
		$views
	);
	setcookie(
		$cookie_key,
		1,
		time() + (12 * HOUR_IN_SECONDS),
		COOKIEPATH,
		COOKIE_DOMAIN
	);
}
add_action('wp', function () {
	if (
		is_singular([
			'f365_video',
			'f365_gallery',
		])
	) {
		futebol365_increment_post_views(
			get_the_ID()
		);
	}
});

/** Filter search results by post type */
function futebol365_search_mod_filter($query)
{
	if (is_admin() || !$query->is_main_query()) {
		return;
	}
	if (!is_search()) {
		return;
	}
	if (empty($_GET['mod'])) {
		return;
	}
	$mod = sanitize_key($_GET['mod']);
	if (!post_type_exists($mod) || !is_post_type_viewable($mod)) {
		return;
	}
	$query->set('post_type', $mod);
	$query->set(
		'posts_per_page',
		get_option('posts_per_page')
	);
}
add_action('pre_get_posts', 'futebol365_search_mod_filter');
/**Register 'mod' as a valid WordPress query variable so it doesn't get dropped*/
function futebol365_register_search_query_vars($vars)
{
	$vars[] = 'mod';
	return $vars;
}
add_filter('query_vars', 'futebol365_register_search_query_vars');
/** Map /pesquisa/ to the internal WordPress search engine, passing through the 'mod' var if it exists*/
function custom_search_rewrite_rules()
{
	add_rewrite_rule(
		'^pesquisa/?$',
		'index.php?s=&mod=$matches[1]',
		'top'
	);
}
add_action('init', 'custom_search_rewrite_rules');
add_filter(
	'redirect_canonical',
	function ( $redirect_url ) {
		global $wp_query;

		if ( isset( $wp_query ) && $wp_query->is_404() ) {
			return false;
		}

		return $redirect_url;
	}
);

/**Redirect standard search URLs while preserving ALL extra query parameters (like &mod=)*/
function custom_search_redirect()
{
	if (is_search() && !empty($_GET['s']) && strpos($_SERVER['REQUEST_URI'], '/pesquisa/') === false) {
		$query_args = $_GET;
		wp_safe_redirect(add_query_arg($query_args, home_url('/pesquisa/')));
		exit();
	}
}
add_action('template_redirect', 'custom_search_redirect');
add_filter(
	'redirect_canonical',
	function ( $redirect_url ) {

		if ( is_404() ) {
			return false;
		}

		return $redirect_url;
	}
);

/** Federation sections config */
function futebol365_get_federation_sections(): array
{
	return [
		'competicoes' => [
			'label' => 'Competições',
			'template' => 'competitions',
		],
		'equipas' => [
			'label' => 'Equipas',
			'template' => 'teams',
		],
		'jogadores' => [
			'label' => 'Jogadores',
			'template' => 'players',
		],
		'treinadores' => [
			'label' => 'Treinadores',
			'template' => 'coaches',
		],
		'dirigentes' => [
			'label' => 'Dirigentes',
			'template' => 'presidents',
		],
		'estadios' => [
			'label' => 'Estádios',
			'template' => 'stadiums',
		],
		'details' => [
			'label' => 'Dados Gerais',
			'template' => 'details',
		],
	];
}
/** Code for poll votes shortcode */
add_shortcode('poll_total_votes', function ($atts) {
	global $wpdb;
	$atts = shortcode_atts(
		array(
			'id' => 0,
		),
		$atts
	);
	$poll_id = absint($atts['id']);
	if (!$poll_id) {
		return '';
	}
	$table = $wpdb->prefix . 'ayspoll_answers';
	$total = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT SUM(votes)
FROM {$table}
WHERE poll_id = %d",
			$poll_id
		)
	);
	return esc_html((int) $total);
});
/** Breadcrumbs */
function futebol365_get_federation_partial_url($post_id, $partial = '')
{
	$url = get_permalink($post_id);
	if ($partial) {
		$url = trailingslashit($url) . $partial;
	}
	return $url;
}
function futebol365_get_parent_breadcrumbs($post_id, $partial = '')
{
	$breadcrumbs = '';
	// Use post parent relationship; replace with a custom parent field if needed
	$parent_id = wp_get_post_parent_id($post_id);
	if ($parent_id) {
		$breadcrumbs .= futebol365_get_parent_breadcrumbs($parent_id, $partial);
	}
	$post = get_post($post_id);
	if ($post) {
		$breadcrumbs .= sprintf(
			'<span><a href="%s">%s</a></span>',
			esc_url(futebol365_get_federation_partial_url($post_id, $partial)),
			esc_html($post->post_title)
		);
	}
	return $breadcrumbs;
}

add_filter(
	'request',
	function ($query_vars) {
		$path = trim(
			parse_url(
				$_SERVER['REQUEST_URI'],
				PHP_URL_PATH
			),
			'/'
		);
		// Video archive.
		if (
			'futebol365/multimedia/videos' === $path ||
			'multimedia/videos' === $path
		) {
			return [
				'post_type' => 'f365_video',
			];
		}
		// Gallery archive.
		if (
			'futebol365/multimedia/fotogalerias' === $path ||
			'multimedia/fotogalerias' === $path
		) {
			return [
				'post_type' => 'f365_gallery',
			];
		}
		// Video single (slug = 90826).
		if (
			preg_match(
				'#(?:futebol365/)?multimedia/videos/([0-9]+)$#',
				$path,
				$matches
			)
		) {
			return [
				'post_type' => 'f365_video',
				'name' => $matches[1],
			];
		}
		// Gallery single.
		if (
			preg_match(
				'#(?:futebol365/)?multimedia/fotogalerias/([0-9]+)$#',
				$path,
				$matches
			)
		) {
			return [
				'post_type' => 'f365_gallery',
				'name' => $matches[1],
			];
		}
		return $query_vars;
	},
	99999
);

/**
 * Get stadium country
 */
function f365_get_stadium_country(
	$stadium_id
) {
	$cache_key =
		'stadium_country_' .
		(int) $stadium_id;
	$country = wp_cache_get(
		$cache_key,
		'f365'
	);
	if (false !== $country) {
		return $country;
	}
	$terms = get_the_terms(
		$stadium_id,
		'f365_country'
	);
	$country =
		empty($terms) ||
		is_wp_error($terms)
		? false
		: reset($terms);
	wp_cache_set(
		$cache_key,
		$country,
		'f365',
		HOUR_IN_SECONDS
	);
	return $country;
}
/**
 * Get stadium country flag
 */
function f365_get_stadium_country_flag(
	$stadium_id
) {
	$cache_key =
		'stadium_flag_' .
		(int) $stadium_id;
	$flag_url = wp_cache_get(
		$cache_key,
		'f365'
	);
	if (false !== $flag_url) {
		return $flag_url;
	}
	$country = f365_get_stadium_country(
		$stadium_id
	);
	if (!$country) {
		return false;
	}
	$flag = get_field(
		'country_flag',
		'f365_country_' .
		$country->term_id
	);
	$flag_url = $flag
		? wp_get_attachment_image_url(
			$flag,
			'thumbnail'
		)
		: false;
	wp_cache_set(
		$cache_key,
		$flag_url,
		'f365',
		HOUR_IN_SECONDS
	);
	return $flag_url;
}
/**
 * Retrieves and formats associated teams for a given stadium ID,
 * sorted properly by club name and step age order (e.g. S15 -> S13 -> S12 ... -> S6).
 *
 * @param int $stadium_id The stadium ID from the post meta.
 * @return array List of arrays containing the formatted name and step parameters.
 */
function f365_get_stadium_teams1($stadium_id)
{
		global $wpdb;
		if (!$stadium_id) {
			return array();
		}
		// Dynamic prefixing for database tables
		$teamstep_table = $wpdb->prefix . 'team_teamstep';
		$team_table = $wpdb->prefix . 'team_team';
		// Query with custom step ordering weight
		$query = $wpdb->prepare(
			"SELECT
	ts.id AS teamstep_id,
	ts.team_id,
	t.shortname AS team_name,
	ts.step,
	ts.modality,
	ts.gender,
	ts.edition
	FROM
	{$teamstep_table} ts
	JOIN
	{$team_table} t ON ts.team_id = t.id
	JOIN (
	-- Subquery to find the latest edition for each unique team/step/modality/gender combination
	SELECT
	sub_ts.team_id,
	sub_ts.step,
	sub_ts.modality,
	sub_ts.gender,
	MAX(sub_ts.edition) AS max_edition
	FROM
	{$teamstep_table} sub_ts
	JOIN
	{$team_table} sub_t ON sub_ts.team_id = sub_t.id
	WHERE
	sub_ts.stadium_id = %d
	OR (sub_ts.stadium_id IS NULL AND sub_t.stadium_id = %d)
	GROUP BY
	sub_ts.team_id, sub_ts.step, sub_ts.modality, sub_ts.gender
	) latest ON ts.team_id = latest.team_id
	AND ts.step = latest.step
	AND ts.modality = latest.modality
	AND ts.gender = latest.gender
	AND ts.edition = latest.max_edition
	WHERE
	ts.stadium_id = %d
	OR (ts.stadium_id IS NULL AND t.stadium_id = %d)
	ORDER BY
	team_name ASC,
	-- Custom sorting to force age order (S23 -> S15 -> S13 ... -> S6)
	CASE ts.step
	WHEN 'principal'    THEN 1
	WHEN 'b_team'       THEN 2
	WHEN 'c_team'       THEN 3
	WHEN 'sub_23'       THEN 4
	WHEN 'sub_22'       THEN 5
	WHEN 'sub_21'       THEN 6
	WHEN 'sub_20'       THEN 7
	WHEN 'sub_19'       THEN 8
	WHEN 'sub_19_b'     THEN 9
	WHEN 'sub_18'       THEN 10
	WHEN 'sub_17'       THEN 11
	WHEN 'sub_17_b'     THEN 12
	WHEN 'sub_16'       THEN 13
	WHEN 'sub_15'       THEN 14
	WHEN 'sub_15_b'     THEN 15
	WHEN 'sub_14'       THEN 16
	WHEN 'sub_13'       THEN 17
	WHEN 'sub_13_b'     THEN 18
	WHEN 'sub_12'       THEN 19
	WHEN 'sub_12_b'     THEN 20
	WHEN 'sub_11'       THEN 21
	WHEN 'sub_11_b'     THEN 22
	WHEN 'sub_10'       THEN 23
	WHEN 'sub_10_b'     THEN 24
	WHEN 'sub_9'        THEN 25
	WHEN 'sub_8'        THEN 26
	WHEN 'sub_7'        THEN 27
	WHEN 'sub_6'        THEN 28
	WHEN 'veterans'     THEN 29
	WHEN 'reservations' THEN 30
	ELSE 99
	END ASC",
			$stadium_id,
			$stadium_id,
			$stadium_id,
			$stadium_id
		);
		$results = $wpdb->get_results($query);
		if (empty($results)) {
			return array();
		}
		$formatted_teams = array();
		// Step/Category display mapping matching the original Django choices.py
		$step_mapping = array(
			'sub_23' => 'S23',
			'sub_22' => 'S22',
			'sub_21' => 'S21',
			'sub_20' => 'S20',
			'sub_19' => 'S19',
			'sub_19_b' => 'S19 B',
			'sub_18' => 'S18',
			'sub_17' => 'S17',
			'sub_17_b' => 'S17 B',
			'sub_16' => 'S16',
			'sub_15' => 'S15',
			'sub_15_b' => 'S15 B',
			'sub_14' => 'S14',
			'sub_13' => 'S13',
			'sub_13_b' => 'S13 B',
			'sub_12' => 'S12',
			'sub_12_b' => 'S12 B',
			'sub_11' => 'S11',
			'sub_11_b' => 'S11 B',
			'sub_10' => 'S10',
			'sub_10_b' => 'S10 B',
			'sub_9' => 'S9',
			'sub_8' => 'S8',
			'sub_7' => 'S7',
			'sub_6' => 'S6',
		);
		foreach ($results as $row) {
			$suffixes = array();
			// 1. Modality suffix
			if ('futsal' === $row->modality) {
				$suffixes[] = 'FS';
			}
			// 2. Gender suffix
			if ('female' === $row->gender) {
				$suffixes[] = 'FM';
			}
			// 3. Step suffix
			if (!in_array($row->step, array('principal', 'b_team', 'c_team', 'veterans', 'reservations'), true)) {
				if (isset($step_mapping[$row->step])) {
					$suffixes[] = $step_mapping[$row->step];
				} else {
					$suffixes[] = ucfirst(str_replace('_', ' ', $row->step));
				}
			} elseif ('b_team' === $row->step) {
				$suffixes[] = 'B';
			} elseif ('c_team' === $row->step) {
				$suffixes[] = 'C';
			} elseif ('veterans' === $row->step) {
				$suffixes[] = 'VET';
			} elseif ('reservations' === $row->step) {
				$suffixes[] = 'RES';
			}
			$suffix_str = !empty($suffixes) ? ' ' . implode(' ', $suffixes) : '';
			$formatted_name = trim($row->team_name . $suffix_str);
			// Build URL structure matching your routes, e.g.:
	// /equipa/{team_id}?edition={edition}&modality={modality}&gender={gender}&step={step}
			$url = home_url(sprintf(
				'/equipa/%d/?edition=%s&modality=%s&gender=%s&step=%s',
				$row->team_id,
				urlencode($row->edition),
				urlencode($row->modality),
				urlencode($row->gender),
				urlencode($row->step)
			));
			$formatted_teams[] = array(
				'team_id' => $row->team_id,
				'formatted_name' => $formatted_name,
				'url' => $url,
				'edition' => $row->edition,
				'step' => $row->step,
			);
		}
		return $formatted_teams;
}
/**
 * Retrieves the total count of matches for a given stadium ID (Zero Joins).
 */
function f365_get_stadium_matches_count1($stadium_id, $type = 'last')
{
		global $wpdb;
		if (!$stadium_id) {
			return 0;
		}
		$match_table = $wpdb->prefix . 'competition_match';
		$situation_clause = ('last' === $type) ? "m.situation = 'finished'" : "(m.situation IS NULL OR m.situation != 'finished')";
		// Simple COUNT query on a single table
		$query = $wpdb->prepare(
			"SELECT COUNT(m.id)
	FROM {$match_table} m
	WHERE
	m.stadium_id = %d
	AND ({$situation_clause})",
			$stadium_id
		);
		return (int) $wpdb->get_var($query);
}
/**
 *  Bypasses postmeta lookup for instant performance test.
 */
function f365_get_stadium_matches1($stadium_id, $type = 'last', $limit = 15, $offset = 0)
{
		global $wpdb;
		if (!$stadium_id) {
			return array();
		}
		$match_table = $wpdb->prefix . 'competition_match';
		$comp_table = $wpdb->prefix . 'competition_competition';
		$teamstep_table = $wpdb->prefix . 'team_teamstep';
		$team_table = $wpdb->prefix . 'team_team';
		$situation_clause = ('last' === $type) ? "m.situation = 'finished'" : "(m.situation IS NULL OR m.situation != 'finished')";
		$order_direction = ('last' === $type) ? "DESC" : "ASC";
		$query = $wpdb->prepare(
			"SELECT
			m.id AS match_id,
			m.date_time,
			m.match_round,
			m.home_score,
			m.visitor_score,
			m.home_score_extra,
			m.visitor_score_extra,
			m.penalty_home_score,
			m.penalty_visitor_score,
			m.situation,
			m.leg,
			m.group_home_team,
			m.group_phase_round,
			-- Pre-computed properties from DB
			m.home_team_id,
			m.home_team_display_name AS home_team_name,
			m.visitor_team_id,
			m.visitor_team_display_name AS visitor_team_name,
			m.competition_display_name AS competition_name,
			m.competition_django_id AS federation_competition_id,
			-- Get edition from competition table
			c.edition AS competition_edition,
			-- home_team_id/visitor_team_id above are TEAMSTEP ids, not team
			-- pub ids (same convention as transfer_transfer/merge_team()
			-- elsewhere in this codebase) -- resolve the actual team pub
			-- here so /equipa/{id}/ links are built from the right id.
			hts.team_id AS home_team_pub,
			vts.team_id AS visitor_team_pub
			FROM
			{$match_table} m
			JOIN
			{$comp_table} c ON m.competition_id = c.id
			LEFT JOIN
			{$teamstep_table} hts ON hts.id = m.home_team_id
			LEFT JOIN
			{$teamstep_table} vts ON vts.id = m.visitor_team_id
			WHERE
			m.stadium_id = %d
			AND ({$situation_clause})
			ORDER BY
			m.date_time {$order_direction}
			LIMIT %d OFFSET %d",
			$stadium_id,
			$limit,
			$offset
		);
		$results = $wpdb->get_results($query);
		if (empty($results)) {
			return array();
		}
		// BYPASS POSTMETA LOOKUP FOR PERFORMANCE TEST
		$wp_team_permalinks = array();
		$formatted_matches = array();
		foreach ($results as $row) {
			$home_winner = false;
			$visitor_winner = false;
			$score_display = 'vs';
			if ('finished' === $row->situation) {
				$home_score = $row->home_score;
				$visitor_score = $row->visitor_score;
				if (null !== $row->home_score_extra && null !== $row->visitor_score_extra) {
					$home_score = $row->home_score_extra;
					$visitor_score = $row->visitor_score_extra;
					$score_display = "{$home_score} - {$visitor_score} a.p.";
				} else {
					$score_display = "{$home_score} - {$visitor_score}";
				}
				if (null !== $row->penalty_home_score && null !== $row->penalty_visitor_score) {
					$score_display = "{$row->penalty_home_score} - {$row->penalty_visitor_score} g.p.";
					$home_winner = ($row->penalty_home_score > $row->penalty_visitor_score);
					$visitor_winner = ($row->penalty_visitor_score > $row->penalty_home_score);
				} else {
					$home_winner = ($home_score > $visitor_score);
					$visitor_winner = ($visitor_score > $home_score);
				}
			}
			// Direct URL fallback (fast, no DB query) -- must use the resolved
			// team pub (home_team_pub/visitor_team_pub), NOT the raw
			// home_team_id/visitor_team_id columns, which are teamstep ids;
			// using them directly built a /equipa/{teamstep_id}/ URL that
			// 404'd for every match (bug fixed 2026-08-06).
			$home_url = home_url('/equipa/' . $row->home_team_pub);
			$visitor_url = home_url('/equipa/' . $row->visitor_team_pub);
			// Build Competition URL matching old logic
			$query_args = array();
			if ($row->competition_edition) {
				$query_args['edition'] = $row->competition_edition;
			}
			if ($row->match_round) {
				$query_args['competition_round'] = $row->match_round;
			}
			if ($row->group_home_team) {
				$query_args['group'] = $row->group_home_team;
			}
			$competition_url = add_query_arg($query_args, home_url(sprintf('/competicao/%s/', $row->federation_competition_id)));
			// Compare URL matching old logic
			$compare_url = add_query_arg(
				array(
					'edition' => $row->competition_edition,
					'step1' => '',
					'step2' => '',
				),
				home_url(sprintf('/equipa/%s/comparar-com/%s/', $row->home_team_pub, $row->visitor_team_pub))
			);
			$match_url = home_url(sprintf('/jogo/%s/', $row->match_id));
			$formatted_matches[] = array(
				'match_id' => $row->match_id,
				'datetime' => $row->date_time,
				'date' => date_i18n('d-m-y', strtotime($row->date_time)),
				'time' => date_i18n('H:i', strtotime($row->date_time)),
				'situation' => $row->situation,
				'competition_url' => $competition_url,
				'home_team_id' => $row->home_team_id,
				'home_team_name' => $row->home_team_name,
				'home_team_url' => $home_url,
				'home_winner' => $home_winner,
				'visitor_team_id' => $row->visitor_team_id,
				'visitor_team_name' => $row->visitor_team_name,
				'visitor_team_url' => $visitor_url,
				'visitor_winner' => $visitor_winner,
				'score' => $score_display,
				'compare_url' => $compare_url,
				'match_url' => $match_url,
			);
		}
		return $formatted_matches;
}

/** Federation sections config */
function futebol365_get_player_sections(): array
{
	return [
		'' => [
			'label' => 'Resumo',
			'template' => '',
		],
		'jogos' => [
			'label' => 'Jogos',
			'template' => 'jogos',
		],
		'palmares' => [
			'label' => 'Palmarés',
			'template' => 'palmares',
		],
		'premios' => [
			'label' => 'Prémios',
			'template' => 'premios',
		],
		'transferencias' => [
			'label' => 'Transferências',
			'template' => 'transferencias',
		],
		'noticias' => [
			'label' => 'Notícias',
			'template' => 'noticias',
		],
		'videos' => [
			'label' => 'Vídeos',
			'template' => 'videos',
		],
		'dados-pessoais' => [
			'label' => 'Dados Pessoais',
			'template' => 'dados-pessoais',
		],
	];
}
/** Poll template loader*/
// }
add_filter('query_vars', 'f365_poll_query_vars');
function f365_poll_query_vars($vars)
{
	$vars[] = 'f365_poll_id';
	return $vars;
}

function f365_get_stadium_breadcrumbs($post_id)
{
	$countries = get_the_terms(
		$post_id,
		'f365_country'
	);
	if (
		empty($countries) ||
		is_wp_error(
			$countries
		)
	) {
		return '';
	}
	$country = reset(
		$countries
	);
	$federation_query = get_posts(
		[
			'post_type' => 'f365_federation',
			'posts_per_page' => 1,
			'post_status' => 'publish',
			'tax_query' => [
				[
					'taxonomy' => 'f365_country',
					'field' => 'term_id',
					'terms' => $country->term_id,
				],
			],
			'fields' => 'ids',
		]
	);
	if (empty($federation_query)) {
		return '';
	}
	$federation_id = $federation_query[0];
	$breadcrumbs = futebol365_get_parent_breadcrumbs(
		$federation_id
	);
	$breadcrumbs = str_replace(
		[
			'<span>',
			'</span>',
		],
		[
			'
<li>',
			'
</li>
',
		],
		$breadcrumbs
	);
	return $breadcrumbs;
}



add_filter(
	'template_include',
	function ($template) {
		$stadium_id = get_query_var(
			'f365_stadium_id'
		);
		if (
			$stadium_id ||
			is_singular(
				'f365_stadium'
			)
		) {
			$custom_template =
				get_template_directory() .
				'/single-f365_stadium.php';
			if (
				file_exists(
					$custom_template
				)
			) {
				return $custom_template;
			}
		}
		return $template;
	},
	999999
);
function futebol365_get_team_sections(): array
{
	return [
		'jogos' => [
			'label' => 'Jogos',
			'template' => 'jogos',
		],
		'calendario' => [
			'label' => 'Calendário',
			'template' => 'calendario',
		],
		'plantel' => [
			'label' => 'Plantel',
			'template' => 'plantel',
		],
		'transferencias' => [
			'label' => 'Transferências',
			'template' => 'transferencias',
		],
		'palmares' => [
			'label' => 'Palmarés',
			'template' => 'palmares',
		],
		'noticias' => [
			'label' => 'Notícias',
			'template' => 'noticias',
		],
		'videos' => [
			'label' => 'Vídeos',
			'template' => 'videos',
		],
		'historico' => [
			'label' => 'Histórico',
			'template' => 'historico',
		],
		'info' => [
			'label' => 'Dados Gerais',
			'template' => 'info',
		],
	];
}
function futebol365_get_team_compare_sections(): array
{
	return [
		'resumo' => [
			'label' => 'Resumo',
			'template' => 'resumo',
			'slug' => 'resumo',
		],
		'confrontos' => [
			'label' => 'Confrontos',
			'template' => 'confrontos',
			'slug' => 'confrontos',
		],
		'forma' => [
			'label' => 'Forma',
			'template' => 'forma',
			'slug' => 'forma',
		],
		'palmares' => [
			'label' => 'Palmarés',
			'template' => 'palmares',
			'slug' => 'palmares',
		],
		'estadios' => [
			'label' => 'Estádios',
			'template' => 'estadios',
			'slug' => 'estadios',
		],
	];
}
/** Federation sections config */
function futebol365_get_live_game_sections(): array
{
	return [
		'' => [
			'label' => 'Todos',
			'status' => '',
		],
		'direto' => [
			'label' => 'Direto',
			'status' => 1,
		],
		'proximos' => [
			'label' => 'Próximos',
			'status' => 2,
		],
		'terminados' => [
			'label' => 'Terminados',
			'status' => 3,
		],
		'meus-jogos' => [
			'label' => 'Meus Jogos (0)',
			'status' => 4,
		],
	];
}
if (file_exists(__DIR__ . '/ajax-live-matches.php')) {
	require_once __DIR__ . '/ajax-live-matches.php';
} elseif (file_exists(__DIR__ . '/inc/ajax-live-matches.php')) {
	require_once get_template_directory() . '/inc/ajax-live-matches.php';
}
function futebol365_plantel_positions(): array
{
	return [
		'goalkeeper' => 'Guarda-redes',
		'defender' => 'Defesas',
		'midfielder' => 'Médios',
		'forward' => 'Avançados',
	];
}
function futebol365_plantel_steps(): array
{
	return [
		'principal' => 'Principal',
		'b_team' => 'Equipa B',
		'c_team' => 'Equipa C',
		'sub_23' => 'Sub-23',
		'sub_21' => 'Sub-21',
		'sub_20' => 'Sub-20',
		'sub_19' => 'Sub-19',
		'sub_18' => 'Sub-18',
		'sub_17' => 'Sub-17',
		'sub_16' => 'Sub-16',
		'sub_15' => 'Sub-15',
		'veterans' => 'Veteranos',
	];
}
function futebol365_get_plantel_filter_state(int $team_id): array
{
	$edition = isset($_GET['edition']) ? sanitize_text_field(wp_unslash($_GET['edition'])) : '';
	if ('' === $edition) {
		$edition = (string) (get_field('edition', $team_id) ?: gmdate('Y'));
	}
	return [
		'edition' => $edition,
		'step' => isset($_GET['step']) ? sanitize_text_field(wp_unslash($_GET['step'])) : 'principal',
		'modality' => isset($_GET['modality']) ? sanitize_text_field(wp_unslash($_GET['modality'])) : 'football',
		'gender' => isset($_GET['gender']) ? sanitize_text_field(wp_unslash($_GET['gender'])) : 'male',
	];
}



require_once get_stylesheet_directory() . '/inc/futebol365-jogos-filter-helpers.php';
add_filter('wpseo_breadcrumb_links', function ($links) {
	if (is_archive() && strpos($_SERVER['REQUEST_URI'], '/live-streaming/') !== false) {
		return array(
			array(
				'url' => home_url('/'),
				'text' => 'Home',
			),
			array(
				'url' => home_url('/live-streaming/'),
				'text' => 'Live Streaming',
			),
		);
	}
	return $links;
}, 99);


function futebol365_get_news_relations( $post_id = 0 ) {

	$post_id = $post_id ?: get_the_ID();

	if ( ! $post_id ) {
		return [];
	}

	$cache_key = 'f365_news_relations_' . $post_id;

	$cached = wp_cache_get(
		$cache_key,
		'f365'
	);

	if ( false !== $cached ) {
		return $cached;
	}

	global $wpdb;

	$post = get_post($post_id);	
	$legacy_id = (int) ($post->legacy_id ?? 0);

	$relations = [
		'teams'      => [],
		'players'    => [],
		'coaches'    => [],
		'presidents' => [],
		'videos'     => [],
	];

	if ( ! $legacy_id ) {

		wp_cache_set(
			$cache_key,
			$relations,
			'f365',
			HOUR_IN_SECONDS
		);

		return $relations;
	}

	$results = $wpdb->get_results(
		$wpdb->prepare(
			"
			SELECT p.ID, 'teams' AS rel_type
			FROM {$wpdb->prefix}publish_news_teams nt
			INNER JOIN {$wpdb->posts} p
			ON p.legacy_id = nt.team_id
			AND p.legacy_table = 'wp_team_team'
			WHERE nt.news_id = %d
				AND p.post_type = 'f365_team'
				AND p.post_status = 'publish'

			UNION ALL

			SELECT p.ID, 'players' AS rel_type
			FROM {$wpdb->prefix}publish_news_players np
			INNER JOIN {$wpdb->posts} p
			ON p.legacy_id = np.player_id
			AND p.legacy_table = 'person_player'
			WHERE np.news_id = %d
				AND p.post_type = 'f365_player'
				AND p.post_status = 'publish'

			UNION ALL

			SELECT p.ID, 'coaches' AS rel_type
			FROM {$wpdb->prefix}publish_news_coaches nc
			INNER JOIN {$wpdb->posts} p
			ON p.legacy_id = nc.coach_id
			AND p.legacy_table = 'wp_person_coach'
			WHERE nc.news_id = %d
				AND p.post_type = 'f365_coach'
				AND p.post_status = 'publish'

			UNION ALL

			SELECT p.ID, 'presidents' AS rel_type
			FROM {$wpdb->prefix}publish_news_presidents npr
			INNER JOIN {$wpdb->posts} p
				ON p.legacy_id = npr.president_id
				AND p.legacy_table = 'person_president'
			WHERE npr.news_id = %d
				AND p.post_type = 'f365_president'
				AND p.post_status = 'publish'

			UNION ALL

			SELECT p.ID, 'videos' AS rel_type
			FROM {$wpdb->prefix}publish_news_videos nv
			INNER JOIN {$wpdb->posts} p
			ON p.legacy_id = nv.video_id
			AND p.legacy_table = 'multimedia_video'
			WHERE nv.news_id = %d
				AND p.post_type = 'f365_video'
				AND p.post_status = 'publish'
			",
			$legacy_id,
			$legacy_id,
			$legacy_id,
			$legacy_id,
			$legacy_id
		)
	);

	if ( ! empty( $results ) ) {

		foreach ( $results as $row ) {
			$relations[ $row->rel_type ][] = (int) $row->ID;
		}

		foreach ( $relations as $type => $ids ) {
			$relations[ $type ] = array_values(
				array_unique( $ids )
			);
		}
	}

	wp_cache_set(
		$cache_key,
		$relations,
		'f365',
		HOUR_IN_SECONDS
	);

	return $relations;
}

/** Master function to clear all custom transients when admin content is saved/updated */

// 1) Stop WordPress from redirecting /sondagem/lista -> /sondagem/lista/
add_filter('redirect_canonical', function ($redirect_url, $requested_url) {
	if (preg_match('#/sondagem/lista/?$#', (string) $requested_url)) {
		return false; // do not redirect; serve as requested
	}
	return $redirect_url;
}, 10, 2);
// 2) Make the rewrite match WITH or WITHOUT the trailing slash
add_action('init', function () {
	add_rewrite_rule(
		'^sondagem/lista/?$',
		'index.php?post_type=sondagem&sondagem_view=lista',
		'top'
	);
});

function flush_custom_admin_caches($target_id = null)
{

	delete_transient('cached_top_nav_menu');
	delete_transient('cached_top_second_menu');
	delete_transient('footer_menu');

	// Header/footer HTML transients (header.php + footer.php).
	delete_transient('f365_header_shell');
	delete_transient('f365_header_sprite');
	delete_transient('footer_html_v1');

	if ('options' === $target_id) {
		delete_transient('cached_header_social_links');
	}
}
function f365_flush_caches_on_widget_option($option)
{
	if ('sidebars_widgets' === $option || 0 === strpos((string) $option, 'widget_')) {
		flush_custom_admin_caches();
	}
}
add_action('updated_option', 'f365_flush_caches_on_widget_option', 10, 1);
add_action('added_option', 'f365_flush_caches_on_widget_option', 10, 1);
add_action('rest_after_save_widget', 'flush_custom_admin_caches');
add_action('update_option_sidebars_widgets', 'flush_custom_admin_caches');
add_action('customize_save_after', 'flush_custom_admin_caches');
add_action('acf/save_post', 'flush_custom_admin_caches', 20);
add_action('after_switch_theme', 'flush_custom_admin_caches');
add_action('wp_update_nav_menu', 'flush_custom_admin_caches');
add_action('wp_delete_nav_menu', 'flush_custom_admin_caches');
add_action('save_post', 'flush_custom_admin_caches');

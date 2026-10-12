<?php

/**
 * Futebol365 functions and definitions
 *
 * @link https://developer.wordpress.org/themes/basics/theme-functions/
 *
 * @package Futebol365
 */

if (! defined('_S_VERSION')) {
	// Replace the version number of the theme on each release.
	define('_S_VERSION', '1.0.0');
}

define( 'FB_THEME_URL', get_template_directory_uri() );
define( 'FB_THEME_PATH', get_template_directory() );

/**
 * Sets up theme defaults and registers support for various WordPress features.
 *
 * Note that this function is hooked into the after_setup_theme hook, which
 * runs before the init hook. The init hook is too late for some features, such
 * as indicating support for post thumbnails.
 */
function futebol365_setup()
{
	/*
		* Make theme available for translation.
		* Translations can be filed in the /languages/ directory.
		* If you're building a theme based on Futebol365, use a find and replace
		* to change 'futebol365' to the name of your theme in all the template files.
		*/
	load_theme_textdomain('futebol365', get_template_directory() . '/languages');

	// Add default posts and comments RSS feed links to head.
	add_theme_support('automatic-feed-links');

	/*
		* Let WordPress manage the document title.
		* By adding theme support, we declare that this theme does not use a
		* hard-coded <title> tag in the document head, and expect WordPress to
		* provide it for us.
		*/
	add_theme_support('title-tag');

	/*
		* Enable support for Post Thumbnails on posts and pages.
		*
		* @link https://developer.wordpress.org/themes/functionality/featured-images-post-thumbnails/
		*/
	add_theme_support('post-thumbnails');

	// This theme uses wp_nav_menu() in one location.
	register_nav_menus(
		array(
			'menu-1' => esc_html__('Primary', 'futebol365'),
		)
	);

	/*
		* Switch default core markup for search form, comment form, and comments
		* to output valid HTML5.
		*/
	add_theme_support(
		'html5',
		array(
			'search-form',
			'comment-form',
			'comment-list',
			'gallery',
			'caption',
			'style',
			'script',
		)
	);

	// Set up the WordPress core custom background feature.
	add_theme_support(
		'custom-background',
		apply_filters(
			'futebol365_custom_background_args',
			array(
				'default-color' => 'ffffff',
				'default-image' => '',
			)
		)
	);

	// Add theme support for selective refresh for widgets.
	add_theme_support('customize-selective-refresh-widgets');

	/**
	 * Add support for core custom logo.
	 *
	 * @link https://codex.wordpress.org/Theme_Logo
	 */
	add_theme_support(
		'custom-logo',
		array(
			'height'      => 250,
			'width'       => 250,
			'flex-width'  => true,
			'flex-height' => true,
		)
	);
}
add_action('after_setup_theme', 'futebol365_setup');

/**
 * Set the content width in pixels, based on the theme's design and stylesheet.
 *
 * Priority 0 to make it available to lower priority callbacks.
 *
 * @global int $content_width
 */
function futebol365_content_width()
{
	$GLOBALS['content_width'] = apply_filters('futebol365_content_width', 640);
}
add_action('after_setup_theme', 'futebol365_content_width', 0);

/**
 * Register widget area.
 *
 * @link https://developer.wordpress.org/themes/functionality/sidebars/#registering-a-sidebar
 */
function futebol365_widgets_init()
{
	register_sidebar(
		array(
			'name'          => esc_html__('Sidebar', 'futebol365'),
			'id'            => 'sidebar-1',
			'description'   => esc_html__('Add widgets here.', 'futebol365'),
			'before_widget' => '<section id="%1$s" class="widget %2$s">',
			'after_widget'  => '</section>',
			'before_title'  => '<h2 class="widget-title">',
			'after_title'   => '</h2>',
		)
	);
}
add_action('widgets_init', 'futebol365_widgets_init');

/**
 * Enqueue scripts and styles.
 */
function futebol365_scripts()
{
	wp_enqueue_script( 'jquery' );
	wp_enqueue_style('futebol365-style', get_stylesheet_uri(), array(), _S_VERSION);
	wp_style_add_data('futebol365-style', 'rtl', 'replace');

	wp_enqueue_script('futebol365-navigation', get_template_directory_uri() . '/js/navigation.js', array(), _S_VERSION, true);
	wp_enqueue_script('futebol365-lagyload', get_template_directory_uri() . '/assets/js/ink-all.min.js', array(), _S_VERSION, true);

	if (
	is_singular('f365_gallery')
) {

	wp_enqueue_script(
		'multimedia-gallery',
			get_template_directory_uri() . '/assets/js/gallery.js',
			array(),
			time(),
			true
		);

	}
	if(is_singular('f365_video'))
		{
			wp_enqueue_script(
			'google-platform',
			'https://apis.google.com/js/platform.js',
			array(),
			null,
			true

		);
		}

	if (is_singular() && comments_open() && get_option('thread_comments')) {
		wp_enqueue_script('comment-reply');
	}
}
add_action('wp_enqueue_scripts', 'futebol365_scripts');

/**
 * Multimedia Custom Permalinks
 */
add_filter(
	'post_type_link',
	function ( $post_link, $post ) {

		if ( 'f365_video' === $post->post_type ) {

			return home_url(
				'/multimedia/videos/' .
				$post->post_name .
				'/'
			);

		}

		if ( 'f365_gallery' === $post->post_type ) {

			return home_url(
				'/multimedia/fotogalerias/' .
				$post->post_name .
				'/'
			);

		}

		return $post_link;

	},
	999,
	2
);

/**
 * Register Query Vars
 */
add_filter(
	'query_vars',
	function ( $vars ) {

		$vars[] = 'f365_section';
		$vars[] = 'gallery_id';

		return $vars;
	}
);


/**
 * Multimedia archives.
 */

add_action(
	'pre_get_posts',
	function ( $query ) {

		if (
			is_admin() ||
			! $query->is_main_query()
		) {
			return;
		}

		// The site paginates every listing with ?pagina=N. Map it onto WP's
		// `paged` for all non-singular listing main queries — taxonomies,
		// categories, tags, author, date, the news routes / blog index and
		// search — so WordPress and Yoast resolve the correct current page,
		// which is what drives the rel prev/next numbers. Scope matches
		// f365_canonical_append_pagina() in the plugin.
		if (
			( is_archive() || is_home() || is_search() ) &&
			isset( $_GET['pagina'] )
		) {
			$query->set(
				'paged',
				max( 1, absint( $_GET['pagina'] ) )
			);
		}

		// The publisher (banca-de-jornais) term archive renders 20 covers per
		// page from a secondary query; align the main query so its
		// max_num_pages (and the rel next bound) matches the visible pagination.
		if ( is_tax( 'f365_publisher' ) ) {
			$query->set( 'posts_per_page', 20 );
		}
	}
);



add_filter(
    'redirect_canonical',
    function ( $redirect_url, $requested_url ) {

		if ( is_404() ) {
            return false;
        }

	    if ( get_query_var( 'f365_section' ) ) {
            return false;
        }

        if ( isset( $_GET['pagina'] ) ) {
            return false;
        }

		if ( get_query_var( 'list_type' ) ) {
            return false;
        }

        return $redirect_url;

    },
    10,
    2
);

add_filter(
    'permalink_manager_filter_redirect',
    function ( $correct_permalink ) {
        if ( get_query_var( 'f365_section' ) ) {
            return false;
        }
        return $correct_permalink;
    },
    10,
    1
);

/**
 * Implement the Custom Header feature.
 */
require get_template_directory() . '/inc/custom-header.php';

/**
 * Custom template tags for this theme.
 */
require get_template_directory() . '/inc/template-tags.php';

/**
 * Functions which enhance the theme by hooking into WordPress.
 */
require get_template_directory() . '/inc/template-functions.php';
require get_template_directory() . '/inc/helper-functions.php';
require get_template_directory() . '/inc/mp-functions.php';
require get_template_directory() . '/inc/dv-functions.php';
require get_template_directory() . '/inc/guias-functions.php';


require get_template_directory() . '/inc/template-acf-fields.php';

/**
 * Customizer additions.
 */
require get_template_directory() . '/inc/customizer.php';
//require get_template_directory() . '/inc/custom-post-types.php';

/**
 * Load Jetpack compatibility file.
 */
if (defined('JETPACK__VERSION')) {
	require get_template_directory() . '/inc/jetpack.php';
}

/**
 * Clear the front page highlight posts transient cache when a post is saved or modified
 */
add_action('save_post', function($post_id) {
    if (get_post_type($post_id) === 'post') {
        delete_transient('f365_highlight_post_ids');
    }
});
/**
 * Clear author page transient cache on post save
 */

add_action( 'save_post', function( $post_id ) {

    if ( get_post_type( $post_id ) !== 'post' ) {
        return;
    }

    $author_id = get_post_field( 'post_author', $post_id );
    $page      = 1;

    while ( delete_transient( 'f365_author_' . $author_id . '_page_' . $page ) ) {
        $page++;
    }
} );
add_filter(
	'do_redirect_guess_404_permalink',
	'__return_false'
);
/**
 * Populate ACF country_id select field with custom table data
 */
add_filter('acf/load_field/name=country_id', 'futebol365_acf_load_country_id_choices');
function futebol365_acf_load_country_id_choices($field)
{
	$field['choices'] = [];
	global $wpdb;
	$table_name = $wpdb->prefix . 'tbl_countries';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name));

	if ($table_exists) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$countries = $wpdb->get_results("SELECT id, shortname FROM {$table_name} ORDER BY shortname ASC");
		if ($countries) {
			foreach ($countries as $country) {
				$field['choices'][$country->id] = $country->shortname;
			}
		}
	}
	return $field;
}
add_filter(
	'single_template',
	function ($template) {

		global $post;

		if (
			isset($post->post_type) &&
			'f365_stadium' === $post->post_type
		) {

			$new_template =
				get_template_directory() .
				'/single-f365_stadium.php';

			if (
				file_exists($new_template)
			) {

				return $new_template;

			}

		}

		return $template;

	},
	999
);
add_action(
	'save_post',
	function ( $post_id ) {

		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		delete_transient(
			'f365_latest_posts_v1'
		);
	}
);
add_action( 'acf/save_post', function( $post_id ) {
    delete_transient( 'f365_video_fields_' . $post_id );
} );
add_action(
	'save_post_post',
	function ( $post_id ) {

		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		delete_transient(
			'f365_related_news_' . $post_id
		);
	}
);
add_action(
	'save_post_f365_video',
	function () {

		global $wpdb;

		$wpdb->query(
			"DELETE FROM {$wpdb->options}
			 WHERE option_name LIKE '_transient_f365_related_videos_%'
			    OR option_name LIKE '_transient_timeout_f365_related_videos_%'"
		);
	}
);
add_action('save_post_f365_gallery', function () {
    wp_cache_delete('latest_galleries_v1', 'f365_gallery_relations');
});
// match function

// add_filter( 'wpseo_breadcrumb_links', 'f365_custom_referee_breadcrumbs' );

// function f365_custom_referee_breadcrumbs( $links ) {

// if ( ! get_query_var( 'f365_referee_id' ) ) {
//     return $links;
// }

// $links = array();

// $links[] = array(
//     'url'  => home_url('/'),
//     'text' => 'Home',
// );

// // FIFA
// $links[] = array(
//     'url'  => home_url('/federacao/1/jogadores'),
//     'text' => 'FIFA',
// );

// // UEFA
// $links[] = array(
//     'url'  => home_url('/federacao/2/jogadores'),
//     'text' => 'UEFA',
// );

// // Portugal
// $links[] = array(
//     'url'  => home_url('/federacao/9/jogadores'),
//     'text' => 'Portugal',
// );

// // AF Lisboa
// $links[] = array(
//     'url'  => home_url('/federacao/248/jogadores'),
//     'text' => 'AF Lisboa',
// );

// $links[] = array(
//     'url'  => get_permalink(),
//     'text' => get_the_title(),
// );

// return $links;

// }
// add_filter( 'wpseo_breadcrumb_links', 'f365_override_referee_yoast_breadcrumbs', 20 );

// /**
//  * Override Yoast breadcrumbs for referee custom routes.
//  *
//  * Expected route vars:
//  * - f365_referee_id
//  * - f365_section: '' | 'resumo' | 'jogos'
//  *
//  * @param array<int,array<string,mixed>> $links Yoast breadcrumb links.
//  * @return array<int,array<string,mixed>>
//  */
// function f365_override_referee_yoast_breadcrumbs( $links ) {
// 	$referee_post = f365_get_breadcrumb_referee_post();

// 	if ( ! ( $referee_post instanceof WP_Post ) ) {
// 		return $links;
// 	}

// 	$current_section = (string) get_query_var( 'f365_section' );
// 	$current_section = '' === $current_section ? 'resumo' : sanitize_key( $current_section );

// 	$breadcrumb_links = f365_get_referee_existing_parent_breadcrumb_links( $links, $referee_post );

// 	foreach ( f365_get_referee_parent_breadcrumb_links( $referee_post, $breadcrumb_links ) as $parent_link ) {
// 		$breadcrumb_links[] = $parent_link;
// 	}

// 	$referee_url  = get_permalink( $referee_post );
// 	$referee_name = get_the_title( $referee_post );

// 	if ( 'resumo' === $current_section ) {
// 		$breadcrumb_links[] = [
// 			'url'  => $referee_url,
// 			'text' => $referee_name,
// 			'id'   => $referee_post->ID,
// 		];

// 		return $breadcrumb_links;
// 	}

// 	$breadcrumb_links[] = [
// 		'url'  => $referee_url,
// 		'text' => $referee_name,
// 		'id'   => $referee_post->ID,
// 	];

// 	$breadcrumb_links[] = [
// 		'url'  => f365_get_referee_section_breadcrumb_url( $referee_url, $current_section ),
// 		'text' => f365_get_referee_section_breadcrumb_label( $current_section ),
// 	];

// 	return $breadcrumb_links;
// }

// /**
//  * Keep Yoast's existing parent breadcrumb chain, but remove the current page crumb.
//  *
//  * Yoast can add parents from settings, taxonomies, CPT archives, or page parents.
//  * This keeps those multiple parents intact and lets this plugin only replace the
//  * final referee/current-section crumbs.
//  *
//  * @param array<int,array<string,mixed>> $links        Yoast breadcrumb links.
//  * @param WP_Post                       $referee_post Referee post.
//  * @return array<int,array<string,mixed>>
//  */
// function f365_get_referee_existing_parent_breadcrumb_links( $links, WP_Post $referee_post ) {
// 	$breadcrumb_links = [];
// 	$referee_url      = f365_normalize_breadcrumb_url( get_permalink( $referee_post ) );

// 	if ( empty( $links ) ) {
// 		return [
// 			[
// 				'url'  => home_url( '/' ),
// 				'text' => get_bloginfo( 'name' ),
// 			],
// 		];
// 	}

// 	foreach ( $links as $link ) {
// 		$link_url = isset( $link['url'] ) ? f365_normalize_breadcrumb_url( $link['url'] ) : '';
// 		$link_id  = isset( $link['id'] ) ? (int) $link['id'] : 0;

// 		if ( $link_id === (int) $referee_post->ID || ( '' !== $link_url && $link_url === $referee_url ) ) {
// 			continue;
// 		}

// 		$breadcrumb_links[] = $link;
// 	}

// 	return $breadcrumb_links;
// }

// /**
//  * Get the current referee post from project helper or query var.
//  *
//  * @return WP_Post|null
//  */
// function f365_get_breadcrumb_referee_post() {
// 	if ( function_exists( 'f365_get_current_referee_post' ) ) {
// 		$referee_post = f365_get_current_referee_post();

// 		if ( $referee_post instanceof WP_Post ) {
// 			return $referee_post;
// 		}
// 	}

// 	$referee_id = (int) get_query_var( 'f365_referee_id' );

// 	return $referee_id > 0 ? get_post( $referee_id ) : null;
// }

// /**
//  * Build breadcrumb links for all parent posts/pages.
//  *
//  * @param WP_Post                       $referee_post    Referee post.
//  * @param array<int,array<string,mixed>> $existing_links Existing breadcrumb links.
//  * @return array<int,array<string,mixed>>
//  */
// function f365_get_referee_parent_breadcrumb_links( WP_Post $referee_post, $existing_links = [] ) {
// 	$parent_links = [];
// 	$ancestor_ids = array_reverse( get_post_ancestors( $referee_post ) );

// 	foreach ( $ancestor_ids as $ancestor_id ) {
// 		if ( f365_breadcrumb_link_exists( $existing_links, $ancestor_id, get_permalink( $ancestor_id ) ) ) {
// 			continue;
// 		}

// 		$parent_links[] = [
// 			'url'  => get_permalink( $ancestor_id ),
// 			'text' => get_the_title( $ancestor_id ),
// 			'id'   => $ancestor_id,
// 		];
// 	}

// 	return $parent_links;
// }

// /**
//  * Check if a breadcrumb link already exists.
//  *
//  * @param array<int,array<string,mixed>> $links   Breadcrumb links.
//  * @param int                           $post_id Post ID.
//  * @param string                        $url     URL.
//  * @return bool
//  */
// function f365_breadcrumb_link_exists( $links, $post_id, $url ) {
// 	$normalized_url = f365_normalize_breadcrumb_url( $url );

// 	foreach ( $links as $link ) {
// 		$link_url = isset( $link['url'] ) ? f365_normalize_breadcrumb_url( $link['url'] ) : '';
// 		$link_id  = isset( $link['id'] ) ? (int) $link['id'] : 0;

// 		if ( $link_id === (int) $post_id || ( '' !== $link_url && $link_url === $normalized_url ) ) {
// 			return true;
// 		}
// 	}

// 	return false;
// }

// /**
//  * Normalize breadcrumb URLs for duplicate checks.
//  *
//  * @param string $url URL.
//  * @return string
//  */
// function f365_normalize_breadcrumb_url( $url ) {
// 	return untrailingslashit( strtok( (string) $url, '?' ) );
// }

// /**
//  * Build the section URL used in the breadcrumb.
//  *
//  * @param string $referee_url     Referee base URL.
//  * @param string $current_section Current route section.
//  * @return string
//  */
// function f365_get_referee_section_breadcrumb_url( $referee_url, $current_section ) {
// 	if ( 'jogos' === $current_section ) {
// 		return user_trailingslashit( trailingslashit( $referee_url ) . 'jogos' );
// 	}

// 	return user_trailingslashit( trailingslashit( $referee_url ) . $current_section );
// }

// /**
//  * Get display label for custom referee sections.
//  *
//  * @param string $current_section Current route section.
//  * @return string
//  */
// function f365_get_referee_section_breadcrumb_label( $current_section ) {
// 	$labels = [
// 		'resumo' => __( 'Resumo', 'futebol365' ),
// 		'jogos'  => __( 'Jogos', 'futebol365' ),
// 	];

// 	return $labels[ $current_section ] ?? ucwords( str_replace( [ '-', '_' ], ' ', $current_section ) );
// }




function futebol365_livegame_theme_image() {
    return get_template_directory_uri() . '/assets/images/jogos03.jpg';
}


add_action('template_redirect', function () {

    $request_uri = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');

    // Development
    if ($request_uri === 'futebol365/equipa') {
        wp_redirect(home_url(), 301);
        exit;
    }

    // Production
    if ($request_uri === 'equipa') {
        wp_redirect(home_url('/'), 301);
        exit;
    }

});
add_filter( 'wpseo_breadcrumb_links', function( $links ) {

    if ( is_author() ) {
        $author = get_queried_object();

        if ( $author instanceof WP_User ) {
            $last = count( $links ) - 1;

            $links[$last]['text'] = $author->display_name;
            $links[$last]['url']  = get_author_posts_url( $author->ID );
        }
    }

    return $links;
});

/**
 * Dequeue poll-maker and other voting/likes scripts for search crawlers/bots
 * to prevent automated client-side POST AJAX requests that overload the server.
 */
function futebol365_dequeue_bot_scripts() {
	$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? $_SERVER['HTTP_USER_AGENT'] : '';
	if ( ! empty( $user_agent ) && preg_match( '/(applebot|googlebot|bingbot|yandexbot|baiduspider|duckduckbot|facebot|facebookexternalhit|twitterbot|ia_archiver|crawler|spider|bot)/i', $user_agent ) ) {
		// Dequeue known poll-maker script handles
		$handles = array(
			'poll-maker-ajax-public',
			'poll-maker',
			'poll-maker-category',
			'poll-maker-autosize',
			'poll-maker-charts-google'
		);
		foreach ( $handles as $handle ) {
			wp_dequeue_script( $handle );
			wp_deregister_script( $handle );
		}

		// Dynamically search for other scripts localizing "likes_handler" or "poll_maker_ajax_public"
		global $wp_scripts;
		if ( ! empty( $wp_scripts->queue ) ) {
			foreach ( $wp_scripts->queue as $handle ) {
				if ( isset( $wp_scripts->registered[$handle] ) ) {
					$script = $wp_scripts->registered[$handle];
					if ( ! empty( $script->extra['data'] ) && ( strpos( $script->extra['data'], 'likes_handler' ) !== false || strpos( $script->extra['data'], 'poll_maker_ajax_public' ) !== false ) ) {
						wp_dequeue_script( $handle );
						wp_deregister_script( $handle );
					}
				}
			}
		}
	}
}
add_action( 'wp_enqueue_scripts', 'futebol365_dequeue_bot_scripts', 9999 );
add_action( 'wp_print_scripts', 'futebol365_dequeue_bot_scripts', 9999 );

/**
 * Block search engine bots and crawlers from making POST requests to admin-ajax.php.
 * Hooked to admin_init to intercept AJAX early.
 */
add_action( 'admin_init', function() {
	if ( defined( 'DOING_AJAX' ) && DOING_AJAX && isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] ) {
		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? $_SERVER['HTTP_USER_AGENT'] : '';
		if ( ! empty( $user_agent ) && preg_match( '/(applebot|googlebot|bingbot|yandexbot|baiduspider|duckduckbot|facebot|facebookexternalhit|twitterbot|ia_archiver|crawler|spider|bot)/i', $user_agent ) ) {
			header( 'HTTP/1.1 403 Forbidden' );
			header( 'Content-Type: text/plain; charset=utf-8' );
			exit( 'Forbidden: Crawlers are not allowed to make POST requests.' );
		}
	}
}, 1 );

add_action('pre_get_posts', function($query) {
    if (!is_admin() && $query->is_main_query() && is_post_type_archive('f365_federation')) {
        $query->set('orderby', 'title');
        $query->set('order', 'ASC');
    }
});

add_filter('get_the_archive_title', function($title) {
    if (is_post_type_archive('f365_federation')) {
        $title = __('Federations', 'futebol365');
    }
    return $title;
});

// Yoast prev/next rel URLs override for federations archive page to support pretty /page/N/ format
add_filter('wpseo_adjacent_rel_url', 'f365_theme_federation_archive_adjacent_rel', 100, 2);
function f365_theme_federation_archive_adjacent_rel($url, $rel) {
    if (is_post_type_archive('f365_federation')) {
        global $wp_query;
        $current = max(1, get_query_var('paged', 1));
        $total = (int) $wp_query->max_num_pages;
        $base = get_post_type_archive_link('f365_federation');

        if ($base) {
            if ('prev' === $rel) {
                if ($current <= 1) {
                    return '';
                }
                return (2 === $current) 
                    ? user_trailingslashit($base) 
                    : user_trailingslashit(trailingslashit($base) . 'page/' . ($current - 1));
            }

            if ('next' === $rel) {
                if ($current >= $total) {
                    return '';
                }
                return user_trailingslashit(trailingslashit($base) . 'page/' . ($current + 1));
            }
        }
    }
    return $url;
}

/**
 * Disable WP Rocket's strict URL validation for our custom rewritten routes.
 * This prevents caching issues where custom section URLs do not match the main post permalink.
 */
add_filter( 'do_rocket_generate_caching_files', function( $do_cache ) {
	if ( $do_cache ) {
		return true;
	}

	return (bool) (
		get_query_var( 'f365_team_id' )
		|| get_query_var( 'f365_team2_id' )
		|| get_query_var( 'f365_section' )
		|| get_query_var( 'f365_player_id' )
		|| get_query_var( 'f365_match_id' )
		|| get_query_var( 'f365_stadium_id' )
		|| get_query_var( 'f365_referee_id' )
		|| get_query_var( 'f365_president_id' )
		|| get_query_var( 'f365_fed_id' )
	);
} );
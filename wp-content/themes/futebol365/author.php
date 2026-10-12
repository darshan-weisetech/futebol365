<?php

/**
 * Author Page
 *
 * Native WordPress loop (all records migrated to posts/users). Author data from
 * user meta (no custom source table). Image: featured thumbnail first, else the
 * source image via the post's legacy news id.
 *
 * @package Futebol365
 */

defined( 'ABSPATH' ) || exit;

get_header();

$author_id = get_queried_object_id();

$author_name        = get_the_author_meta( 'display_name', $author_id );
$author_description = get_the_author_meta( 'description', $author_id );

$author_avatar = '';

// Preference 1: local avatar set via the wp-user-avatars plugin
if ( function_exists( 'wp_user_avatars_get_local_avatar_url' ) ) {
    $local_avatar = wp_user_avatars_get_local_avatar_url( $author_id, 300 );
    if ( ! empty( $local_avatar ) ) {
        $author_avatar = $local_avatar;
    }
}

// Fallback: default fallback image
if ( empty( $author_avatar ) ) {
    $author_avatar = esc_url( FB_THEME_URL . '/assets/images/no-image-user.jpg' );
}


/**
 * Social usernames (user meta).
 */
$facebook_username  = trim( (string) get_the_author_meta( 'facebook', $author_id ) );
$twitter_username   = trim( (string) get_the_author_meta( 'twitter', $author_id ) );
$instagram_username = trim( (string) get_the_author_meta( 'instagram', $author_id ) );

$author_socials = array(

	'facebook' => array(
		'url' => ! empty( $facebook_username )
			? 'https://facebook.com/' . ltrim( $facebook_username, '@' )
			: '',
	),

	'twitter' => array(
		'url' => ! empty( $twitter_username )
			? 'https://x.com/' . ltrim( $twitter_username, '@' )
			: '',
	),

	'instagram' => array(
		'url' => ! empty( $instagram_username )
			? 'https://instagram.com/' . ltrim( $instagram_username, '@' )
			: '',
	),

);

?>

<main id="primary" class="site-main author-page">
    <div class="ink-grid vertical-space" source="adsense">
        <?php get_template_part( 'template-parts/template-offer-widget' ); ?>
        <div class="ink-header-border padding">

            <div class="column-group xlarge-horizontal-gutters large-horizontal-gutters medium-horizontal-gutters small-vertical-gutters tiny-vertical-gutters small-align-center tiny-align-center">

                <div class="xlarge-20 large-25 medium-30 small-100 tiny-100">

                    <figure>

                        <img
                            src="<?php echo esc_url( $author_avatar ); ?>" class="w-full"
                            onerror="this.onerror=null;this.src='<?php echo esc_url( FB_THEME_URL . '/assets/images/no-image-user.jpg' ); ?>';"
                            alt="<?php echo esc_attr( $author_name ); ?>"
                            title="<?php echo esc_attr( $author_name ); ?>">

                    </figure>

                </div>

                <div class="xlarge-80 large-75 medium-70 small-100 tiny-100 no-margin">

                    <h1 class="slab-700 text-capital">
                        <?php echo esc_html( $author_name ); ?>
                    </h1>

                    <?php if ( $author_description ) : ?>

                        <div class="half-top-space slab-400">

                            <?php
                            echo wp_kses_post(
                                wpautop( $author_description )
                            );
                            ?>

                        </div>

                    <?php endif; ?>

                    <div class="half-top-space">

                        <?php foreach ( $author_socials as $social_name => $social ) : ?>

                            <?php if ( ! empty( $social['url'] ) ) : ?>

                                <a
                                    href="<?php echo esc_url( $social['url'] ); ?>"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    aria-label="<?php echo esc_attr( ucfirst( $social_name ) ); ?>">

                                    <?php if ( $social_name === 'facebook' ) : ?>

                                        <svg class="quarter-right-space" viewBox="0 0 16 16" width="1em" height="1em" aria-hidden="true" focusable="false" style="pointer-events: unset;">
                                            <use href="#social-facebook" style="pointer-events: unset;"></use>
                                        </svg>

                                    <?php elseif ( $social_name === 'twitter' ) : ?>

                                        <svg class="quarter-right-space" viewBox="0 0 16 16" width="1em" height="1em" aria-hidden="true" focusable="false" style="pointer-events: unset;">
                                            <use href="#social-x" style="pointer-events: unset;"></use>
                                        </svg>

                                    <?php elseif ( $social_name === 'instagram' ) : ?>

                                        <svg class="quarter-right-space" viewBox="0 0 16 16" width="1em" height="1em" aria-hidden="true" focusable="false" style="pointer-events: unset;">
                                            <use href="#social-instagram" style="pointer-events: unset;"></use>
                                        </svg>

                                    <?php endif; ?>

                                </a>

                            <?php endif; ?>

                        <?php endforeach; ?>

                    </div>

                </div>

            </div>

        </div>
    </div>


    <div class="ink-grid futebol365-container">

        <section class="left-column-block">
		<div class="breadcrumbs-f365">

			<div class="ink-navigation breadcrumbs">

				<?php
				if ( function_exists( 'yoast_breadcrumb' ) ) {
					yoast_breadcrumb();
				}
				?>

			</div>

		</div>

            <?php if ( have_posts() ) : ?>

                <?php while (have_posts()) : the_post(); ?>

                    <?php get_template_part('template-parts/news-listing'); ?>

                <?php endwhile; ?>


                <?php
                    global $wp_query;

                    $total_pages = min(20,(int) $wp_query->max_num_pages );
                    $current_page = max(1,absint($_GET['pagina'] ?? get_query_var( 'paged', 1 )));

                    if ( $total_pages > 1 ) {

                        futebol365_render_pagination(
                            $current_page,
                            $total_pages
                        );
                    }
                    ?>

            <?php endif; ?>

            <?php get_template_part( 'template-parts/template-front-page-posts' ); ?>
        </section>

        <section class="right-column-block">

            <?php get_sidebar(); ?>

        </section>

    </div>

</main>

<?php
get_footer();
<?php

/**
 * The template for displaying 404 pages (not found)
 *
 * No ACF. Static markup matching the live design.
 *
 * @package Futebol365
 */

$background_url = get_template_directory_uri() . '/assets/images/offline-background.jpg';
?>
<!doctype html>
<html <?php language_attributes(); ?>>

<head>
	<meta charset="<?php bloginfo('charset'); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link href="https://fonts.googleapis.com/css2?family=Montserrat:ital,wght@0,100..900;1,100..900&display=swap" rel="stylesheet">

	<?php wp_head(); ?>
</head>

<body <?php body_class( 'custom-404-page' ); ?>>
	<?php wp_body_open(); ?>
	<svg xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false" style="position:absolute;width:0;height:0;overflow:hidden">
		<symbol id="social-facebook" viewBox="0 0 16 16">
			<path fill="currentColor" d="M16 8.049c0-4.446-3.582-8.05-8-8.05C3.58 0-.002 3.603-.002 8.05c0 4.017 2.926 7.347 6.75 7.951v-5.625h-2.03V8.05H6.75V6.275c0-2.017 1.195-3.131 3.022-3.131.876 0 1.791.157 1.791.157v1.98h-1.009c-.993 0-1.303.621-1.303 1.258v1.51h2.218l-.354 2.326H9.25V16c3.824-.604 6.75-3.934 6.75-7.951" />
		</symbol>
		<symbol id="social-x" viewBox="0 0 16 16">
			<path fill="currentColor" d="M12.6.75h2.454l-5.36 6.142L16 15.25h-4.937l-3.867-5.07-4.425 5.07H.316l5.733-6.57L0 .75h5.063l3.495 4.633L12.601.75Zm-.86 13.028h1.36L4.323 2.145H2.865z" />
		</symbol>
	</svg>
	<div id="notfound" style="background-image:url('<?php echo esc_url($background_url); ?>');">
		<div class="notfound-bg"></div>
		<div class="notfound">
			<div class="notfound-404">
				<h1><?php esc_html_e( 'OPS!', 'futebol365' ); ?></h1>
			</div>
			<h2>
				<?php
				echo wp_kses_post(
					__(
						'Lamentamos, mas a página não foi encontrada.<br><br>Regresse à página inicial.',
						'futebol365'
					)
				);
				?>
			</h2>
			<a href="<?php echo esc_url( home_url( '/?utm_source=Error404' ) ); ?>" class="home-btn">
					<?php esc_html_e( 'Voltar', 'futebol365' ); ?>
				</a>
			<div class="notfound-social">
				<a href="<?php echo esc_url( 'https://www.facebook.com/futebol365' ); ?>"><svg viewBox="0 0 16 16" width="1em" height="1em" aria-hidden="true" focusable="false"><use href="#social-facebook"></use></svg></a>
				<a href="<?php echo esc_url( 'https://twitter.com/futebol365' ); ?>"><svg viewBox="0 0 16 16" width="1em" height="1em" aria-hidden="true" focusable="false"><use href="#social-x"></use></svg></a>
			</div>
		</div>
	</div>
	<?php wp_footer(); ?>

</body>

</html>
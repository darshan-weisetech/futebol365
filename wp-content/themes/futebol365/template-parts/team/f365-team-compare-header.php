<?php
/**
 * Team Compare Header
 *
 * Drop this in:
 * template-parts/team/f365-team-compare-header.php
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'f365_team_compare_route_args' ) ) {
	function f365_team_compare_route_args() {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );

		if (
			preg_match(
				'#(?:^|/)equipa/(\d+)/comparar-com/(\d+)(?:/(confrontos|forma|palmares|estadios))?/?$#',
				$path,
				$matches
			)
		) {
			return array(
				'first_django_id'  => absint( $matches[1] ),
				'second_django_id' => absint( $matches[2] ),
				'section'          => isset( $matches[3] ) ? sanitize_key( $matches[3] ) : 'resumo',
			);
		}

		return array(
			'first_django_id'  => 0,
			'second_django_id' => 0,
			'section'          => 'resumo',
		);
	}
}

$route = f365_team_compare_route_args();

$first_id        = absint( $args['first_id'] ?? get_the_ID() );
$second_id       = absint( $args['second_id'] ?? 0 );
$current_section = sanitize_key( $args['current_section'] ?? '' );
$all_sections    = $args['sections'] ?? futebol365_get_team_compare_sections();
$nav_qs          = $args['nav_qs'] ?? '';
$is_clubs        = $args['is_clubs'] ?? true;

$first_django_id = absint( $args['first_django_id'] ?? 0 );
if ( ! $first_django_id && $first_id ) {
	$first_django_id = absint( get_post_meta( $first_id, 'django_id', true ) );
}
if ( ! $first_django_id ) {
	$first_django_id = $route['first_django_id'];
}

$second_django_id = absint( $args['second_django_id'] ?? 0 );
if ( ! $second_django_id && $second_id ) {
	$second_django_id = absint( get_post_meta( $second_id, 'django_id', true ) );
}
if ( ! $second_django_id ) {
	$second_django_id = $route['second_django_id'];
}

if ( ! empty( $route['second_django_id'] ) ) {
	$current_section = $route['section'];
} elseif ( '' === $current_section ) {
	$current_section = $route['section'];
}

$section_aliases = array(
	''              => 'resumo',
	'resume'        => 'resumo',
	'resumo'        => 'resumo',
	'confrontation' => 'confrontos',
	'confrontos'    => 'confrontos',
	'shape'         => 'forma',
	'forma'         => 'forma',
	'awards'        => 'palmares',
	'palmares'      => 'palmares',
	'stadiums'      => 'estadios',
	'estadios'      => 'estadios',
);

$current_section = $section_aliases[ $current_section ] ?? $current_section;

$base_url = home_url(
	user_trailingslashit(
		sprintf(
			'equipa/%d/comparar-com/%d',
			$first_django_id,
			$second_django_id
		)
	)
);
?>

<div class="ink-grid vertical-space team-<?php echo absint( $first_django_id ); ?>">

	<?php get_template_part( 'template-parts/template-offer-widget' ); ?>

	<div class="ink-header-f365 f365-compare-header">
		<div class="column-group">
			<div class="align-left all-100">
				<div class="headerdata">
					<h1><?php esc_html_e( 'Frente-a-frente', 'futebol365' ); ?></h1>
				</div>
			</div>
		</div>
	</div>

	<div class="nav-trigger" id="nav-trigger">
		<span><?php esc_html_e( 'Menu', 'futebol365' ); ?></span>
	</div>

	<nav class="nav-main" id="nav-main">
		<ul>
			<?php foreach ( $all_sections as $slug => $section ) : ?>
				<?php
				$slug = sanitize_key( $slug );

				if ( 'estadios' === $slug && ! $is_clubs ) {
					continue;
				}

				$normalized_slug = $section_aliases[ $slug ] ?? $slug;
				$is_active       = $current_section === $normalized_slug;

				if ( '' === $slug || 'resumo' === $normalized_slug ) {
					$tab_url = $base_url;
				} else {
					$tab_url = trailingslashit( untrailingslashit( $base_url ) . '/' . $normalized_slug );
				}

				if ( ! empty( $nav_qs ) ) {
					$tab_url = add_query_arg( wp_parse_args( ltrim( $nav_qs, '?' ) ), $tab_url );
				}
				?>

				<li class="<?php echo $is_active ? 'active' : ''; ?>">
					<a class="<?php echo $is_active ? 'active' : ''; ?>" href="<?php echo esc_url( $tab_url ); ?>">
						<?php if ( $is_active ) : ?>
							<b><?php echo esc_html( $section['label'] ); ?></b>
						<?php else : ?>
							<?php echo esc_html( $section['label'] ); ?>
						<?php endif; ?>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
	</nav>

	<nav class="nav-mobile" id="nav-mobile"></nav>
</div>

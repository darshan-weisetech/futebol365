<?php
/**
 * Referee Header — shared across Resumo / Jogos
 *
 * Renders the nationality flag, name, the Resumo|Jogos nav, and the personal
 * details card. Markup classes preserved from the original static template
 * (arbitro-title / arbitro-details / persion-info). Labels match the Django
 *
 * @package Futebol365
 *
 * Args (via get_template_part $args):
 *   referee         array  Output of f365_get_referee_data()
 *   current_section string 'resumo' | 'jogos'
 *   sections        array  get_referee_section_functions()
 */

defined( 'ABSPATH' ) || exit;

$referee         = isset( $args['referee'] ) && is_array( $args['referee'] ) ? $args['referee'] : [];
$current_section = isset( $args['current_section'] ) ? (string) $args['current_section'] : 'resumo';

$display_name = (string) ( $referee['display_name'] ?? '' );
$shortname    = (string) ( $referee['shortname'] ?? $display_name );
$longname     = (string) ( $referee['longname'] ?? '' );
$flag_url     = (string) ( $referee['header_country']['flag_url'] ?? '' );
$flag_alt     = (string) ( $referee['header_country']['name'] ?? '' );
$photo_url    = (string) ( $referee['photo_url'] ?? '' );

if ( empty($photo_url) ) {
	$photo_url = get_template_directory_uri() . '/assets/images/no-image.jpg';
}

$birth_date   = (string) ( $referee['birth_date'] ?? '' );
$age          = $referee['age'] ?? null;
$is_deceased  = ! empty( $referee['is_deceased'] );
$death_date   = (string) ( $referee['death_date'] ?? '' );
$birth_place  = (string) ( $referee['birth_place'] ?? '' );
$naturality   = (string) ( $referee['naturality']['name'] ?? '' );
$nationality  = (string) ( $referee['nationality']['name'] ?? '' );

$base_url     = (string) ( $referee['base_url'] ?? '#' );
$games_url    = (string) ( $referee['games_url'] ?? '#' );

$birth_display = '';
if ( $birth_date !== '' ) {
	$ts = strtotime( $birth_date );
	$birth_display = $ts ? date_i18n( 'd-m-Y', $ts ) : $birth_date;
}
$death_display = '';
if ( $death_date !== '' ) {
	$ts = strtotime( $death_date );
	$death_display = $ts ? date_i18n( 'd-m-Y', $ts ) : $death_date;
}
?>

<div class="arbitro-title">
	<div class="image-block">
		<?php if ( $flag_url !== '' ) : ?>
			<i><img src="<?php echo esc_url( $flag_url ); ?>" alt="<?php echo esc_attr( $flag_alt ); ?>" title="<?php echo esc_attr( $flag_alt ); ?>"></i>
		<?php endif; ?>
		<div class="mobile-title">
			<h2><?php echo esc_html( $shortname !== '' ? $shortname : esc_html__( 'Árbitro', 'futebol365' ) ); ?></h2>
				<ul class="arbitro-links mobile-show">
				<li>
					<a href="<?php echo esc_url( $base_url ); ?>" title="<?php echo esc_attr__( 'Resumo', 'futebol365' ); ?>">
						<?php if ( $current_section === 'resumo' ) : ?>
							<strong><?php echo esc_html__( 'Resumo', 'futebol365' ); ?></strong>
						<?php else : ?>
							<?php echo esc_html__( 'Resumo', 'futebol365' ); ?>
						<?php endif; ?>
					</a>
				</li>
				<li>
					<a href="<?php echo esc_url( $games_url ); ?>" title="<?php echo esc_attr__( 'Jogos', 'futebol365' ); ?>">
						<?php if ( $current_section === 'jogos' ) : ?>
							<strong><?php echo esc_html__( 'Jogos', 'futebol365' ); ?></strong>
						<?php else : ?>
							<?php echo esc_html__( 'Jogos', 'futebol365' ); ?>
						<?php endif; ?>
					</a>
				</li>
			</ul>
		</div>
		
	</div>

	<ul class="arbitro-links desktop-hide">
		<li>
			<a href="<?php echo esc_url( $base_url ); ?>" title="<?php echo esc_attr__( 'Resumo', 'futebol365' ); ?>">
				<?php if ( $current_section === 'resumo' ) : ?>
					<strong><?php echo esc_html__( 'Resumo', 'futebol365' ); ?></strong>
				<?php else : ?>
					<?php echo esc_html__( 'Resumo', 'futebol365' ); ?>
				<?php endif; ?>
			</a>
		</li>
		<li>
			<a href="<?php echo esc_url( $games_url ); ?>" title="<?php echo esc_attr__( 'Jogos', 'futebol365' ); ?>">
				<?php if ( $current_section === 'jogos' ) : ?>
					<strong><?php echo esc_html__( 'Jogos', 'futebol365' ); ?></strong>
				<?php else : ?>
					<?php echo esc_html__( 'Jogos', 'futebol365' ); ?>
				<?php endif; ?>
			</a>
		</li>
	</ul>
	<span class="extraspan-hide"></span>
</div>

<div class="arbitro-details">
	<?php if ( $photo_url !== '' ) : ?>
		<div class="thumb-img">
			<img src="<?php echo esc_url( $photo_url ); ?>" alt="<?php echo esc_attr( $shortname ); ?>" onerror="this.onerror=null;this.src='<?php echo esc_url( FB_THEME_URL . '/assets/images/no-image.jpg' ); ?>';" title="<?php echo esc_attr( $shortname ); ?>" width="100" height="100">
		</div>
	<?php endif; ?>
	

	<ul class="persion-info">
		<li>
			<strong><?php echo esc_html__( 'Nome', 'futebol365' ); ?>:</strong>
			<span><?php echo esc_html( $longname !== '' ? $longname : $shortname ); ?></span>
		</li>

		<?php if ( $birth_display !== '' ) : ?>
			<li>
				<strong><?php echo esc_html__( 'Data Nascimento', 'futebol365' ); ?>:</strong>
				<span><?php echo esc_html( $birth_display ); ?></span>
			</li>

			<?php if ( $is_deceased && $death_display !== '' ) : ?>
				<li>
					<span><?php
						/* translators: %s: date of death (d-m-Y) */
						echo esc_html( sprintf( __( 'Falecido em %s', 'futebol365' ), $death_display ) );
					?></span>
				</li>
			<?php elseif ( $age !== null ) : ?>
				<li>
					<strong><?php echo esc_html__( 'Idade', 'futebol365' ); ?>:</strong>
					<span><?php
						/* translators: %d: age in years */
						echo esc_html( sprintf( __( '%d anos', 'futebol365' ), (int) $age ) );
					?></span>
				</li>
			<?php endif; ?>
		<?php endif; ?>

		<?php if ( $naturality !== '' ) : ?>
			<li>
				<strong><?php echo esc_html__( 'Nascido em', 'futebol365' ); ?>:</strong>
				<span><?php echo esc_html( $naturality ); ?></span>
			</li>
		<?php endif; ?>

		<?php if ( $birth_place !== '' ) : ?>
			<li>
				<strong><?php echo esc_html__( 'Local Nascimento', 'futebol365' ); ?>:</strong>
				<span><?php echo esc_html( $birth_place ); ?></span>
			</li>
		<?php endif; ?>

		<?php if ( $nationality !== '' ) : ?>
			<li>
				<strong><?php echo esc_html__( 'Nacionalidade', 'futebol365' ); ?>:</strong>
				<span><?php echo esc_html( $nationality ); ?></span>
			</li>
		<?php endif; ?>

		<?php if ( ! empty( $referee['social_links'] ) && is_array( $referee['social_links'] ) ) : ?>
			<li class="social-row">
				<strong><?php echo esc_html__( 'Redes Sociais', 'futebol365' ); ?>:</strong>
				<span>
					<?php foreach ( $referee['social_links'] as $social ) : ?>
						<a class="<?php echo esc_attr( $social['icon'] ?? '' ); ?>"
						   target="_blank" rel="noopener"
						   title="<?php echo esc_attr( $social['label'] ?? '' ); ?>"
						   href="<?php echo esc_url( $social['url'] ?? '#' ); ?>"></a>
					<?php endforeach; ?>
				</span>
			</li>
		<?php endif; ?>
	</ul>
</div>
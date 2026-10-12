<?php
$post_id = $args['post_id'];
$current_section = $args['current_section'];
$sections = $args['sections'];
$base_url = trailingslashit( get_permalink( $post_id ) );
?>

<nav class="nav-main stadium-nav">
	<ul>
		<?php foreach ( $sections as $key => $section ) : ?>
			<li>
				<a class="<?php echo $current_section === $key ? 'active' : ''; ?>" href="<?php echo esc_url( $key === 'summary' ? $base_url : $base_url . $key . '/' ); ?>">
					<?php echo esc_html( $section['label'] ); ?>
				</a>
			</li>
		<?php endforeach; ?>
	</ul>
</nav>

<?php
$post_id =
	$args['post_id'] ?? 0;
$sections =
	$args['sections'] ?? [];
$current_section =
	$args['current_section'] ??
	'summary';
$base_url = trailingslashit(
	get_permalink($post_id)
);
?>
<?php if (! empty($sections)) : ?>
	<div
		class="nav-trigger"
		id="nav-trigger"
	>
		<span>
			<?php esc_html_e(
				'Menu',
				'f365'
			); ?>
		</span>
	</div>
	<nav
		class="nav-main"
		id="nav-main"
	>
		<ul class="stadium-tabs">
			<?php foreach (
				$sections as
				$key => $section
			) : ?>
				<?php
				$url = $base_url;
				if (
					! empty(
						$section['url']
					)
				) {
					$url = trailingslashit(
						$base_url .
						$section['url']
					);
				}
				$is_active =
					$current_section ===
					$key;
				?>
				<li
					class="<?php echo esc_attr(
						$is_active
						? 'active'
						: ''
					); ?>"
				>
					<a
						class="<?php echo esc_attr(
							$is_active
							? 'active'
							: ''
						); ?>"
						href="<?php echo esc_url($url); ?>"
					>
						<?php
						echo esc_html(
							$section['label']
						);
						?>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
	</nav>
	<nav
		class="nav-mobile"
		id="nav-mobile"
	></nav>
<?php endif; ?>

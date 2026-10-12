<div class="column-group vertical-gutters news-list">

		<div class="xlarge-15 large-20 medium-20 small-30 tiny-100 img-block">

			<a href="<?php the_permalink(); ?>">

				<?php if ( has_post_thumbnail() ) : ?>

					<?php the_post_thumbnail(
						'thumbnail',
						[
							'class' => 'search-thumb',
							'alt'   => get_the_title(),
							'title' => get_the_title(),
						]
					); ?>

				<?php else : ?>

					<img src="<?php echo esc_url( futebol365_fallback_image() ); ?>" alt="<?php echo esc_attr( get_the_title() ); ?>" width="120">

				<?php endif; ?>

			</a>

		</div>

		<div class="xlarge-85 large-80 medium-80 small-70 tiny-100 content-block">

			<div class="condensed-700 large quarter-bottom-space">
				<a href="<?php the_permalink(); ?>">
					<?php the_title(); ?>
				</a>
			</div>

			<div class="uppercase condensed-400 medium">
				<?php echo esc_html( get_the_date('d-m-Y') ); ?> · <?php echo esc_html( get_the_time('H:i') ); ?>
			</div>

		</div>

	</div>
	
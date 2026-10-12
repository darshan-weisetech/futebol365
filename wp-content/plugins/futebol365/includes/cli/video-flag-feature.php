<?php

if ( defined( 'WP_CLI' ) && WP_CLI ) {

	class F365_Assign_Featured_Videos_Command {

		/**
		 * Assign "featured" term to first 100 highlighted videos.
		 *
		 * ## EXAMPLES
		 *
		 *     wp f365 assign-featured-videos
		 */
		public function __invoke() {

			$taxonomy = 'f365_video_type';
			$term_slug = 'featured';
			$term_name = 'Featured';

			if ( ! taxonomy_exists( $taxonomy ) ) {
				WP_CLI::error( sprintf( 'Taxonomy "%s" does not exist.', $taxonomy ) );
			}

			// Create term if missing.
			$term = get_term_by( 'slug', $term_slug, $taxonomy );

			if ( ! $term ) {

				$result = wp_insert_term(
					$term_name,
					$taxonomy,
					[
						'slug' => $term_slug,
					]
				);

				if ( is_wp_error( $result ) ) {
					WP_CLI::error( $result->get_error_message() );
				}

				$term_id = (int) $result['term_id'];

				WP_CLI::success(
					sprintf(
						'Created term "%s" (ID %d)',
						$term_name,
						$term_id
					)
				);

			} else {

				$term_id = (int) $term->term_id;

				WP_CLI::log(
					sprintf(
						'Using existing term "%s" (ID %d)',
						$term_name,
						$term_id
					)
				);
			}

			global $wpdb;

			$video_ids = $wpdb->get_col(
				$wpdb->prepare(
					"
					SELECT p.ID
					FROM {$wpdb->posts} p
					INNER JOIN {$wpdb->postmeta} pm
						ON pm.post_id = p.ID
					WHERE p.post_type = %s
					AND p.post_status = 'publish'
					AND pm.meta_key = %s
					AND pm.meta_value = %s
					ORDER BY p.post_date DESC
					LIMIT 100
					",
					'f365_video',
					'highlight',
					'1'
				)
			);

			if ( empty( $video_ids ) ) {
				WP_CLI::warning( 'No highlighted videos found.' );
				return;
			}

			foreach ( $video_ids as $video_id ) {
				wp_set_object_terms(
					(int) $video_id,
					$term_id,
					$taxonomy,
					true
				);
			}

			WP_CLI::success(
				sprintf(
					'Assigned "%s" term to %d videos.',
					$term_slug,
					count( $video_ids )
				)
			);
		}
	}

	WP_CLI::add_command(
		'f365 assign-featured-videos',
		'F365_Assign_Featured_Videos_Command'
	);
}
<?php
/**
 * Runtime triggers.
 *
 * Each change records only the affected bucket(s); regeneration runs on `shutdown` after the
 * response is flushed. A single post change can touch: its own post bucket, the taxonomy
 * buckets of its terms (so term lastmod tracks the latest post, like Yoast), and its author
 * bucket. All de-duplicated per request.
 */
class SSG_Hooks {

	/** @var SSG_Generator */
	private $gen;

	private $dirty_posts   = array(); // post_type => [ bucket => true ]
	private $dirty_terms   = array(); // taxonomy  => [ bucket => true ]
	private $dirty_authors = array(); // bucket => true

	public function __construct( SSG_Generator $generator ) {
		$this->gen = $generator;

		add_action( 'transition_post_status', array( $this, 'on_transition' ), 10, 3 );
		add_action( 'save_post', array( $this, 'on_save' ), 10, 2 );
		add_action( 'before_delete_post', array( $this, 'on_delete' ), 10, 1 );

		add_action( 'created_term', array( $this, 'on_term' ), 10, 3 );
		add_action( 'edited_term', array( $this, 'on_term' ), 10, 3 );
		add_action( 'delete_term', array( $this, 'on_term' ), 10, 3 );

		add_action( 'profile_update', array( $this, 'on_user' ), 10, 1 );
		add_action( 'user_register', array( $this, 'on_user' ), 10, 1 );
		add_action( 'deleted_user', array( $this, 'on_user' ), 10, 1 );

		add_action( 'shutdown', array( $this, 'flush' ), 0 );

		add_filter( 'robots_txt', array( $this, 'robots_txt' ), 10, 2 );

		$settings = $this->gen->settings();
		if ( ! empty( $settings['disable_core'] ) ) {
			add_filter( 'wp_sitemaps_enabled', '__return_false' );
		}
	}

	private function tracks_post_type( $post_type ) {
		return in_array( $post_type, $this->gen->post_types(), true );
	}

	private function tracks_taxonomy( $taxonomy ) {
		return in_array( $taxonomy, $this->gen->taxonomies(), true );
	}

	private function mark_post( $post_id, $post_type ) {
		$this->dirty_posts[ $post_type ][ $this->gen->bucket_for_id( $post_id ) ] = true;
	}

	private function mark_author( $author_id ) {
		$author_id = (int) $author_id;
		if ( $author_id && $this->gen->authors_enabled() ) {
			$this->dirty_authors[ $this->gen->bucket_for_id( $author_id ) ] = true;
		}
	}

	/** Refresh the taxonomy buckets of every term attached to this post. */
	private function mark_post_terms( $post ) {
		foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
			if ( ! $this->tracks_taxonomy( $taxonomy ) ) {
				continue;
			}
			$term_ids = wp_get_post_terms( $post->ID, $taxonomy, array( 'fields' => 'ids' ) );
			if ( is_wp_error( $term_ids ) ) {
				continue;
			}
			foreach ( $term_ids as $term_id ) {
				$this->dirty_terms[ $taxonomy ][ $this->gen->bucket_for_id( $term_id ) ] = true;
			}
		}
	}

	private function mark_all_for_post( $post ) {
		$this->mark_post( $post->ID, $post->post_type );
		$this->mark_post_terms( $post );
		$this->mark_author( $post->post_author );
	}

	public function on_transition( $new_status, $old_status, $post ) {
		if ( ! $post instanceof WP_Post || ! $this->tracks_post_type( $post->post_type ) ) {
			return;
		}
		if ( 'publish' === $new_status || 'publish' === $old_status ) {
			$this->mark_all_for_post( $post );
		}
	}

	public function on_save( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! $post instanceof WP_Post || ! $this->tracks_post_type( $post->post_type ) ) {
			return;
		}
		if ( 'publish' === $post->post_status ) {
			$this->mark_all_for_post( $post );
		}
	}

	public function on_delete( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || ! $this->tracks_post_type( $post->post_type ) ) {
			return;
		}
		// Terms are still attached at before_delete_post, so we can refresh them too.
		$this->mark_all_for_post( $post );
	}

	public function on_term( $term_id, $tt_id, $taxonomy ) {
		if ( ! $this->tracks_taxonomy( $taxonomy ) ) {
			return;
		}
		$this->dirty_terms[ $taxonomy ][ $this->gen->bucket_for_id( $term_id ) ] = true;
	}

	public function on_user( $user_id ) {
		$this->mark_author( $user_id );
	}

	public function flush() {
		if ( empty( $this->dirty_posts ) && empty( $this->dirty_terms ) && empty( $this->dirty_authors ) ) {
			return;
		}

		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		}

		$posts   = $this->dirty_posts;
		$terms   = $this->dirty_terms;
		$authors = $this->dirty_authors;
		$this->dirty_posts   = array();
		$this->dirty_terms   = array();
		$this->dirty_authors = array();

		$this->gen->flush_dirty( $posts, $terms, $authors );
	}

	public function robots_txt( $output, $public ) {
		if ( $public ) {
			$output .= "\nSitemap: " . esc_url( $this->gen->index_url() ) . "\n";
		}
		return $output;
	}
}

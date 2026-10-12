<?php
/**
 * Futebol365 — Yoast breadcrumb trail for competition pages.
 *
 * Replicates, exactly, the breadcrumb hierarchy Django renders in
 * `competition/competition_detail.html` (inherited unchanged by every
 * competition section: resumo, jogos, classificação, estádios, vencedores, …,
 * because those templates only override `competition_content`, never the
 * `left_content` block that holds the breadcrumb).
 *
 * Django markup:
 *   <li><a href="{% url homepage %}">Home</a></li>
 *   {{ competition.federation_competition.federation.get_parent_breadcrumbs_competitions|safe }}
 *   <li><a href="{% url competition-index fed_comp.id %}?edition={{ edition }}">{{ competition.name }}</a></li>
 *   <li class="active">{{ competition.season }}</li>
 *
 * …where `get_parent_breadcrumbs_competitions()` recurses up the federation
 * parent chain (Federation.get_parent_breadcrumbs('competicoes')):
 *
 *   def get_parent_breadcrumbs(self, partial):
 *       if self.federation is None:
 *           return '<li><a href="{partial_url}">{shortname}</a></li>'
 *       return '{parent.get_parent_breadcrumbs(partial)} <li><a href="{partial_url}">{shortname}</a></li>'
 *
 * i.e. it emits every ancestor federation from the top-most down to the
 * competition's own federation, each linking to /federacao/{id}/competicoes/.
 *
 * Resulting trail (identical on resumo AND every section page):
 *   Home → [top federation → … → own federation] (each → competições page)
 *        → Competition name (→ resumo, ?edition=) → Season (active, no link)
 *
 * WordPress mapping:
 *   - federation parent chain  → f365_federation is a hierarchical CPT, so the
 *                                parent is the WP post_parent (get_post_ancestors).
 *   - federation crumb url     → f365_federation_url($id, 'competicoes')   (= get_partial_url)
 *   - competition name         → competition post title  (Django Competition.name)
 *   - competition crumb url    → f365_competition_url($id)  (resumo + ?edition=)
 *   - season                   → competition `season` meta
 *
 * Drop this file in the theme (e.g. inc/) and require it from functions.php,
 * or paste the function + add_filter into functions.php. `yoast_breadcrumb()`
 * keeps working unchanged.
 *
 * @package Futebol365
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'f365_competition_federation_chain_crumbs' ) ) {
	/**
	 * Build the federation ancestor crumbs (top-most → own federation), each
	 * linking to its "competições" page. Ports Federation.get_parent_breadcrumbs().
	 *
	 * @param int $federation_id f365_federation post id (the competition's own federation).
	 * @return array<int,array{text:string,url:string}>
	 */
	function f365_competition_federation_chain_crumbs( int $federation_id ): array {
		$crumbs = array();
		if ( ! $federation_id ) {
			return $crumbs;
		}

		// get_post_ancestors() returns closest-parent → top-most; reverse to get
		// top-most → parent, then append self for top-most → … → own federation.
		$ordered   = array_reverse( get_post_ancestors( $federation_id ) );
		$ordered[] = $federation_id;

		foreach ( $ordered as $fed_id ) {
			$fed_id    = (int) $fed_id;
			$shortname = (string) get_post_meta( $fed_id, 'shortname', true );
			if ( '' === $shortname ) {
				$shortname = get_the_title( $fed_id );
			}

			$crumbs[] = array(
				'text' => $shortname,
				'url'  => function_exists( 'f365_federation_url' )
					? f365_federation_url( $fed_id, 'competicoes' )
					: get_permalink( $fed_id ),
			);
		}

		return $crumbs;
	}
}

if ( ! function_exists( 'f365_competition_yoast_breadcrumb_links' ) ) {
	/**
	 * Replace Yoast's breadcrumb trail on competition pages with the Django trail.
	 *
	 * @param array $links Yoast breadcrumb links (each: ['text'=>, 'url'=>] | ['id'=>]).
	 * @return array
	 */
	function f365_competition_yoast_breadcrumb_links( $links ) {
		$object = get_queried_object();

		// Only competition pages (resumo + every section share the queried object).
		if ( ! ( $object instanceof WP_Post ) || 'f365_competition' !== $object->post_type ) {
			return $links;
		}

		$comp_id     = (int) $object->ID;
		$fed_comp_id = (int) get_post_meta( $comp_id, 'federation_competition', true );
		$fed_id      = $fed_comp_id ? (int) get_post_meta( $fed_comp_id, 'federation', true ) : 0;

		$season    = (string) get_post_meta( $comp_id, 'season', true );
		$comp_name = get_the_title( $comp_id ); // Django Competition.name.
		$comp_url  = function_exists( 'f365_competition_url' )
			? f365_competition_url( $comp_id )   // resumo, with ?edition= appended.
			: get_permalink( $comp_id );

		$trail = array();

		// 1. Home — preserve Yoast's configured home crumb when present.
		if ( ! empty( $links ) && is_array( $links[0] ) ) {
			$trail[] = $links[0];
		} else {
			$trail[] = array(
				'text' => __( 'Home', 'futebol365' ),
				'url'  => home_url( '/' ),
			);
		}

		// 2. Federation ancestor chain (top-most → own federation).
		foreach ( f365_competition_federation_chain_crumbs( $fed_id ) as $crumb ) {
			$trail[] = $crumb;
		}

		// 3. Competition name → resumo (?edition=).
		$trail[] = array(
			'text' => $comp_name,
			'url'  => $comp_url,
		);

		// 4. Season — current page (no url → Yoast renders it as the active crumb).
		if ( '' !== $season ) {
			$trail[] = array( 'text' => $season );
		}

		return $trail;
	}
}

add_filter( 'wpseo_breadcrumb_links', 'f365_competition_yoast_breadcrumb_links' );
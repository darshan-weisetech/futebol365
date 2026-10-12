<?php

/**
 * Register Multimedia CPT
 */
function futebol365_register_multimedia_post_type()
{
	$labels = array(

		'name'          => 'Multimedia',
		'singular_name' => 'Multimedia',
		'menu_name'     => 'Multimedia',
	);

	$args = array(
		'labels' => $labels,
		'public' => true,
		'menu_icon' => 'dashicons-format-video',
		'supports' => array(
			'title',
			'editor',
			'thumbnail',
			'excerpt',
			'author',
		),
		'taxonomies' => array(
			'post_tag',
		),
		'has_archive' => false,
		'rewrite' => false,
		'show_in_rest' => true,

	);

	register_post_type(
		'multimedia',
		$args
	);

	/* Multimedia Type Taxonomy*/

	$taxonomy_labels = array(
		'name'          => 'Multimedia Types',
		'singular_name' => 'Multimedia Type',
		'menu_name'     => 'Multimedia Types',
	);

	$taxonomy_args = array(
		'labels' => $taxonomy_labels,
		'public' => true,
		'hierarchical' => true,
		'show_admin_column' => true,
		'show_in_rest' => true,
		'meta_box_cb' => false,
		'rewrite' => array(
			'slug' => 'multimedia',
			'with_front' => false,
			'hierarchical' => true,
		),
	);
	register_taxonomy(
		'multimedia_type',
		array('multimedia'),
		$taxonomy_args
	);
	
	/* * Competicao CPT */

	register_post_type(
		'competicao',
		array(
			'label' => 'Competicao',
			'public' => true,
			'has_archive' => false,
			'rewrite' => array(
				'slug' => 'competicao',
			),
			'supports' => array(
				'title',
				'editor',
				'thumbnail',
				'excerpt',
				'author',
			),

			'taxonomies' => array(
				'category',
			),

			'show_in_rest' => true,
			'menu_position' => 20,
			'menu_icon' => 'dashicons-awards',
		)
	);

	/**
	 * Newsstand CPT
	 */
	register_post_type(

		'banca-de-jornais',

		array(

			'label' => 'Newsstand',
			'public' => true,
			'has_archive' => false,
			'rewrite' => array(
				'slug' => 'banca-de-jornais',
			),
			'supports' => array(
				'title',
				'editor',
				'thumbnail',
				'excerpt',
				'author',
			),

			'taxonomies' => array(
				'category',
			),

			'show_in_rest' => true,
			'menu_position' => 20,
			'menu_icon' => 'dashicons-text-page',

		)

	);

	/**
	 * Players CPT
	 */
	register_post_type(

		'jogador',

		array(

			'label' => 'Players',
			'public' => true,
			'has_archive' => true,
			'rewrite' => array(
				'slug' => 'jogador',
			),

			'supports' => array(
				'title',
				'editor',
				'thumbnail',
				'excerpt',
				'author',
			),


			'show_in_rest' => true,
			'menu_position' => 21,
			'menu_icon' => 'dashicons-groups',

		)

	);

	/**
	 * Estadio CPT
	 */
	$labels = [
		'name'               => _x('Estadios', 'post type general name', 'futebol365'),
		'singular_name'      => _x('Estadio', 'post type singular name', 'futebol365'),
		'menu_name'          => _x('Estadios', 'admin menu', 'futebol365'),
		'name_admin_bar'     => _x('Estadio', 'add new on admin bar', 'futebol365'),
		'add_new'            => _x('Añadir Nuevo', 'estadio', 'futebol365'),
		'add_new_item'       => __('Añadir Nuevo Estadio', 'futebol365'),
		'new_item'           => __('Nuevo Estadio', 'futebol365'),
		'edit_item'          => __('Editar Estadio', 'futebol365'),
		'view_item'          => __('Ver Estadio', 'futebol365'),
		'all_items'          => __('Todos los Estadios', 'futebol365'),
		'search_items'       => __('Buscar Estadios', 'futebol365'),
		'parent_item_colon'  => __('Estadios padre:', 'futebol365'),
		'not_found'          => __('No se encontraron estadios.', 'futebol365'),
		'not_found_in_trash' => __('No se encontraron estadios en la papelera.', 'futebol365')
	];

	register_post_type(
		'estadio',
		array(
			'labels'             => $labels,
			'description'        => __('Estadios.', 'futebol365'),
			'public'             => true,
			'publicly_queryable' => true,
			'show_ui'            => true,
			'show_in_menu'       => true,
			'query_var'          => true,
			'rewrite'            => ['slug' => 'estadio'],
			'capability_type'    => 'post',
			'has_archive'        => true,
			'hierarchical'       => false,
			'menu_position'      => null,
			'show_in_rest'       => true,
			'supports'           => ['title', 'editor', 'thumbnail']
		)
	);
}

add_action(
	'init',
	'futebol365_register_multimedia_post_type'
);

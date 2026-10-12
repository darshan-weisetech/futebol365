<?php
/**
 * Guias CPT registration and hooks
 *
 * @package Futebol365
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register Guias Custom Post Type
 */
function futebol365_register_guias_cpt() {
	$labels = [
		'name'               => _x( 'Guias', 'post type general name', 'futebol365' ),
		'singular_name'      => _x( 'Guia', 'post type singular name', 'futebol365' ),
		'menu_name'          => _x( 'Guias', 'admin menu', 'futebol365' ),
		'name_admin_bar'     => _x( 'Guia', 'add new on admin bar', 'futebol365' ),
		'add_new'            => _x( 'Adicionar Novo', 'guia', 'futebol365' ),
		'add_new_item'       => __( 'Adicionar Novo Guia', 'futebol365' ),
		'new_item'           => __( 'Novo Guia', 'futebol365' ),
		'edit_item'          => __( 'Editar Guia', 'futebol365' ),
		'view_item'          => __( 'Ver Guia', 'futebol365' ),
		'all_items'          => __( 'Todos os Guias', 'futebol365' ),
		'search_items'       => __( 'Buscar Guias', 'futebol365' ),
		'parent_item_colon'  => __( 'Guias pai:', 'futebol365' ),
		'not_found'          => __( 'Nenhum guia encontrado.', 'futebol365' ),
		'not_found_in_trash' => __( 'Nenhum guia encontrado no lixo.', 'futebol365' ),
	];

	$args = [
		'labels'             => $labels,
		'public'             => true,
		'publicly_queryable' => true,
		'show_ui'            => true,
		'show_in_menu'       => true,
		'query_var'          => true,
		'rewrite'            => [ 'slug' => 'guias' ],
		'capability_type'    => 'post',
		'has_archive'        => true,
		'hierarchical'       => false,
		'menu_position'      => 20,
		'show_in_rest'       => true,
		'menu_icon'          => 'dashicons-welcome-learn-more',
		'supports'           => [ 'title', 'editor', 'thumbnail', 'excerpt', 'author' ],
		'taxonomies'         => [ 'category' ],
	];

	register_post_type( 'guias', $args );
}
add_action( 'init', 'futebol365_register_guias_cpt' );

/**
 * Disable comments on Guias post type.
 */
add_filter( 'comments_open', function( $open, $post_id ) {
	if ( get_post_type( $post_id ) === 'guias' ) {
		return false;
	}
	return $open;
}, 10, 2 );

<?php

/**
 * Plugin Name: Current Day , Date , Year
 * Version:     1.0
 * Plugin URI:
 * Description: Current date, day, date and year showing via shortcode [cur_date], [cur_day], [cur_month], [cur_year]. This  shortcode also effect on title and meta date like meta title meta description.
 * Author:  Team Webnow (MS)
 * Author URI:
 * Text Domain:
 * Domain Path:
 * License:
 */

function cddy_format_current_date( $format ) {
	if ( function_exists( 'wp_date' ) ) {
		return wp_date( $format );
	}

	return date_i18n( $format );
}

add_shortcode(
	'cur_year',
	function () {
		return cddy_format_current_date( 'Y' );
	}
);

add_shortcode(
	'cur_month',
	function () {
		$cur_month = cddy_format_current_date( 'F' );

		return ucfirst( $cur_month );
	}
);

add_shortcode(
	'cur_day',
	function () {
		$cur_day = cddy_format_current_date( 'l' );

		return ucfirst( $cur_day );
	}
);

add_shortcode(
	'cur_date',
	function () {
		return cddy_format_current_date( 'd' );
	}
);

//add_filter( 'pre_get_document_title', 'mannu_meta_title_page' );

add_filter( 'wpseo_title', 'mannu_meta_title_page' );

add_filter( 'the_title', 'mannu_only_title_page' );

//add_filter( 'the_content', 'filter_wp_content' );

function mannu_only_title_page( $title )

{

	$title = do_shortcode($title);

	    return $title;

}

function mannu_meta_title_page( $title )

{

	$title = do_shortcode($title);

	return $title;

}



add_filter( 'wpseo_opengraph_title', 'og_change_title' );

function og_change_title( $title ) {

	$title = do_shortcode($title);

	    return $title;

}

add_filter( 'wpseo_schema_webpage', 'og_change_article' );

function og_change_article( $data ) {
	if(isset($data['name'])){
		$data['name'] = do_shortcode($data['name']);
	}
	if(isset($data['description'])){
		$data['description'] = do_shortcode($data['description']);
	}
	    return $data;

}

function add_yoast_opengraph_description( $str ) {

	$post_id = get_the_id();

	$str = get_post_meta($post_id, '_yoast_wpseo_metadesc',true);

	$str =  do_shortcode($str);

    return $str;

}

add_filter( 'wpseo_opengraph_desc', 'add_yoast_opengraph_description' );

function mannu_seo_description( $seo_desc ) {

  $seo_desc = do_shortcode($seo_desc);

  return $seo_desc;

}



add_filter( 'wpseo_metadesc', 'mannu_seo_description', 10, 1 );



add_filter( 'widget_text', 'shortcode_unautop',10);

add_filter( 'widget_text', 'do_shortcode',10);

add_filter( 'the_excerpt', 'shortcode_unautop',10);

add_filter( 'the_excerpt', 'do_shortcode',10);

add_filter( 'term_description', 'shortcode_unautop',10);

add_filter( 'term_description', 'do_shortcode' ,10);

add_filter( 'the_title', 'shortcode_unautop',10);

add_filter( 'the_title', 'do_shortcode' ,10);


add_filter(
	'rank_math/frontend/title',
	function ( $title ) {
		return do_shortcode( $title );}
);

// Activate shortcode function in Post Title.
add_filter( 'the_title', 'do_shortcode' );

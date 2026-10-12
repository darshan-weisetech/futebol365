<?php
/**
 * ACF extended & extra fields for native WordPress posts (News module).
 *
 * Consolidates all news-related fields (metadata, CPT relationships,
 * flags, SEO fields, and repeater schedules) into a single clean,
 * tabbed field group for easy backend maintenance.
 *
 * Django: News → WP post type 'post'
 */

defined( 'ABSPATH' ) || exit;

add_action( 'acf/init', 'f365_acf_news_extended' );

function f365_acf_news_extended(): void {

    acf_add_local_field_group( [
        'key'      => 'group_f365_news',
        'title'    => 'News Details & SEO',
        'location' => [ [ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'post' ] ] ],
        'fields'   => [

            [
                'key'   => 'field_news_tab_content',
                'label' => 'Content & Metadata',
                'type'  => 'tab',
            ],
            [
                'key'           => 'field_n_views',
                'label'         => 'Views',
                'name'          => 'views',
                'type'          => 'number',
                'readonly'     => 1,
                'default_value' => 0,
            ],

            // ═══════════════════════════════════════════════════════════════
            // TAB 2: TEMPORARY MAPPINGS
            // ═══════════════════════════════════════════════════════════════
            [
                'key'   => 'field_news_tab_temp_tax',
                'label' => 'Competition Temp data',
                'type'  => 'tab',
            ],
            [
                'key'          => 'field_news_competition_tmp',
                'label'        => 'Competition Temp',
                'name'         => 'competition_tmp',
                'type'         => 'text',
                'instructions' => 'competition string imported from Django.',
                'readonly'     => 1,
            ],
            [
                'key'          => 'field_news_season_tmp',
                'label'        => 'Season Temp',
                'name'         => 'season_tmp',
                'type'         => 'text',
                'instructions' => 'Temporary season string imported from Django.',
                'readonly'     => 1,
            ],

            // ═══════════════════════════════════════════════════════════════
            // TAB 3: RELATIONSHIPS
            // ═══════════════════════════════════════════════════════════════
            [
                'key'   => 'field_news_tab_relations',
                'label' => 'CPT Relationships',
                'type'  => 'tab',
            ],
            [
                'key'           => 'field_n_gallery',
                'label'         => 'Photo Gallery',
                'name'          => 'photogallery',
                'type'          => 'post_object',
                'post_type'     => [ 'f365_gallery' ],
                'return_format' => 'id',
                'required'      => 0,
            ],
            [
                'key'           => 'field_news_video_embed',
                'label'         => 'Video Embed Relation',
                'name'          => 'video_embed',
                'type'          => 'post_object',
                'post_type'     => [ 'f365_video' ],
                'return_format' => 'id',
                'instructions'  => 'Linked Video Embed post.',
                'required'      => 0,
            ],
            [
                'key'          => 'field_n_embed_video',
                'label'        => 'Embed Video URL',
                'name'         => 'embed_video',
                'type'         => 'url',
                'instructions' => 'Direct external video URL (YouTube, Vimeo, etc.).',
            ],

            // ═══════════════════════════════════════════════════════════════
            // TAB 4: EXTERNAL LINKS
            // ═══════════════════════════════════════════════════════════════
            [
                'key'   => 'field_news_tab_links',
                'label' => 'Links & Comments',
                'type'  => 'tab',
            ],
            [
                'key'   => 'field_n_gen_link_title',
                'label' => 'Generic Link Title',
                'name'  => 'generic_link_title',
                'type'  => 'text',
            ],
            [
                'key'   => 'field_n_gen_link_url',
                'label' => 'Generic Link URL',
                'name'  => 'generic_link_url',
                'type'  => 'text',
            ],

            // ═══════════════════════════════════════════════════════════════
            // TAB 5: FLAGS
            // ═══════════════════════════════════════════════════════════════
            [
                'key'   => 'field_news_tab_flags',
                'label' => 'Flags',
                'type'  => 'tab',
            ],
            [
                'key'           => 'field_n_display_matchday',
                'label'         => 'Display Matchday',
                'name'          => 'display_matchday',
                'type'          => 'true_false',
                'default_value' => 0,
            ],
            [
                'key'           => 'field_n_highlight',
                'label'         => 'Highlight',
                'name'          => 'highlight',
                'type'          => 'true_false',
                'default_value' => 0,
            ],
            [
                'key'           => 'field_n_news_aggregators',
                'label'         => 'Allow Aggregators',
                'name'          => 'news_aggregators',
                'type'          => 'true_false',
                'default_value' => 0,
            ],
            [
                'key'           => 'field_n_social_sharing',
                'label'         => 'Social Sharing',
                'name'          => 'social_sharing',
                'type'          => 'true_false',
                'default_value' => 0,
            ],
            [
                'key'           => 'field_n_social_sharing_photo',
                'label'         => 'Sharing With Photo',
                'name'          => 'social_sharing_with_photo',
                'type'          => 'true_false',
                'default_value' => 0,
            ],
            [
                'key'           => 'field_n_related_news',
                'label'         => 'Show Related News',
                'name'          => 'related_news',
                'type'          => 'true_false',
                'default_value' => 0,
            ],


           
        ],
    ] );

}

<?php
if( function_exists('acf_add_local_field_group') ):

    acf_add_local_field_group( array(
        'key'    => 'group_footer_menu_block',
        'title'  => 'Block: Footer Column Menus',
        'fields' => array(
            array(
                'key'          => 'field_block_select_menus',
                'label'        => 'Select Menus',
                'name'         => 'block_select_menus', 
                'type'         => 'repeater',
                'layout'       => 'table',
                'button_label' => __( 'Add New Menu Group', 'futebol365' ),
                'sub_fields'   => array(
                    array(
                        'key'   => 'field_block_menu_title',
                        'label' => 'Add Menu Title',
                        'name'  => 'add_menu_title',
                        'type'  => 'text',
                    ),
                    array(
                        'key'           => 'field_block_menu_assign',
                        'label'         => 'Assign Menu',
                        'name'          => 'footer_menu_assign',
                        'type'          => 'select',
                        'choices'       => function_exists('futebol365_get_menus') ? futebol365_get_menus() : array(),
                        'return_format' => 'value',
                    ),
                ),
            ),
        ),
        'location' => array(
            array(
                array(
                    'param'    => 'block',
                    'operator' => '==',
                    'value'    => 'acf/footer-column-menus', 
                ),
            ),
        ),
    ) );

    /** Header options */
    acf_add_local_field_group(array(
        'key' => 'group_6a07198f0de60',
        'title' => 'Header Settings',
        'fields' => array(
            array(
                'key' => 'field_social_links',
                'label' => 'Social Links',
                'name' => 'social_links',
                'type' => 'repeater',
                'layout' => 'table',
                'button_label' => 'Add Social Link',
                'sub_fields' => array(

                    array(
                        'key' => 'field_social_platform',
                        'label' => 'Platform',
                        'name' => 'social_platform',
                        'type' => 'select',
                        'choices' => array(
                            'facebook'  => 'Facebook',
                            'twitter'   => 'X / Twitter',
                            'instagram' => 'Instagram',
                            'threads'   => 'Threads',
                            'youtube'   => 'YouTube',
                            'tiktok'    => 'TikTok',
                            'whatsapp'  => 'WhatsApp',
                            'telegram'  => 'Telegram',
                            'linkedin'  => 'LinkedIn',
                            'rss'       => 'RSS',
                        ),
                        'default_value' => false,
                        'return_format' => 'value',
                        'ui' => 1,
                    ),

                    array(
                        'key' => 'field_social_url',
                        'label' => 'Social URL',
                        'name' => 'social_url',
                        'type' => 'link',
                    ),
                
                ),
            ),
            array(
                'key' => 'field_6a0aa55fe3f28',
                'label' => 'Heading',
                'name' => 'offer_heading',
                'type' => 'text',
                'instructions' => '',
                'required' => 0,
                'conditional_logic' => 0,
                'wrapper' => array(
                    'width' => '33',
                    'class' => '',
                    'id' => '',
                ),
                'default_value' => '',
                'placeholder' => '',
                'prepend' => '',
                'append' => '',
                'maxlength' => '',
            ),
            array(
                'key' => 'field_6a0aa5d6e3f29',
                'label' => 'SubHeading',
                'name' => 'offer_sub_heading',
                'type' => 'text',
                'instructions' => '',
                'required' => 0,
                'conditional_logic' => 0,
                'wrapper' => array(
                    'width' => '33',
                    'class' => '',
                    'id' => '',
                ),
                'default_value' => '',
                'placeholder' => '',
                'prepend' => '',
                'append' => '',
                'maxlength' => '',
            ),
            array(
                'key' => 'field_6a0aa5e7e3f2a',
                'label' => 'Link',
                'name' => 'offer_link',
                'type' => 'link',
                'instructions' => '',
                'required' => 0,
                'conditional_logic' => 0,
                'wrapper' => array(
                    'width' => '33',
                    'class' => '',
                    'id' => '',
                ),
                'return_format' => 'array',
            ),
            array(
                'key' => 'field_disable_offer_widget_globally',
                'label' => 'Want to hide offer widget?',
                'name' => 'want_to_hide_offer_widget_globally',
                'type' => 'checkbox',
                'instructions' => '',
                'required' => 0,
                'conditional_logic' => 0,
                'choices' => array(
                    'Yes' => 'Yes',
                ),
                'allow_custom' => 0,
                'default_value' => array(
                ),
                'layout' => 'vertical',
                'toggle' => 0,
                'return_format' => 'value',
                'save_custom' => 0,
            ),
        ),
        'location' => array(
            array(
                array(
                    'param' => 'options_page',
                    'operator' => '==',
                    'value' => 'acf-options-header',
                ),
            ),
        ),
        'menu_order' => 0,
        'position' => 'normal',
        'style' => 'default',
        'label_placement' => 'top',
        'instruction_placement' => 'label',
        'hide_on_screen' => '',
        'active' => true,
        'description' => '',
        'show_in_rest' => 0,
    ));
	
    /** Field for add image in menu */
    acf_add_local_field_group(array(
        'key' => 'group_6a06eb4777d11',
        'title' => 'Menu Group',
        'fields' => array(
            array(
                'key' => 'field_6a06eb546e33a',
                'label' => 'Upload Flag',
                'name' => 'upload_flag',
                'type' => 'image',
                'instructions' => '',
                'required' => 0,
                'conditional_logic' => 0,
                'return_format' => 'id',
                'preview_size' => 'medium',
                'library' => 'all',
            ),
        ),
        'location' => array(
            array(
                array(
                    'param' => 'nav_menu_item',
                    'operator' => '==',
                    'value' => 'all',
                ),
            ),
        ),
        'menu_order' => 0,
        'position' => 'normal',
        'style' => 'default',
        'label_placement' => 'top',
        'instruction_placement' => 'label',
        'hide_on_screen' => '',
        'active' => true,
        'description' => '',
        'show_in_rest' => 0,
    ));

    /** Disable Offer Widget (after header) */
    acf_add_local_field_group(array(
        'key' => 'group_6a0aa614e47c4',
        'title' => 'Disable Offer Widget',
        'fields' => array(
            array(
                'key'   => 'field_disable_offer_widget_label',
                'label' => 'Block: Disable Offer Widget',
                'name'  => '',
                'type'  => 'message',
                'new_lines' => 'wpautop',
                'esc_html'  => 0,
            ),
            array(
                'key' => 'field_6b1bb66af4g39',
                'label' => 'Promo Heading',   
                'name' => 'promo_heading',       
                'type' => 'text',
                'instructions' => 'Enter the main heading for this promotion.',
                'required' => 0,
                'conditional_logic' => 0,
                'wrapper' => array(
                    'width' => '33',
                    'class' => '',
                    'id' => '',
                ),
                'default_value' => '',
                'placeholder' => 'e.g., Limited Time Offer',
                'prepend' => '',
                'append' => '',
                'maxlength' => '',
            ),
            array(
                'key' => 'field_6b1bb67bf4g40',
                'label' => 'Promo SubHeading', 
                'name' => 'promo_sub_heading',  
                'type' => 'text',
                'instructions' => 'Enter a brief subheading.',
                'required' => 0,
                'conditional_logic' => 0,
                'wrapper' => array(
                    'width' => '33',
                    'class' => '',
                    'id' => '',
                ),
                'default_value' => '',
                'placeholder' => 'e.g., Get 20% off all items',
                'prepend' => '',
                'append' => '',
                'maxlength' => '',
            ),
            array(
                'key' => 'field_6b1bb68cf4g41',
                'label' => 'Promo Link', 
                'name' => 'promo_link',   
                'type' => 'link',
                'instructions' => 'Select or paste the destination URL.',
                'required' => 0,
                'conditional_logic' => 0,
                'wrapper' => array(
                    'width' => '33',
                    'class' => '',
                    'id' => '',
                ),
                'return_format' => 'array',
            ),
            array(
                'key' => 'field_6a0aa63eeb39d',
                'label' => 'Want to hide offer widget for this page?',
                'name' => 'want_to_hide_offer_widget_for_this_page',
                'type' => 'checkbox',
                'instructions' => '',
                'required' => 0,
                'conditional_logic' => 0,
                'choices' => array(
                    'Yes' => 'Yes',
                ),
                'allow_custom' => 0,
                'default_value' => array(
                ),
                'layout' => 'vertical',
                'toggle' => 0,
                'return_format' => 'value',
                'save_custom' => 0,
            ),
        ),
        'location' => array(
            array(
                array(
                    'param'    => 'post_type',
                    'operator' => '==',
                    'value'    => 'post',
                ),
            ),
            array(
                array(
                    'param'    => 'post_type',
                    'operator' => '==',
                    'value'    => 'page',
                ),
            ),
            array(
                array(
                    'param'    => 'post_type',
                    'operator' => '==',
                    'value'    => 'f365_team',
                ),
            ),
            array(
                array(
                    'param'    => 'post_type',
                    'operator' => '==',
                    'value'    => 'f365_player',
                ),
            ),
            array(
                array(
                    'param'    => 'post_type',
                    'operator' => '==',
                    'value'    => 'f365_coach',
                ),
            ),
            array(
                array(
                    'param'    => 'post_type',
                    'operator' => '==',
                    'value'    => 'f365_president',
                ),
            ),
            array(
                array(
                    'param'    => 'post_type',
                    'operator' => '==',
                    'value'    => 'f365_referee',
                ),
            ),
            array(
                array(
                    'param'    => 'post_type',
                    'operator' => '==',
                    'value'    => 'f365_match',
                ),
            ),
             array(
                array(
                    'param'    => 'post_type',
                    'operator' => '==',
                    'value'    => 'sondagem',
                ),
            ),
            array(
                array(
                    'param'    => 'post_type',
                    'operator' => '==',
                    'value'    => 'f365_stadium',
                ),
            ),
            array(
                array(
                    'param'    => 'post_type',
                    'operator' => '==',
                    'value'    => 'f365_transfer',
                ),
            ),
            array(
                array(
                    'param'    => 'post_type',
                    'operator' => '==',
                    'value'    => 'f365_video',
                ),
            ),
            array(
                array(
                    'param'    => 'post_type',
                    'operator' => '==',
                    'value'    => 'f365_gallery',
                ),
            ),
            array(
                array(
                    'param'    => 'post_type',
                    'operator' => '==',
                    'value'    => 'f365_newsstand',
                ),
            ),
            array(
                array(
                    'param'    => 'post_type',
                    'operator' => '==',
                    'value'    => 'f365_livestream',
                ),
            ),
            array(
                array(
                    'param'    => 'post_type',
                    'operator' => '==',
                    'value'    => 'f365_ranking',
                ),
            ),
            array(
                array(
                    'param'    => 'post_type',
                    'operator' => '==',
                    'value'    => 'f365_competition',
                ),
            ),
        ),
        'menu_order' => 0,
        'position' => 'normal',
        'style' => 'default',
        'label_placement' => 'top',
        'instruction_placement' => 'label',
        'hide_on_screen' => '',
        'active' => true,
        'description' => '',
        'show_in_rest' => 0,
    ));

    /** Category posts block with half layout */
    acf_add_local_field_group(array(
        'key' => 'group_6a0ae36535a35',
        'title' => 'Posts by Category',
        'fields' => array(
            array(
                'key'   => 'field_category_posts_block_label',
                'label' => 'Block: Category Posts Block',
                'name'  => '',
                'type'  => 'message',
                'new_lines' => 'wpautop',
                'esc_html'  => 0,
            ),
            array(
                'key' => 'field_6a0ae3b5095a5',
                'label' => 'Add Title',
                'name' => 'add_title',
                'type' => 'text',
                'instructions' => '',
                'required' => 0,
                'conditional_logic' => 0,
                'wrapper' => array(
                    'width' => '',
                    'class' => '',
                    'id' => '',
                ),
                'default_value' => '',
                'placeholder' => '',
                'prepend' => '',
                'append' => '',
                'maxlength' => '',
            ),

            array(
                'key' => 'field_posts_count',
                'label' => 'Number of Posts',
                'name' => 'posts_count',
                'type' => 'number',
                'default_value' => 4,
                'min' => 4,
            ),
            array(
                'key' => 'field_6a0ae41a095a7',
                'label' => 'Select Color',
                'name' => 'select_color',
                'type' => 'color_picker',
                'instructions' => '',
                'required' => 0,
                'conditional_logic' => 0,
                'wrapper' => array(
                    'width' => '50',
                    'class' => '',
                    'id' => '',
                ),
                'default_value' => '',
                'enable_opacity' => 0,
                'return_format' => 'string',
            ),
            array(
                'key' => 'field_block_category',
                'label' => 'Select Category',
                'name' => 'block_category',
                'type' => 'taxonomy',
                'taxonomy' => 'category',
                'field_type' => 'select',
                'return_format' => 'id',
                'allow_null' => 1,
                'ajax' => true,
                'ui' => 1,
            ),
            array(
                'key' => 'field_6a0ae3cd095a6',
                'label' => 'Half Layout',
                'name' => 'half_layout',
                'type' => 'checkbox',
                'instructions' => 'Check if you want to show the half layout with second category posts.',
                'required' => 0,
                'conditional_logic' => 0,
                'wrapper' => array(
                    'width' => '50',
                    'class' => '',
                    'id' => '',
                ),
                'choices' => array(
                    'yes' => 'yes',
                ),
                'allow_custom' => 0,
                'default_value' => array(
                ),
                'layout' => 'vertical',
                'toggle' => 0,
                'return_format' => 'value',
                'save_custom' => 0,
            ),
            array(
                'key' => 'field_6a0ae3b5095a5_second',
                'label' => 'Add Title For Second Layout',
                'name' => 'add_title_second',
                'type' => 'text',
                'instructions' => '',
                'required' => 0,
                'conditional_logic' => array(
                    array(
                        array(
                            'field' => 'field_6a0ae3cd095a6',
                            'operator' => '==',
                            'value' => 'yes',
                        ),
                    ),
                ),
            ),
            array(
                'key' => 'field_posts_count_second',
                'label' => 'Number of Posts for Second Layout',
                'name' => 'posts_count_Second',
                'type' => 'number',
                'default_value' => 4,
                'min' => 4,
                'conditional_logic' => array(
                    array(
                        array(
                            'field' => 'field_6a0ae3cd095a6',
                            'operator' => '==',
                            'value' => 'yes',
                        ),
                    ),
                ),
            ),
            array(
                'key' => 'field_6a0ae41a095a7_second',
                'label' => 'Select Color For Second Layout',
                'name' => 'select_color_second',
                'type' => 'color_picker',
                'instructions' => '',
                'required' => 0,
                'conditional_logic' => array(
                    array(
                        array(
                            'field' => 'field_6a0ae3cd095a6',
                            'operator' => '==',
                            'value' => 'yes',
                        ),
                    ),
                ),
                'wrapper' => array(
                    'width' => '50',
                    'class' => '',
                    'id' => '',
                ),
                'default_value' => '',
                'enable_opacity' => 0,
                'return_format' => 'string',
            ),
            array(
                'key' => 'field_block_category_second',
                'label' => 'Select Category For Second Layout',
                'name' => 'block_category_second',
                'type' => 'taxonomy',
                'taxonomy' => 'category',
                'field_type' => 'select',
                'return_format' => 'id',
                'allow_null' => 1,
                'ajax' => true,
                'ui' => 1,
                'conditional_logic' => array(
                    array(
                        array(
                            'field' => 'field_6a0ae3cd095a6',
                            'operator' => '==',
                            'value' => 'yes',
                        ),
                    ),
                ),
            ),
        ),
        'location' => array(
            array(
                array(
                    'param' => 'block',
                    'operator' => '==',
                    'value' => 'acf/futebol365-category-posts-block',
                ),
            ),
        ),
        'menu_order' => 0,
        'position' => 'normal',
        'style' => 'default',
        'label_placement' => 'top',
        'instruction_placement' => 'label',
        'hide_on_screen' => '',
        'active' => true,
        'description' => '',
        'show_in_rest' => 0,
    ));

    /** Main post Block */
    acf_add_local_field_group(array(
        'key' => 'group_main_posts_block',
        'title' => __('Main Posts Block','futebol365'),
        'fields' => array(
            array(
                'key'   => 'field_main_posts_block_label',
                'label' => 'Block: Main Posts Block',
                'name'  => '',
                'type'  => 'message',
                'new_lines' => 'wpautop',
                'esc_html'  => 0,
            ),
            array(
                'key' => 'field_main_posts_count',
                'label' => 'Number of Posts',
                'name' => 'main_posts_count',
                'type' => 'number',
                'default_value' => 5,
            ),
            array(
                'key' => 'field_main_block_category',
                'label' => __('Select Posts', 'futebol365'),
                'name' => 'select_main_posts',
                'type' => 'relationship',
                'post_type' => ['post'],
                'filters' => array('search'),
                'return_format' => 'object',
                'post_status' => array('publish'),
                'ui' => 1,
                'lazy' => true,
                'ajax' => true,
            ),
        ),
        'location' => array(
            array(
                array(
                    'param' => 'block',
                    'operator' => '==',
                    'value' => 'acf/futebol365-main-posts-block',
                ),
            ),
        ),
        'menu_order' => 0,
        'position' => 'normal',
        'style' => 'default',
        'label_placement' => 'top',
        'instruction_placement' => 'label',
        'hide_on_screen' => '',
        'active' => true,
        'description' => '',
        'show_in_rest' => 0,
    ));

    /** Newsstand posts block */
    acf_add_local_field_group(array(
        'key' => 'group_newsstand_posts_block',
        'title' => __('newsstand Posts Block','futebol365'),
        'fields' => array(
            array(
                'key'   => 'field_newsstand_posts_block_label',
                'label' => 'Block: Newsstand Posts Block',
                'name'  => '',
                'type'  => 'message',
                'new_lines' => 'wpautop',
                'esc_html'  => 0,
            ),
            array(
                'key' => 'field_newsstand_section_title',
                'label' => 'Add Title',
                'name' => 'newsstand_add_title',
                'type' => 'text',
            ),
            array(
                'key' => 'field_newsstand_section_link',
                'label' => 'Add Link',
                'name' => 'newsstand_add_link',
                'type' => 'link',
            ),
            array(
                'key' => 'field_newsstand_posts_count',
                'label' => 'Number of Posts',
                'name' => 'newsstand_posts_count',
                'type' => 'number',
                'default_value' => 3,
            ),
            array(
                'key' => 'field_newsstand_color_for_section',
                'label' => 'Select Color',
                'name' => 'select_color_newsstand_section',
                'type' => 'color_picker',
                'return_format' => 'string',
            ),
            array(
                'key' => 'field_newsstand_block_category',
                'label' => __('Select Posts', 'futebol365'),
                'name' => 'select_newsstand_posts',
                'type' => 'relationship',
                'post_type' => ['f365_newsstand'],
                'filters' => array('search'),
                'return_format' => 'object',
                'post_status' => array('publish'),
                'ui' => 1,
                'lazy' => true,
                'ajax' => true,
            ),
        ),
        'location' => array(
            array(
                array(
                    'param' => 'block',
                    'operator' => '==',
                    'value' => 'acf/futebol365-newsstand-posts-block',
                ),
            ),
        ),
        'menu_order' => 0,
        'position' => 'normal',
        'style' => 'default',
        'label_placement' => 'top',
        'instruction_placement' => 'label',
        'hide_on_screen' => '',
        'active' => true,
        'description' => '',
        'show_in_rest' => 0,
    ));

    /** showing block for video posts */
    acf_add_local_field_group(array(
        'key' => 'group_video_posts_block',
        'title' => __('video Posts Block','futebol365'),
        'fields' => array(
            array(
                'key'   => 'field_video_posts_block_label',
                'label' => 'Block: Video Posts Block',
                'name'  => '',
                'type'  => 'message',
                'new_lines' => 'wpautop',
                'esc_html'  => 0,
            ),
            array(
                'key' => 'field_video_section_title',
                'label' => 'Add Title',
                'name' => 'video_add_title',
                'type' => 'text',
            ),
            array(
                'key' => 'field_video_section_link',
                'label' => 'Add Link',
                'name' => 'video_add_link',
                'type' => 'link',
            ),
            array(
                'key' => 'field_color_for_video_section',
                'label' => 'Select Color',
                'name' => 'select_color_video_section',
                'type' => 'color_picker',
                'return_format' => 'string',
            ),
            array(
                'key' => 'field_video_block_category',
                'label' => __('Select Posts', 'futebol365'),
                'name' => 'select_video_posts',
                'type' => 'relationship',
                'post_type' => ['f365_video'],
                'filters' => array('search'),
                'return_format' => 'ids',
                'post_status' => array('publish'),
                'ui' => 1,
                'lazy' => true,
                'ajax' => true,
                'max' => 5,
            ),

        ),
        'location' => array(
            array(
                array(
                    'param' => 'block',
                    'operator' => '==',
                    'value' => 'acf/futebol365-video-posts-block',
                ),
            ),
        ),
        'menu_order' => 0,
        'position' => 'normal',
        'style' => 'default',
        'label_placement' => 'top',
        'instruction_placement' => 'label',
        'hide_on_screen' => '',
        'active' => true,
        'description' => '',
        'show_in_rest' => 0,
    ));
    
    /** block for showing competicao posts */
    acf_add_local_field_group(array(
        'key' => 'group_competicao_posts_block',
        'title' => __('Competicao Posts Block','futebol365'),
        'fields' => array(
            array(
                'key'   => 'field_Competicao_block_label',
                'label' => 'Block: Competicao Posts Block',
                'name'  => '',
                'type'  => 'message',
                'new_lines' => 'wpautop',
                'esc_html'  => 0,
            ),
            array(
                'key' => 'field_Competicao_section_title',
                'label' => 'Add Title',
                'name' => 'competicao_add_title',
                'type' => 'text',
            ),
            array(
                'key' => 'field_Competicao_section_link',
                'label' => 'Add Link',
                'name' => 'competicao_add_link',
                'type' => 'link',
            ),
            array(
                'key' => 'field_color_for_Competicao_section',
                'label' => 'Select Color',
                'name' => 'select_color_competicao_section',
                'type' => 'color_picker',
                'return_format' => 'string',
            ),
            array(
                'key' => 'field_Competicao_block_category',
                'label' => __('Select Posts', 'futebol365'),
                'name' => 'select_competicao_posts',
                'type' => 'relationship',
                'post_type' => ['f365_competition'],
                'filters' => array('search'),
                'return_format' => 'object',
                'post_status' => array('publish'),
                'ui' => 1,
                'lazy' => true,
                'ajax' => true,
                'max' => 10,
            ),

        ),
        'location' => array(
            array(
                array(
                    'param' => 'block',
                    'operator' => '==',
                    'value' => 'acf/futebol365-competicao-posts-block',
                ),
            ),
        ),
        'menu_order' => 0,
        'position' => 'normal',
        'style' => 'default',
        'label_placement' => 'top',
        'instruction_placement' => 'label',
        'hide_on_screen' => '',
        'active' => true,
        'description' => '',
        'show_in_rest' => 0,
    ));

    /** Latest news block for widget area */
    acf_add_local_field_group(array(
        'key' => 'group_latest-news_block',
        'title' => __('latest-news Posts Block','futebol365'),
        'fields' => array(
            array(
                'key'   => 'field_latest-news_block_label',
                'label' => 'Block: Latest News Block',
                'name'  => '',
                'type'  => 'message',
                'new_lines' => 'wpautop',
                'esc_html'  => 0,
            ),
            array(
                'key' => 'field_latest-news_section_title',
                'label' => 'Add Title',
                'name' => 'latest-news_add_title',
                'type' => 'text',
            ),
            array(
                'key' => 'field_color_for_latest-news_section',
                'label' => 'Select Color',
                'name' => 'select_color_latest-news_section',
                'type' => 'color_picker',
                'return_format' => 'string',
            ),
            array(
                'key' => 'field_latest-news_section_link',
                'label' => 'Add Link',
                'name' => 'latest-news_add_link',
                'type' => 'link',
            ),
            array(
                'key' => 'field_latest-news_posts',
                'label' => __('Select Posts', 'futebol365'),
                'name' => 'select_latest-news_posts',
                'type' => 'relationship',
                'post_type' => ['multimedia'],
                'filters' => array('search'),
                'return_format' => 'object',
                'post_status' => array('publish'),
                'ui' => 1,
                'lazy' => true,
                'ajax' => true,
                'max' => 5,
            ),

        ),
        'location' => array(
            array(
                array(
                    'param' => 'block',
                    'operator' => '==',
                    'value' => 'acf/futebol365-latest-news-block',
                ),
            ),
        ),
        'menu_order' => 0,
        'position' => 'normal',
        'style' => 'default',
        'label_placement' => 'top',
        'instruction_placement' => 'label',
        'hide_on_screen' => '',
        'active' => true,
        'description' => '',
        'show_in_rest' => 0,
    ));

    /** Global setting for Paeg template */
    acf_add_local_field_group(array(
        'key' => 'group_6a0c67e5cc504',
        'title' => 'Add tabs for banner area for page',
        'fields' => array(
            array(
                'key' => 'field_site_settings_tab',
                'label' => 'Site settings',
                'name' => '',
                'type' => 'tab',
                'instructions' => '',
                'required' => 0,
                'conditional_logic' => 0,
                'placement' => 'top',
                'endpoint' => 0,
            ),
            array(
                'key'   => 'field_6a0c67f71553c',
                'label' => 'Block: Banner Area For Page',
                'name'  => '',
                'type'  => 'message',
                'new_lines' => 'wpautop',
                'esc_html'  => 0,
            ),
            array(
                'key' => 'field_6a0c680f1553d',
                'label' => 'Background Image',
                'name' => 'bg_image',
                'type' => 'image',
                'return_format' => 'array',
                'preview_size' => 'medium',
                'library' => 'all',
            ),
            array(
                'key' => 'field_6a0c68011553b',
                'label' => 'Add pages',
                'name' => 'add_pages',
                'type' => 'repeater',
                'layout' => 'table',
                'button_label' => '',
                'sub_fields' => array(
                    array(
                        'key' => 'field_6a0c680f1553c',
                        'label' => 'Select page',
                        'name' => 'select_page',
                        'type' => 'link',
                        'return_format' => 'array',
                    ),
                ),
            ),
            array(
                'key' => 'field_poll_settings_tab',
                'label' => 'Poll Settings',
                'name' => '',
                'type' => 'tab',
                'instructions' => '',
                'required' => 0,
                'conditional_logic' => 0,
                'placement' => 'top',
                'endpoint' => 0,
            ),
            array(
                'key' => 'field_img_for_poll_form',
                'label' => 'Image for the poll form',
                'name' => 'poll_image',
                'type' => 'image',
                'return_format' => 'array',
                'preview_size' => 'medium',
                'library' => 'all',
            ),
            /** Federation Settings Tab */
            array(
                'key' => 'field_federation_settings_tab',
                'label' => 'Federation Settings',
                'name' => '',
                'type' => 'tab',
                'instructions' => '',
                'required' => 0,
                'conditional_logic' => 0,
                'placement' => 'top',
                'endpoint' => 0,
            ),
            array(
                'key' => 'field_img_for_federation_header_bg',
                'label' => 'Image for the federation header background',
                'name' => 'federation_header_bg_image',
                'type' => 'image',
                'return_format' => 'array',
                'preview_size' => 'medium',
                'library' => 'all',
            ),

            /** Multimedia Settings Tab */
            array(
                'key' => 'field_multimedia_settings_tab',
                'label' => 'Multimedia Settings',
                'name' => '',
                'type' => 'tab',
                'placement' => 'top',
                'endpoint' => 0,
            ),

            array(
                'key'               => 'field_team_youtube',
                'label'             => 'YouTube Channel ID',
                'name'              => 'youtube_channel_id',
                'type'              => 'text',
                'instructions'      => 'Enter only the YouTube Channel ID. Example: UCdCCyF92C76R2ux_YQcOAFQ',
                'required'          => 0,
                'conditional_logic' => 0,
                'wrapper'           => array(
                    'width' => '50',
                    'class' => '',
                    'id'    => '',
                ),
                'default_value'     => '',
                'prepend'           => '',
                'append'            => '',
                'maxlength'         => '',
            ),
             array(
                'key' => 'field_img_for_video_header_bg',
                'label' => 'Image for the Video header background',
                'name' => 'video_header_bg_image',
                'type' => 'image',
                'return_format' => 'array',
                'preview_size' => 'medium',
                'library' => 'all',
            ),

             array(
                'key' => 'field_img_for_fotogallery_header_bg',
                'label' => 'Image for the Fotogallery header background',
                'name' => 'fotogallery_header_bg_image',
                'type' => 'image',
                'return_format' => 'array',
                'preview_size' => 'medium',
                'library' => 'all',
            ),
           
            array(
                'key' => 'field_stadium_settings_tab',
                'label' => 'Stadium Settings',
                'name' => '',
                'type' => 'tab',
                'placement' => 'top',
                'endpoint' => 0,
            ),
            array(
                'key' => 'field_stadium_header_bg_image',
                'label' => 'Stadium Header Background Image',
                'name' => 'stadium_header_bg_image',
                'type' => 'image',
                'instructions' => '',
                'required' => 0,
                'conditional_logic' => 0,
                'return_format' => 'array',
                'preview_size' => 'medium',
                'library' => 'all',
                'min_width' => '',
                'min_height' => '',
                'min_size' => '',
                'max_width' => '',
                'max_height' => '',
                'max_size' => '',
                'mime_types' => '',
            ),
             array(
                'key' => 'field_Team_tab',
                'label' => 'Team Settings',
                'name' => '',
                'type' => 'tab',
                'placement' => 'top',
                'endpoint' => 0,
            ),
                array(
                    'key' => 'field_team_header_bg_image',
                    'label' => 'Team Header Background Image',
                    'name' => 'team_header_bg_image',
                    'type' => 'image',
                    'instructions' => '',
                    'required' => 0,
                    'conditional_logic' => 0,
                    'return_format' => 'array',
                    'preview_size' => 'medium',
                    'library' => 'all',
                    'min_width' => '',
                    'min_height' => '',
                    'min_size' => '',
                    'max_width' => '',
                    'max_height' => '',
                    'max_size' => '',
                    'mime_types' => '',
                ),
        ),
        'location' => array(
            array(
                array(
                    'param' => 'options_page',
                    'operator' => '==',
                    'value' => 'global-settings',
                ),
            ),
        ),
        'menu_order' => 0,
        'position' => 'normal',
        'style' => 'default',
        'label_placement' => 'top',
        'instruction_placement' => 'label',
        'hide_on_screen' => '',
        'active' => true,
        'description' => '',
        'show_in_rest' => 0,
    ));

    /** Text Image Info block */
    acf_add_local_field_group(array(
        'key' => 'group_text_img_info_banner',
        'title' => 'Text Img Info Banner',
        'fields' => array(
            array(
                'key'   => 'field_text_img_info_block_label',
                'label' => 'Block: Text Image Information Block',
                'name'  => '',
                'type'  => 'message',
                'new_lines' => 'wpautop',
                'esc_html'  => 0,
            ),
            array(
                'key' => 'field_text_img_info_title',
                'label' => 'Title',
                'name' => 'text_img_info_title',
                'type' => 'text',
                'required' => 0,
                'default_value' => '',
                'placeholder' => 'Enter title',
            ),
            array(
                'key' => 'field_text_img_info_para',
                'label' => 'Paragraph',
                'name' => 'text_img_info_para',
                'type' => 'textarea',
                'required' => 0,
                'rows' => 4,
                'placeholder' => 'Enter paragraph text',
            ),
            array(
                'key' => 'field_text_img_info_image',
                'label' => 'Image',
                'name' => 'text_img_info_image',
                'type' => 'image',
                'return_format' => 'array',
                'preview_size' => 'medium',
                'library' => 'all',
            ),
            array(
                'key' => 'field_text_img_info_social_url',
                'label' => 'Social URL',
                'name' => 'text_img_info_image_social_url',
                'type' => 'link',
            ),

        ),

        'location' => array(
            array(
                array(
                    'param' => 'block',
                    'operator' => '==',
                    'value' => 'acf/futebol365-text-img-info-block',
                ),
            ),
        ),
        'menu_order' => 0,
        'position' => 'normal',
        'style' => 'default',
        'label_placement' => 'top',
        'instruction_placement' => 'label',
        'hide_on_screen' => '',
        'active' => true,
        'description' => '',
        'show_in_rest' => 0,
    ));

    /** special heading block */
    acf_add_local_field_group(array(
        'key' => 'group_special_heading_block',
        'title' => 'Special Heading Block',
        'fields' => array(
            array(
                'key'   => 'field_special_heading_block_label',
                'label' => 'Block: Special Heading Block',
                'name'  => '',
                'type'  => 'message',
                'new_lines' => 'wpautop',
                'esc_html'  => 0,
            ),
            array(
                'key' => 'field_special_heading_title',
                'label' => 'Title',
                'name' => 'special_heading_title',
                'type' => 'text',
                'required' => 0,
                'default_value' => '',
                'placeholder' => 'Enter title',
            ),
            array(
                'key' => 'field_selected_heading_tag',
                'label' => 'Heading Tag',
                'name' => 'selected_heading_tag',
                'type' => 'select',
                'choices' => array(
                    'h1' => 'h1',
                    'h2' => 'h2',
                    'h3' => 'h3',
                    'h4' => 'h4',
                    'h5' => 'h5',
                    'h6' => 'h6',
                ),
                'default_value' => 'h1',
            ),
            array(
                'key' => 'field_special_heading_color',
                'label' => 'Select Color',
                'name' => 'special_heading_color',
                'type' => 'color_picker',
                'return_format' => 'string',
            ),
            array(
                'key' => 'field_heading_link',
                'label' => 'Select Link',
                'name' => 'heading_link',
                'type' => 'link',
            )
        ),

        'location' => array(
            array(
                array(
                    'param' => 'block',
                    'operator' => '==',
                    'value' => 'acf/futebol365-special-heading-block',
                ),
            ),
        ),

        'menu_order' => 0,
        'position' => 'normal',
        'style' => 'default',
        'label_placement' => 'top',
        'instruction_placement' => 'label',
        'hide_on_screen' => '',
        'active' => true,
        'description' => '',
        'show_in_rest' => 0,
    ));

endif;		
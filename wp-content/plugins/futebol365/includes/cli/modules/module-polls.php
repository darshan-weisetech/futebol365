<?php
/**
 * Migration module: Polls.
 *
 * Django tables: poll_poll, poll_choice
 * WordPress CPT: f365_poll
 *
 * @package Futebol365
 * @since   2.0.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
    return;
}

trait F365_Module_Polls {

    protected function import_polls(): void {
        $total  = (int) $this->pg_val( 'SELECT COUNT(*) FROM poll_poll' );
        // Ensure Poll Maker plugin is active by checking its primary table
        global $wpdb;
        $polls_table   = $wpdb->prefix . 'ayspoll_polls';
        $answers_table = $wpdb->prefix . 'ayspoll_answers';
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$polls_table}'" ) != $polls_table ) {
            WP_CLI::error( 'Poll Maker plugin is not activated or its tables are missing. Please activate Poll Maker before running this migration.' );
            return;
        }

        $rows   = $this->pg_all( 'SELECT * FROM poll_poll ORDER BY datetime DESC LIMIT $1 OFFSET $2',
            [ min( $total, $this->limit ), $this->offset ] );
        $n = $skip = $fail = 0;

        foreach ( $rows as $r ) {
            $existing_poll_id = $this->already_imported( 'ayspoll_polls', $r['id'] );

            if ( $n >= $this->limit ) break;
            if ( ! $this->force && $existing_poll_id && $this->dry_run ) {
                $skip++;
                $this->log( 'polls', "SKIP poll_id={$r['id']}" );
                continue;
            }

            $pd        = $r['datetime'] ? date( 'Y-m-d H:i:s', strtotime( $r['datetime'] ) ) : current_time( 'mysql' );
            $is_active = ( $r['is_active'] ?? false ) ? 1 : 0;

            $default_styles = '{"randomize_answers":"off","main_color":"#0C6291","text_color":"#0C6291","button_text_color":"#FBFEF9","button_bg_color":"#0C6291","icon_color":"#0C6291","icon_size":24,"width":0,"width_for_mobile":0,"poll_min_height":"","btn_text":"Vote","border_style":"ridge","border_radius":"0","border_width":"2","box_shadow_color":"#000000","poll_box_shadow_x_offset":0,"poll_box_shadow_y_offset":0,"poll_box_shadow_z_offset":15,"enable_background_gradient":"off","background_gradient_color_1":"#103251","background_gradient_color_2":"#607593","poll_gradient_direction":"vertical","poll_question_size_pc":"16","poll_question_size_mobile":"16","poll_question_image_height":"","poll_question_image_object_fit":"cover","poll_mobile_max_width":"","poll_buttons_size":"medium","poll_buttons_font_size":"17","poll_buttons_mobile_font_size":"17","poll_buttons_left_right_padding":"20","poll_buttons_top_bottom_padding":"10","poll_buttons_border_radius":"3","poll_buttons_width":"","poll_buttons_mobile_width":"","enable_box_shadow":"off","bg_color":"#FBFEF9","answer_bg_color":"#FBFEF9","answer_hover_color":"#0C6291","answer_border_side":"all_sides","answer_font_size":"16","poll_answer_font_size_mobile":"16","poll_answer_object_fit":"cover","poll_answer_padding":"10","poll_answer_margin":"10","poll_answer_border_radius":0,"poll_answer_icon_check":"off","poll_answer_icon":"radio","poll_answer_view_type":"list","poll_answer_enable_box_shadow":"off","poll_answer_box_shadow_color":"#000000","poll_answer_box_shadow_x_offset":0,"poll_answer_box_shadow_y_offset":0,"poll_answer_box_shadow_z_offset":10,"title_bg_color":"rgba(255,255,255,0)","poll_title_font_size":"20","poll_title_font_size_mobile":"20","bg_image":false,"enable_answer_style":"on","hide_results":0,"hide_result_message":0,"hide_results_text":"Thanks for your answer!","allow_not_vote":0,"show_social":0,"load_effect":"load_gif","load_gif":"plg_default","limit_users":0,"limitation_message":"","redirect_url":"","redirection_delay":0,"user_role":"","enable_restriction_pass":0,"restriction_pass_message":"","enable_logged_users":0,"enable_logged_users_message":"","notify_email_on":0,"notify_email":"","result_sort_type":"none","create_date":"","author":"","redirect_users":0,"redirect_after_vote_url":"","redirect_after_vote_delay":0,"published":1,"enable_pass_count":"on","activeInterval":"","activeIntervalSec":"","deactiveInterval":"","deactiveIntervalSec":"","active_date_message":"","active_date_check":"","enable_restart_button":0,"enable_vote_btn":1,"disable_answer_hover": 0,"logo_image":"","poll_enable_logo_url":"off","poll_logo_title":"","poll_logo_url":"","custom_class":"","enable_poll_title_text_shadow":"off","poll_title_text_shadow":"rgba(255,255,255,0)","poll_title_text_shadow_x_offset":2,"poll_title_text_shadow_y_offset":2,"poll_title_text_shadow_z_offset":0,"poll_allow_multivote":"off","multivote_answer_min_count":"1","poll_allow_multivote_count":"1","poll_direction":"ltr","show_create_date":0,"show_author":0,"ays_poll_show_timer":0,"ays_show_timer_type":"countdown","show_result_btn_see_schedule":"with_see","active_date_message_soon":"","show_result_btn_schedule":0,"dont_show_poll_cont":"off","see_res_btn_text":"See Results","enable_asnwers_sound":"off","poll_vote_reason":"off","enable_view_more_button":"off","poll_view_more_button_count":0,"answer_sort_type":"default","show_answers_numbering":"none","result_message":"","poll_social_buttons_heading":"","poll_show_social_ln":"on","poll_show_social_fb":"on","poll_show_social_tr":"on","poll_show_social_vk":"off","limit_users_method":"ip","show_votes_count":1,"show_res_percent":1,"show_login_form":"off","info_form":0,"fields":"apm_name,apm_email,apm_phone","required_fields":"apm_email","info_form_title":"","enable_mailchimp":"off","redirect_after_submit":0,"mailchimp_list":"","users_role":"[]","poll_bg_image_position":"center center","poll_bg_img_in_finish_page":"off","ays_add_post_for_poll":"off","result_in_rgba":"off","show_passed_users":"off","see_result_button":"on","see_result_radio":"ays_see_result_button","loader_font_size":"64","effect_message":"","poll_allow_collecting_users_data":"off","poll_every_answer_redirect_delay":"","poll_enable_answer_image_after_voting":"off","poll_enable_answer_redirect_delay":"off","poll_show_passed_users_count":3,"poll_allow_answer":"off","poll_allow_answer_require":"off","poll_answer_image_height":"150","poll_answer_image_height_for_mobile":"150","poll_answer_image_border_radius":"0","poll_title_alignment":"center","poll_title_alignment_mobile":"center","poll_text_type_length_enable":"off","poll_text_type_limit_type":"characters","poll_text_type_limit_length":"","poll_text_type_limit_message":"off","poll_text_type_placeholder":"Your answer","poll_text_type_width":"","poll_text_type_width_type":"percent","poll_enable_password":"off","poll_password":"","poll_enable_password_visibility":"off","poll_password_message":"","display_fields_labels":"off","autofill_user_data":"off","poll_logo_url_new_tab":"off","poll_create_author":1,"enable_social_links":"off","poll_social_links_heading":"","social_links":{"linkedin_link":"","facebook_link":"","twitter_link":"","vkontakte_link":"","youtube_link":"","tiktok_link":""},"show_chart_type":"default_bar_chart","show_chart_type_google_height":"400","border_color":"#0C6291"}';
            $styles_arr = json_decode( $default_styles, true );
            $styles_arr['create_date'] = $pd;
            $styles_arr['published']   = $is_active;

            $image_url = '';
            $att_id = 0;
            if ( ! empty( $r['image'] ) && ! $this->dry_run ) {
                $att_id = $this->sideload_image(
                    $this->media_url( $r['image'] ),
                    0,
                    $r['question'] ?? '',
                    'polls'
                );
                if ( $att_id ) {
                    $image_url = wp_get_attachment_url( $att_id );
                } else {
                    $this->log( 'polls', "IMAGE_FAIL poll_id={$r['id']}" );
                }
            }

            $poll_inserted = false;
            $wp_id = false;
            if ( ! $this->dry_run ) {
                $poll_data = [
                    'title'       => $r['question'] ?? '',
                    'description' => '',
                    'question'    => $r['question'] ?? '',
                    'type'        => 'choosing',
                    'view_type'   => 'list',
                    'categories'  => ',1,',
                    'image'       => $image_url,
                    'post_id'     => $r['id'], // Hijacking post_id to store Django ID
                    'styles'      => wp_json_encode( $styles_arr ),
                ];

                if ( $existing_poll_id ) {
                    // Modifying existing data as requested
                    $updated = $wpdb->update( $polls_table, $poll_data, [ 'id' => $existing_poll_id ] );
                    $poll_inserted = ( $updated !== false );
                    $wp_id = $existing_poll_id;
                    $this->log( 'polls', "UPDATED poll_id={$r['id']} -> ayspoll_polls ID {$wp_id}" );

                    // Delete old answers and reports so they can be freshly re-inserted
                    $wpdb->delete( $answers_table, [ 'poll_id' => $wp_id ] );
                    $reports_table = $wpdb->prefix . 'ayspoll_reports';
                    if ( $wpdb->get_var( "SHOW TABLES LIKE '{$reports_table}'" ) == $reports_table ) {
                        $wpdb->delete( $reports_table, [ 'poll_id' => $wp_id ] );
                    }
                } else {
                    $poll_inserted = $wpdb->insert( $polls_table, $poll_data );
                    $wp_id = $wpdb->insert_id;
                }
            }

            if ( ! $poll_inserted && ! $this->dry_run ) {
                $fail++;
                $this->log( 'polls', "FAIL poll_id={$r['id']} (Could not insert/update to Poll Maker)" );
                continue;
            }

            $wp_id = $this->dry_run ? rand(1000, 9999) : $wp_id;

            // Import choices into ayspoll_answers table
            $choice_rows = $this->pg_all( '
                SELECT * FROM poll_choice WHERE poll_id = $1
                ORDER BY "order" NULLS LAST, id
            ', [ $r['id'] ] );

            $total_votes  = 0;
            $choice_count = 0;
            $choice_map   = []; // Map old choice ID to new answer ID
            foreach ( $choice_rows as $cr ) {
                $votes        = (int) ( $cr['votes'] ?? 0 );
                $total_votes += $votes;
                $choice_count++;
                
                if ( ! $this->dry_run ) {
                    $wpdb->insert( $answers_table, [
                        'poll_id'  => $wp_id,
                        'answer'   => $cr['choice_text'] ?? '',
                        'votes'    => $votes,
                        'ordering' => (int) ( $cr['order'] ?? 0 ),
                    ] );
                    $choice_map[ $cr['id'] ] = $wpdb->insert_id;
                }
            }

            // Import historical IP votes into ayspoll_reports
            if ( ! $this->dry_run ) {
                $reports_table = $wpdb->prefix . 'ayspoll_reports';
                if ( $wpdb->get_var( "SHOW TABLES LIKE '{$reports_table}'" ) == $reports_table ) {
                    $responses = $this->pg_all( '
                        SELECT r.* 
                        FROM poll_response r 
                        JOIN poll_choice c ON r.choice_id = c.id 
                        WHERE c.poll_id = $1
                    ', [ $r['id'] ] );

                    $reports_inserted = 0;
                    foreach ( $responses as $resp ) {
                        if ( empty( $resp['ip_address'] ) ) continue;
                        $old_choice_id = $resp['choice_id'];
                        $new_answer_id = $choice_map[ $old_choice_id ] ?? 0;
                        if ( ! $new_answer_id ) continue;

                        $wpdb->insert( $reports_table, [
                            'poll_id'   => $wp_id,
                            'answer_id' => $new_answer_id,
                            'user_ip'   => $resp['ip_address'],
                            'user_id'   => 0,
                            'vote_date' => $resp['datetime'] ? date( 'Y-m-d H:i:s', strtotime( $resp['datetime'] ) ) : current_time( 'mysql' ),
                        ] );
                        $reports_inserted++;
                    }
                    if ( $reports_inserted ) {
                        $this->log( 'polls', "IMPORTED $reports_inserted IP reports for poll_id={$r['id']}" );
                    }
                }
            }

            $this->map_set( 'ayspoll_polls', $r['id'], $wp_id );
            $this->log( 'polls', "OK poll_id={$r['id']} → ays_poll_id=$wp_id choices=$choice_count total_votes=$total_votes" );
            $n++;
        }

        $this->map_save( 'ayspoll_polls' );
        $this->log( 'polls', "SUMMARY: imported=$n skipped=$skip failed=$fail" );
        WP_CLI::success( "Polls: $n imported, $skip skipped, $fail failed." );
    }
}

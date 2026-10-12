<div class="wrap">
    <div class="ays-poll-heading-box">
        <div class="ays-poll-wordpress-user-manual-box">
            <a href="https://ays-pro.com/wordpress-poll-maker-user-manual" target="_blank" style="text-decoration: none;font-size: 13px;">
                <i class="ays_poll_fas ays_fa_file_text"></i>
                <span style="margin-left: 3px;text-decoration: underline;"><?php echo esc_html__("View Documentation", "poll-maker"); ?></span>
            </a>
        </div>
    </div>
    <h1 class="wp-heading-inline">
        <?php
            echo esc_html(get_admin_page_title());
        ?>
    </h1>
    <div class="ays-poll-how-to-user-custom-fields-box" style="margin: auto;">
        <div class="ays-poll-how-to-user-custom-fields-title">
            <h4><?php echo esc_html__( "Learn How to Use Custom Fields in Poll Maker", "poll-maker" ); ?></h4>
        </div>
        <div class="ays-poll-how-to-user-custom-fields-youtube-video">
            <div class="ays-poll-community-youtube-video">
                <div class="ays-poll-create-poll-youtube-video">
                    <div class="ays-poll-youtube-placeholder" data-video-id="LnaTowgH29c">
                        <img src="<?php echo esc_url(POLL_MAKER_AYS_ADMIN_URL .'/images/custom-fields-in-poll.webp'); ?>" loading="lazy" width="560" height="315">
                    </div>
                </div>                
            </div>
        </div>
    </div>
    <div class="form-group row" style="margin: 20px 0 0 0;">
        <div class="col-sm-12 only_pro" style="padding:10px 0 0 10px;">
            <div class="pro_features" style="justify-content:flex-end;">
            </div>
            <img src="<?php echo esc_url(POLL_MAKER_AYS_ADMIN_URL) . '/images/features/personal_avatar.png'; ?>" alt="Statistics" style="width: 100%;">
            <a href="https://ays-pro.com/wordpress/poll-maker" target="_blank" class="ays-poll-new-upgrade-button-link">
                <div class="ays-poll-new-upgrade-button-box">
                    <div>
                        <img src="<?php echo esc_url(POLL_MAKER_AYS_ADMIN_URL).'/images/icons/pro-features-icons/locked_24x24.svg'?>">
                        <img src="<?php echo esc_url(POLL_MAKER_AYS_ADMIN_URL).'/images/icons/pro-features-icons/unlocked_24x24.svg'?>" class="ays-poll-new-upgrade-button-hover">
                    </div>
                    <div class="ays-poll-new-upgrade-button"><?php echo esc_html__("Upgrade", "poll-maker"); ?></div>
                </div>
            </a>
            <div class="ays-poll-center-big-main-button-box ays-poll-new-big-button-flex">
                <div class="ays-poll-center-big-main-button-box">
                    <a href="https://ays-pro.com/wordpress/poll-maker" target="_blank" class="ays-poll-new-upgrade-button-link">
                        <div class="ays-poll-center-new-big-upgrade-button">
                            <img src="<?php echo esc_url(POLL_MAKER_AYS_ADMIN_URL).'/images/icons/pro-features-icons/locked_24x24.svg'?>" class="ays-poll-new-button-img-hide">
                            <img src="<?php echo esc_url(POLL_MAKER_AYS_ADMIN_URL).'/images/icons/pro-features-icons/unlocked_24x24.svg'?>" class="ays-poll-new-upgrade-button-hover">  
                            <?php echo esc_html__("Upgrade", "poll-maker"); ?>
                        </div>
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- This file should primarily consist of HTML with a little bit of PHP. -->
<div class="wrap">
    <div class="ays-poll-heading-box">
        <div class="ays-poll-wordpress-user-manual-box">
            <a href="https://ays-pro.com/wordpress-poll-maker-user-manual" target="_blank" style="text-decoration: none;font-size: 13px;">
                <i class="ays_poll_fas ays_fa_file_text"></i>
                <span style="margin-left: 3px;text-decoration: underline;"><?php echo esc_html__("View Documentation", "poll-maker"); ?></span>
            </a>
        </div>
    </div>
    <h1 id="ays-poll-intro-title"><?php echo esc_html__('Please feel free to use our other awesome plugins!', 'poll-maker'); ?></h1>
    <?php $this->poll_output_about_addons(); ?> 
    <div class="ays-poll-see-all">
        <a href="https://ays-pro.com/wordpress" target="_blank" class="ays-poll-all-btn"><?php echo esc_html__('See All Plugins', "poll-maker"); ?></a>
    </div>
</div>
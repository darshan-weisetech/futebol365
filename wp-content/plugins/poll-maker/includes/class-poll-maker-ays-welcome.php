<?php

class Poll_Maker_Ays_Welcome {

    /**
     * Hidden welcome page slug.
     *
     * @since 4.6.4
     */
    const SLUG = 'poll-maker-getting-started';

    /**
     * Primary class constructor.
     *
     * @since 4.6.4
     */
    public function __construct() {
        add_action( 'plugins_loaded', [ $this, 'hooks' ] );
    }

    public function hooks() {
		add_action( 'admin_menu', [ $this, 'register' ] );
		add_action( 'admin_head', [ $this, 'hide_menu' ] );
		add_action( 'admin_init', [ $this, 'redirect' ], 9999 );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_styles' ], 10, 1 );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
    }

	/**
	 * Register the pages to be used for the Welcome screen (and tabs).
	 *
	 * These pages will be removed from the Dashboard menu, so they will
	 * not actually show. Sneaky, sneaky.
	 *
	 * @since 1.0.0
	 */
	public function register() {

        add_dashboard_page(
			esc_html__( 'Welcome to Poll Maker', "poll-maker" ),
			esc_html__( 'Welcome to Poll Maker', "poll-maker" ),
			'manage_options',
			self::SLUG,
			[ $this, 'output' ]
		);
	}

    /**
     * Removed the dashboard pages from the admin menu.
     *
     * This means the pages are still available to us, but hidden.
     *
     * @since 4.6.4
     */
    public function hide_menu() {

        remove_submenu_page( 'index.php', self::SLUG );
    }

    /**
     * Welcome screen redirect.
     *
     * This function checks if a new install or update has just occurred. If so,
     * then we redirect the user to the appropriate page.
     *
     * @since 4.6.4
     */
    public function redirect() {

        $current_page = isset( $_GET['page'] ) ? $_GET['page'] : '';

        // Check if we are already on the welcome page.
        if ( $current_page === self::SLUG ) {
            return;
        }

        // Get the activation status
        $first_activation = get_option('ays_poll_maker_first_time_activation_page', false);
        
        // Check if we're in a multisite environment
        if (function_exists('is_multisite') && is_multisite()) {
            // Get current blog ID
            $blog_id = get_current_blog_id();
            
            // Use a blog-specific option name to track activation for each site
            $blog_specific_option = 'ays_poll_maker_first_time_activation_page_blog_' . $blog_id;
            $blog_first_activation = get_option($blog_specific_option, null);
            
            // If blog-specific option doesn't exist yet, set it based on the main option
            if ($blog_first_activation === null) {
                update_option($blog_specific_option, $first_activation);
                $first_activation = $first_activation;
            } else {
                // Use the blog-specific activation status
                $first_activation = $blog_first_activation;
            }
        }

        if (isset($_GET['page']) && strpos($_GET['page'], POLL_MAKER_AYS_NAME) !== false && $first_activation) {
            wp_safe_redirect( admin_url( 'index.php?page=' . self::SLUG ) );
            exit;
        }
    }

    /**
     * Enqueue custom CSS styles for the welcome page.
     *
     * @since 4.6.4
     */
    public function enqueue_styles( $hook ) {
            
        if (strpos( $hook, 'poll-maker-ays-dashboard' ) === false && strpos( $hook, 'poll-maker-getting-started' ) === false) {
            return;
        }
    
        wp_enqueue_style(
            'poll-maker-ays-welcome-css', 
            esc_url(POLL_MAKER_AYS_ADMIN_URL) . '/css/poll-maker-ays-welcome.css',
            array(), false, 'all'
        );
    }
    

    /**
	 * Register the JavaScript for the welcome page.
	 *
	 * @since 4.6.4
	 */
    public function enqueue_scripts() {

        wp_enqueue_script( 'poll-maker-ays-welcome', esc_url(POLL_MAKER_AYS_ADMIN_URL) . '/js/poll-maker-ays-welcome.js', array('jquery'), false, true);
        wp_localize_script( 'poll-maker-ays-welcome', 'poll_maker_ays_welcome', array(
            'show_more' => esc_html__( 'Show More', "poll-maker" ),
            'show_less' => esc_html__( 'Show Less', "poll-maker" ),
        ) );
    }

    private function get_latest_changelog_entries($limit = 3) {
        $readme_path = plugin_dir_path(__FILE__) . '../README.txt';
        if ( ! file_exists( $readme_path ) ) {
            return array();
        }

        $content = file_get_contents( $readme_path );

        // Extract only the Changelog section
        if ( preg_match( '/==\s*Changelog\s*==(.*)/is', $content, $matches ) ) {
            $changelog = trim($matches[1]);
        } else {
            return array();
        }

        // Split each version entry
        preg_match_all( '/=+\s*(.*?)\s*=+\s*(.*?)(?=(?:=+\s*[\d\.]+|\Z))/is', $changelog, $entries, PREG_SET_ORDER );

        $releases = array();
        foreach ( $entries as $entry ) {
            $title = trim($entry[1]);
            $changes = trim($entry[2]);

            // Extract version and date if available
            if ( preg_match('/([\d\.]+).*?\((.*?)\)/', $title, $version_match) ) {
                $version = trim($version_match[1]);
                $date = trim($version_match[2]);
            } else {
                $version = $title;
                $date = '';
            }

            // Split lines like * Added: Something
            $lines = array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $changes)));
            $lines = array_map(function($line) {
                return preg_replace('/^\*\s*/', '', $line);
            }, $lines);

            $releases[] = array(
                'version' => $version,
                'date'    => $date,
                'changes' => $lines,
            );
        }

        return array_slice($releases, 0, $limit);
    }

    /**
     * Getting Started screen. Shows after first install.
     *
     * @since 1.0.0
     */
    public function output($hide_close_button = false) {

        $latest_changelog = $this->get_latest_changelog_entries(3);

        ?>
            <div class="ays-pm-w-container">
                <!-- Header -->
                <section class="ays-pm-w-section ays-pm-w-header-wrapper ays-pm-w-text-center">
                    <div class="ays-pm-w-header-dismiss" <?php if($hide_close_button) echo 'style="display: none"'; ?>>
                        <a href="<?php echo admin_url( 'admin.php?page=' . POLL_MAKER_AYS_NAME ) ?> ">
                            <img src="<?php echo esc_url(POLL_MAKER_AYS_ADMIN_URL); ?>/images/icons/close.svg" alt="<?php echo esc_html__( 'Close', "poll-maker" ); ?>">
                        </a>
                    </div>
                    <div class="ays-pm-w-header-icon">
                        <img src="<?php echo esc_url(POLL_MAKER_AYS_ADMIN_URL) . '/images/icons/poll-maker-logo.png'?>" alt="poll-w-logo">
                    </div>
                    <h1 class="ays-pm-w-header-title"><?php echo esc_html__( 'Welcome to Poll Maker', "poll-maker" ); ?></h1>
                    <p class="ays-pm-w-header-desc"><?php echo esc_html__( 'Thank you for choosing Poll Maker — the best poll and survey plugin for WordPress', "poll-maker" ); ?></p>
                    <div class="ays-pm-w-header-create">
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . POLL_MAKER_AYS_NAME . "&action=add") ); ?>" class="poll-maker-btn poll-maker-btn-block poll-maker-btn-lg poll-maker-btn-orange">
                            <?php echo esc_html__( 'Create Your First Poll', "poll-maker" ); ?>
                        </a>
                    </div>
                </section>

                <!-- Quick Start Steps -->
                <section class="ays-pm-w-section">
                    <div class="ays-pm-w-text-center">
                        <h2 class="ays-pm-w-header-title" style="font-size:1.5rem;"><?php echo esc_html__( 'Get Started in a Few Steps', "poll-maker" ); ?></h2>
                        <p class="ays-pm-w-header-desc" style="font-size:1rem;"><?php echo esc_html__( 'Follow these simple steps to create your first poll', "poll-maker" ); ?></p>
                    </div>

                    <div class="ays-pm-w-steps-wrapper" style="margin-top:2rem;">
                        <!-- Step 1 -->
                        <div class="ays-pm-w-step-item">
                        <div class="ays-pm-w-step-number"><?php echo esc_html__( 'Step 1', "poll-maker" ); ?></div>
                        <div class="ays-pm-w-step-content">
                            <h3><?php echo esc_html__( 'Add a New Poll', "poll-maker" ); ?></h3>
                        </div>
                        </div>
                        <!-- Step 2 -->
                        <div class="ays-pm-w-step-item">
                        <div class="ays-pm-w-step-number"><?php echo esc_html__( 'Step 2', "poll-maker" ); ?></div>
                        <div class="ays-pm-w-step-content">
                            <h3><?php echo esc_html__( 'Add Options', "poll-maker" ); ?></h3>
                        </div>
                        </div>
                        <!-- Step 3 -->
                        <div class="ays-pm-w-step-item">
                        <div class="ays-pm-w-step-number"><?php echo esc_html__( 'Step 3', "poll-maker" ); ?></div>
                        <div class="ays-pm-w-step-content">
                            <h3><?php echo esc_html__( 'Save the Poll', "poll-maker" ); ?></h3>
                        </div>
                        </div>
                        <!-- Step 4 -->
                        <div class="ays-pm-w-step-item">
                        <div class="ays-pm-w-step-number"><?php echo esc_html__( 'Step 4', "poll-maker" ); ?></div>
                        <div class="ays-pm-w-step-content">
                            <h3><?php echo esc_html__( 'Copy the Shortcode', "poll-maker" ); ?></h3>
                        </div>
                        </div>
                    </div>
                </section>

                <!-- Video Tutorials -->
                <section class="ays-pm-w-section">
                    <div class="ays-pm-w-text-center">
                        <h2 class="ays-pm-w-header-title" style="font-size:1.5rem;"><?php echo esc_html__( 'Learn with Video', "poll-maker" ); ?></h2>
                        <p class="ays-pm-w-header-desc" style="font-size:1rem;"><?php echo esc_html__( 'Watch these quick tutorials to master Poll Maker', "poll-maker" ); ?></p>
                    </div>

                    <div class="ays-pm-w-video-grid" style="margin-top:2rem;">
                        <!-- Video 1 -->
                        <div class="ays-pm-w-video-card" data-video-id="8Z4aJ0jhSa8">
                        <div class="ays-pm-w-video-thumb">
                            <img src="https://img.youtube.com/vi/8Z4aJ0jhSa8/hqdefault.jpg" alt="Getting Started with Poll Maker">
                            <div class="ays-pm-w-video-overlay">
                            <div class="ays-pm-w-play-btn">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18" style="margin-left:3px">
                                <polygon points="6 4 20 12 6 20 6 4"></polygon>
                                </svg>
                            </div>
                            </div>
                            <span class="ays-pm-w-video-duration">1:18</span>
                        </div>
                        <div class="ays-pm-w-video-title"><?php echo esc_html__( 'WordPress Poll Plugin', "poll-maker" ); ?></div>
                        </div>

                        <!-- Video 2 -->
                        <div class="ays-pm-w-video-card" data-video-id="JrTkFtliTVQ">
                        <div class="ays-pm-w-video-thumb">
                            <img src="https://img.youtube.com/vi/JrTkFtliTVQ/hqdefault.jpg" alt="Advanced Poll Customization">
                            <div class="ays-pm-w-video-overlay">
                            <div class="ays-pm-w-play-btn">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18" style="margin-left:3px">
                                <polygon points="6 4 20 12 6 20 6 4"></polygon>
                                </svg>
                            </div>
                            </div>
                            <span class="ays-pm-w-video-duration">2:00</span>
                        </div>
                        <div class="ays-pm-w-video-title"><?php echo esc_html__( 'Best Way to Create WordPress Anonymous Poll', "poll-maker" ); ?></div>
                        </div>

                        <!-- Video 3 -->
                        <div class="ays-pm-w-video-card" data-video-id="y9yu9Md4vCs">
                        <div class="ays-pm-w-video-thumb">
                            <img src="https://img.youtube.com/vi/y9yu9Md4vCs/hqdefault.jpg" alt="Embedding Polls on Your Site">
                            <div class="ays-pm-w-video-overlay">
                            <div class="ays-pm-w-play-btn">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18" style="margin-left:3px">
                                <polygon points="6 4 20 12 6 20 6 4"></polygon>
                                </svg>
                            </div>
                            </div>
                            <span class="ays-pm-w-video-duration">3:26</span>
                        </div>
                        <div class="ays-pm-w-video-title"><?php echo esc_html__( 'How to Create a Password Protected Poll', "poll-maker" ); ?></div>
                        </div>
                    </div>
                </section>

                <!-- Useful Resources -->
                <section class="ays-pm-w-section">
                    <div class="ays-pm-w-text-center">
                        <h2 class="ays-pm-w-header-title" style="font-size:1.5rem;"><?php echo esc_html__( 'Help & Support', "poll-maker" ); ?></h2>
                    </div>

                    <div class="ays-pm-w-resource-grid" style="margin-top:2rem;">
                        <!-- Card Template -->
                        <div class="ays-pm-w-resource-card">
                        <div class="ays-pm-w-resource-icon ays-pm-w-demo" style="background:#d1fae5;color:#047857;"><img src="<?php echo esc_url(POLL_MAKER_AYS_ADMIN_URL) . '/images/icons/poll-w-demo.svg'?>" alt="demo"></div>
                        <div class="ays-pm-w-resource-content">
                            <div class="ays-pm-w-resource-title"><?php echo esc_html__( 'Demo', "poll-maker" ); ?></div>
                            <p class="ays-pm-w-resource-desc"><?php echo esc_html__( 'See Poll Maker in action with live examples and use cases', "poll-maker" ); ?></p>
                            <a href="https://poll-plugin.com/wordpress-poll-plugin-free-demo" target="_blank" class="ays-pm-w-resource-action"><?php echo esc_html__( 'View Demo →', "poll-maker" ); ?></a>
                        </div>
                        </div>
                        <div class="ays-pm-w-resource-card">
                        <div class="ays-pm-w-resource-icon ays-pm-w-doc" style="background:#dbeafe;color:#2563eb;"><img src="<?php echo esc_url(POLL_MAKER_AYS_ADMIN_URL) . '/images/icons/poll-w-doc.svg'?>" alt="doc"></div>
                        <div class="ays-pm-w-resource-content">
                            <div class="ays-pm-w-resource-title"><?php echo esc_html__( 'Documentation', "poll-maker" ); ?></div>
                            <p class="ays-pm-w-resource-desc"><?php echo esc_html__( 'Complete guides, tutorials, and API reference for developers', "poll-maker" ); ?></p>
                            <a href="https://ays-pro.com/wordpress-poll-maker-user-manual" target="_blank" class="ays-pm-w-resource-action"><?php echo esc_html__( 'Read Docs →', "poll-maker" ); ?></a>
                        </div>
                        </div>
                        <div class="ays-pm-w-resource-card">
                        <div class="ays-pm-w-resource-icon ays-pm-w-community" style="background:#ffedd5;color:#c2410c;"><img src="<?php echo esc_url(POLL_MAKER_AYS_ADMIN_URL) . '/images/icons/poll-w-community.svg'?>" alt="community"></div>
                        <div class="ays-pm-w-resource-content">
                            <div class="ays-pm-w-resource-title"><?php echo esc_html__( 'Community Forum', "poll-maker" ); ?></div>
                            <p class="ays-pm-w-resource-desc"><?php echo esc_html__( 'Ask questions, share ideas, and get help from the Poll Maker community', "poll-maker" ); ?></p>
                            <a href="https://wordpress.org/support/plugin/poll-maker" target="_blank" class="ays-pm-w-resource-action"><?php echo esc_html__( 'Join Forum →', "poll-maker" ); ?></a>
                        </div>
                        </div>
                        <div class="ays-pm-w-resource-card">
                        <div class="ays-pm-w-resource-icon ays-pm-w-support" style="background:#ede9fe;color:#6b21a8;"><img src="<?php echo esc_url(POLL_MAKER_AYS_ADMIN_URL) . '/images/icons/poll-w-support.svg'?>" alt="support"></div>
                        <div class="ays-pm-w-resource-content">
                            <div class="ays-pm-w-resource-title"><?php echo esc_html__( 'Contact Support', "poll-maker" ); ?></div>
                            <p class="ays-pm-w-resource-desc"><?php echo esc_html__( 'Get help from our friendly support team whenever you need it', "poll-maker" ); ?></p>
                            <a href="https://ays-pro.com/contact" target="_blank" class="ays-pm-w-resource-action"><?php echo esc_html__( 'Get Support →', "poll-maker" ); ?></a>
                        </div>
                        </div>
                    </div>
                </section>

                <!-- What's New -->
                <?php
                if ( ! empty( $latest_changelog ) ) :
                ?>
                <section class="ays-pm-w-section ays-pm-w-wn-section" style="position:relative;">
                    <div class="ays-pm-w-text-center">
                        <h2 class="ays-pm-w-header-title" style="font-size:1.5rem;"><?php echo esc_html__( "What's New", "poll-maker" ); ?></h2>
                        <button id="wn-toggle" class="ays-pm-w-wn-toggle" style="position:absolute; top:1rem; right:1rem; background:none;border:none;font-size:0.875rem;color:#2563eb;cursor:pointer;display:flex;align-items:center;gap:0.25rem;"><?php echo esc_html__( "Show More", "poll-maker" ); ?> <span class="ays-pm-w-wn-arrow">▾</span></button>
                        <p class="ays-pm-w-header-desc" style="font-size:1rem;margin-top:0.5rem;"><?php echo esc_html__( "Latest updates and improvements to Poll Maker", "poll-maker" ); ?></p>
                    </div>
                    <div class="ays-pm-w-changelog ays-pm-w-collapsed" style="margin-top:2rem;">
                        <?php foreach ( $latest_changelog as $release ) : ?>
                            <div class="ays-pm-w-release">
                                <div class="ays-pm-w-release-header">
                                    <span class="ays-pm-w-badge">v<?php echo esc_html( $release['version'] ); ?></span>
                                    <?php if ( ! empty( $release['date'] ) ) : ?>
                                        <span class="ays-pm-w-release-date"><?php echo esc_html( $release['date'] ); ?></span>
                                    <?php endif; ?>
                                </div>
                                <ul class="ays-pm-w-release-list">
                                    <?php foreach ( $release['changes'] as $change ) : ?>
                                        <li><?php echo esc_html( $change ); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
                <?php endif; ?>
            </div>
              <!-- Video Lightbox -->
            <div id="ays-pm-w-video-lightbox" class="ays-pm-w-video-lightbox">
                <div class="ays-pm-w-video-lightbox-content">
                <button id="ays-pm-w-video-lightbox-close" class="ays-pm-w-video-lightbox-close">
                    <img src="<?php echo esc_url(POLL_MAKER_AYS_ADMIN_URL); ?>/images/icons/close.svg" alt="<?php echo esc_html__( 'Close', "poll-maker" ); ?>">
                </button>
                <div class="ays-pm-w-video-wrapper">
                    <iframe id="ays-pm-w-video-iframe" width="560" height="315" src="" frameborder="0" allowfullscreen></iframe>
                </div>
                </div>
            </div>
        <?php
        // Update both the general option and the blog-specific option if in multisite
        update_option('ays_poll_maker_first_time_activation_page', false);
        
        if (function_exists('is_multisite') && is_multisite()) {
            $blog_id = get_current_blog_id();
            $blog_specific_option = 'ays_poll_maker_first_time_activation_page_blog_' . $blog_id;
            update_option($blog_specific_option, false);
        }
    }
}
new Poll_Maker_Ays_Welcome();
<?php
/**
 * MU Plugin: Stop WP Rocket cache from being purged on admin-ajax requests.
 * The RocketCDN auto-subscription feature calls rocket_clean_domain() on every
 * admin_init, wiping the entire cache. This filter prevents that by returning
 * an empty URL list during AJAX requests.
 */
add_filter("rocket_clean_domain_urls", function($urls) {
    if (defined("DOING_AJAX") && DOING_AJAX) {
        return [];
    }
    return $urls;
}, PHP_INT_MAX);

<?php
// 1. Fetch all fields into memory at once (Drastically reduces DB queries)
$current_post_id = get_the_ID();
$local_fields    = get_fields( $current_post_id ) ?: [];
$global_fields   = get_fields( 'option' ) ?: [];

// 2. Check Global Hide Setting
$global_hide = $global_fields['want_to_hide_offer_widget_globally'] ?? null;
if ( ! empty( $global_hide ) && in_array( 'Yes', (array) $global_hide, true ) ) {
    echo '<div class="ink-grid vertical-space"></div>';
	return;
}

// 3. Check Local Page/Post Hide Setting
$local_hide = $local_fields['want_to_hide_offer_widget_for_this_page'] ?? null;
if ( ! empty( $local_hide ) && in_array( 'Yes', (array) $local_hide, true ) ) {
    echo '<div class="ink-grid vertical-space"></div>';
	return;
}

// 4. Fetch Data with Fallback Chain (Using PHP's null coalescing & Elvis operators)
$offer_heading     = ( ! empty( $local_fields['promo_heading'] ) )     ? $local_fields['promo_heading']     : ( $global_fields['offer_heading'] ?? '' );
$offer_sub_heading = ( ! empty( $local_fields['promo_sub_heading'] ) ) ? $local_fields['promo_sub_heading'] : ( $global_fields['offer_sub_heading'] ?? '' );
$offer_link        = ( ! empty( $local_fields['promo_link'] ) )        ? $local_fields['promo_link']        : ( $global_fields['offer_link'] ?? null );

// 5. Sanitize Link Properties
$link_url    = ! empty( $offer_link['url'] ) ? $offer_link['url'] : '#';
$link_target = ! empty( $offer_link['target'] ) ? $offer_link['target'] : '_self';

// 6. Only render if we actually have text to display
if ( ! empty( $offer_heading ) || ! empty( $offer_sub_heading ) ) : 
?>
<div class="ink-grid vertical-space">
    <div class="column-group bottom-space">
        <div class="all-25 small-100 tiny-100 half-vertical-padding condensed-700 uppercase align-center large background-color-dark-apostar-1xbet vertical-center">
            <a class="font-white" href="<?php echo esc_url( $link_url ); ?>" target="<?php echo esc_attr( $link_target ); ?>">
                <?php if ( ! empty( $offer_heading ) ) : ?>
                    <em><?php echo esc_html( $offer_heading ); ?></em>
                <?php endif; ?>
            </a>
        </div>
        <div class="all-75 small-100 tiny-100 half-vertical-padding horizontal-padding condensed-400 align-center large background-color-apostar-1xbet">
            <a class="font-white" href="<?php echo esc_url( $link_url ); ?>" target="<?php echo esc_attr( $link_target ); ?>" rel="nofollow sponsored">
                <?php if ( ! empty( $offer_sub_heading ) ) : ?>
                    <em><?php echo esc_html( $offer_sub_heading ); ?></em>
                <?php endif; ?>
            </a>
            <span class="font-silver betting-age-badge">18+</span>
        </div>
    </div>
</div>
<?php endif; ?>
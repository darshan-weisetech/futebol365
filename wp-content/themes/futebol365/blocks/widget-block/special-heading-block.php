<?php
$allfields = get_fields();
$title       = $allfields['special_heading_title'];
$heading_tag = $allfields['selected_heading_tag'];
$color       = $allfields['special_heading_color'];
$heading_link = $allfields['heading_link'];

if ( empty( $title ) ) {
    $title = 'Sondagem';
}

if ( empty( $heading_tag ) ) {
    $heading_tag = 'h1'; 
}

$style_attribute = '';
if ( ! empty( $color ) ) {
    $style_attribute = ' style="background: ' . esc_attr($color) . ';"';
}
?>

<<?php echo esc_attr($heading_tag); ?> class="condensed-400 extralarge modtitle">
    <a href="<?php echo esc_url($heading_link['url']); ?>" target="<?php echo esc_attr($heading_link['target']); ?>">
        <?php echo esc_html($title); ?>
    </a>
    <span class="modtitle-border" <?php echo $style_attribute; ?>></span>
</<?php echo esc_attr($heading_tag); ?>>
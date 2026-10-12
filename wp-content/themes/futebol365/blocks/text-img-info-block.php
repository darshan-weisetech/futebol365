<?php
$fields = get_fields() ?: [];
$info_title = $fields['text_img_info_title'] ?? '';
$para = $fields['text_img_info_para'] ?? '';
$image = $fields['text_img_info_image'] ?? [];
$social_url = $fields['text_img_info_image_social_url'] ?? [];
$link_url = ! empty( $social_url['url'] ) ? esc_url( $social_url['url'] ) : '';
$link_target = ! empty( $social_url['target'] ) ? esc_attr( $social_url['target'] ) : '';
if ( empty( $info_title ) ) :
    return;
endif;
?>

<section class="div-mercado-da-bola bottom-space">
    <div class="column-group half-horizontal-gutters aligncenter">

        <div class="all-50 small-100 tiny-100 small-half-bottom-space tiny-half-bottom-space">

            <?php if ( $info_title ) : ?>
                <div class="quarter-bottom-space large condensed-700">
                    <?php if ( $link_url ) : ?>
                        <a href="<?php echo $link_url; ?>"<?php echo $link_target ? ' target="' . $link_target . '"' : ''; ?>>
                            <?php echo esc_html( $info_title ); ?>
                        </a>
                    <?php else : ?>
                        <?php echo esc_html( $info_title ); ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ( $para ) : ?>
                <div class="condensed-400">
                    <?php if ( $link_url ) : ?>
                        <a href="<?php echo $link_url; ?>"<?php echo $link_target ? ' target="' . $link_target . '"' : ''; ?>>
                            <?php echo esc_html( $para ); ?>
                        </a>
                    <?php else : ?>
                        <?php echo esc_html( $para ); ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

        </div>

        <div class="all-50 small-100 tiny-100 small-half-top-space tiny-half-top-space">

            <?php if ( ! empty( $image['url'] ) ) : ?>
                <figure>
                    <?php if ( $link_url ) : ?>
                        <a href="<?php echo $link_url; ?>"<?php echo $link_target ? ' target="' . $link_target . '"' : ''; ?>>
                    <?php endif; ?>

                    <img
                        src="<?php echo esc_url( $image['url'] ); ?>"
                        alt="futebol365"
                        title="futebol365">

                    <?php if ( $link_url ) : ?>
                        </a>
                    <?php endif; ?>
                </figure>
            <?php endif; ?>

        </div>

    </div>
</section>
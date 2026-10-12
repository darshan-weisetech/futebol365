<?php
/**
 * Single Photo Gallery detail from imported Django tables.
 *
 * Paste after:
 * <div class="breadcrumbs-f365"> ... </div>
 *
 * URL example:
 * /multimedia/fotogalerias/977/
 */

global $wpdb;

if (!function_exists('f365_pg_media_url')) {
    function f365_pg_media_url() {
        if (defined('F365_MEDIA_URL')) {
            return rtrim(F365_MEDIA_URL, '/');
        }

        $opt = get_option('f365_media_url');
        if ($opt) {
            return rtrim($opt, '/');
        }

        return 'https://media.futebol365.pt';
    }
}

if (!function_exists('f365_pg_image_url')) {
    function f365_pg_image_url($image) {
        $image = trim((string) $image);

        if ($image === '') {
            $image = 'images/photos/no-image.jpg';
        }

        if (preg_match('#^https?://#i', $image)) {
            return $image;
        }

        $image = preg_replace('#^/?media/#', '', $image);
        return f365_pg_media_url() . '/' . ltrim($image, '/');
    }
}

if (!function_exists('f365_pg_id_from_url')) {
    function f365_pg_id_from_url() {
        $uri = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '';

        if (preg_match('#/multimedia/fotogalerias/(\d+)/?#', $uri, $m)) {
            return absint($m[1]);
        }

        if (isset($_GET['gallery_id'])) {
            return absint($_GET['gallery_id']);
        }

        return absint(get_the_ID());
    }
}

if (!function_exists('f365_pg_gallery_url')) {
    function f365_pg_gallery_url($id) {
        return home_url('/multimedia/fotogalerias/' . absint($id) . '/');
    }
}

if (!function_exists('f365_pg_get_related_links')) {
    function f365_pg_get_related_links($gallery) {
        global $wpdb;

        $links = array();

        if (!empty($gallery->competition_id)) {
            $competition = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT id, name, edition, federation_competition_id
                     FROM {$wpdb->prefix}competition_competition
                     WHERE id = %d",
                    $gallery->competition_id
                )
            );

            if ($competition) {
                $links[] = array(
                    'title' => $competition->name,
                    'url'   => add_query_arg(
                        'edition',
                        $competition->edition,
                        home_url('/competicao/' . absint($competition->federation_competition_id) . '/')
                    ),
                );
            }
        }

        if (!empty($gallery->match_id)) {
            $has_players = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*)
                     FROM {$wpdb->prefix}competition_matchplayer
                     WHERE match_id = %d",
                    $gallery->match_id
                )
            );

            if ($has_players > 0) {
                $links[] = array(
                    'title' => 'Ficha do jogo',
                    'url'   => home_url('/jogo/' . absint($gallery->match_id) . '/'),
                );
            }
        }

        $team_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT t.id, t.shortname, t.longname
                 FROM {$wpdb->prefix}multimedia_photogallery_teams rel
                 INNER JOIN {$wpdb->prefix}team_team t ON t.id = rel.team_id
                 WHERE rel.photogallery_id = %d
                 ORDER BY t.shortname",
                $gallery->id
            )
        );

        foreach ($team_rows as $team) {
            $links[] = array(
                'title' => $team->shortname ?: $team->longname,
                'url'   => home_url('/equipa/' . absint($team->id) . '/jogos/'),
            );
        }

        $player_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT pl.id, pe.shortname, pe.longname
                 FROM {$wpdb->prefix}multimedia_photogallery_players rel
                 INNER JOIN {$wpdb->prefix}person_player pl ON pl.id = rel.player_id
                 INNER JOIN {$wpdb->prefix}person_person pe ON pe.id = pl.person_id
                 WHERE rel.photogallery_id = %d
                 ORDER BY pe.shortname",
                $gallery->id
            )
        );

        foreach ($player_rows as $player) {
            $links[] = array(
                'title' => $player->shortname ?: $player->longname,
                'url'   => home_url('/jogador/' . absint($player->id) . '/'),
            );
        }

        $coach_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT c.id, c.coach_name, pe.shortname, pe.longname
                 FROM {$wpdb->prefix}multimedia_photogallery_coaches rel
                 INNER JOIN {$wpdb->prefix}person_coach c ON c.id = rel.coach_id
                 LEFT JOIN {$wpdb->prefix}person_person pe ON pe.id = c.person_id
                 WHERE rel.photogallery_id = %d
                 ORDER BY c.coach_name",
                $gallery->id
            )
        );

        foreach ($coach_rows as $coach) {
            $links[] = array(
                'title' => $coach->coach_name ?: ($coach->shortname ?: $coach->longname),
                'url'   => home_url('/treinador/' . absint($coach->id) . '/'),
            );
        }

        $president_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT pr.id, pe.shortname, pe.longname
                 FROM {$wpdb->prefix}multimedia_photogallery_presidents rel
                 INNER JOIN {$wpdb->prefix}person_president pr ON pr.id = rel.president_id
                 INNER JOIN {$wpdb->prefix}person_person pe ON pe.id = pr.person_id
                 WHERE rel.photogallery_id = %d
                 ORDER BY pe.shortname",
                $gallery->id
            )
        );

        foreach ($president_rows as $president) {
            $links[] = array(
                'title' => $president->shortname ?: $president->longname,
                'url'   => home_url('/dirigente/' . absint($president->id) . '/'),
            );
        }

        $referee_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT r.id, pe.shortname, pe.longname
                 FROM {$wpdb->prefix}multimedia_photogallery_referees rel
                 INNER JOIN {$wpdb->prefix}person_referee r ON r.id = rel.referee_id
                 INNER JOIN {$wpdb->prefix}person_person pe ON pe.id = r.person_id
                 WHERE rel.photogallery_id = %d
                 ORDER BY pe.shortname",
                $gallery->id
            )
        );

        foreach ($referee_rows as $referee) {
            $links[] = array(
                'title' => $referee->shortname ?: $referee->longname,
                'url'   => home_url('/arbitro/' . absint($referee->id) . '/'),
            );
        }

        return $links;
    }
}

$gallery_id = f365_pg_id_from_url();

$gallery = $wpdb->get_row(
    $wpdb->prepare(
        "SELECT *
         FROM {$wpdb->prefix}multimedia_photogallery
         WHERE id = %d
           AND is_published = 1",
        $gallery_id
    )
);

if (!$gallery) {
    echo '<p class="f365-no-results">Fotogaleria nao encontrada.</p>';
    return;
}

$gallery_images = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT
            p.id,
            p.title,
            p.image
         FROM {$wpdb->prefix}multimedia_photogalleryphotos pgp
         INNER JOIN {$wpdb->prefix}multimedia_photo p ON p.id = pgp.photo_id
         WHERE pgp.photogallery_id = %d
         ORDER BY pgp.id ASC",
        $gallery_id
    )
);

$related_links = f365_pg_get_related_links($gallery);

$latest_galleries = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT
            pg.id,
            pg.title,
            COALESCE(NULLIF(pg.image, ''), first_photo.image, 'images/photos/no-image.jpg') AS front_image,
            pg.datetime
         FROM {$wpdb->prefix}multimedia_photogallery pg
         LEFT JOIN (
            SELECT pgp.photogallery_id, MIN(p.image) AS image
            FROM {$wpdb->prefix}multimedia_photogalleryphotos pgp
            INNER JOIN {$wpdb->prefix}multimedia_photo p ON p.id = pgp.photo_id
            GROUP BY pgp.photogallery_id
         ) AS first_photo ON first_photo.photogallery_id = pg.id
         WHERE pg.is_published = 1
           AND pg.id <> %d
         ORDER BY pg.datetime DESC
         LIMIT 8",
        $gallery_id
    )
);
?>

<div class="photogallery-panel">
    <div id="main_carousel" class="ink-carousel">
        <ul class="stage">
            <?php if (!empty($gallery_images)) : ?>
                <?php foreach ($gallery_images as $index => $photo) : ?>
                    <li class="slide all-100">
                        <figure class="ink-image">
                            <img
                                src="<?php echo esc_url(f365_pg_image_url($photo->image)); ?>"
                                alt="<?php echo esc_attr($photo->title ?: 'fotogaleria'); ?>"
                                loading="<?php echo $index === 0 ? 'eager' : 'lazy'; ?>">

                            <?php if (!empty($photo->title)) : ?>
                                <figcaption class="over-bottom dark fw-400 medium uppercase">
                                    <?php echo esc_html($photo->title); ?>
                                </figcaption>
                            <?php endif; ?>
                        </figure>
                    </li>
                <?php endforeach; ?>
            <?php else : ?>
                <li class="slide all-100">
                    <figure class="ink-image">
                        <img
                            src="<?php echo esc_url(f365_pg_image_url($gallery->image)); ?>"
                            alt="<?php echo esc_attr($gallery->title); ?>"
                            loading="eager">
                    </figure>
                </li>
            <?php endif; ?>
        </ul>

        <nav id="pagination_main" class="ink-navigation top-space">
            <ul class="pagination chevron"></ul>
        </nav>
    </div>

    <?php if (!empty($gallery_images)) : ?>
        <div id="thumbs_carousel" class="ink-carousel">
            <ul class="stage column-group half-horizontal-gutters">
                <?php foreach ($gallery_images as $index => $photo) : ?>
                    <li class="slide all-20 <?php echo $index === 0 ? 'active' : ''; ?>">
                        <a href="#">
                            <img
                                src="<?php echo esc_url(f365_pg_image_url($photo->image)); ?>"
                                alt="<?php echo esc_attr($photo->title ?: 'fotogaleria'); ?>"
                                loading="lazy">
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>

            <nav id="pagination_thumbs" class="ink-navigation top-space">
                <ul class="pagination dotted"></ul>
            </nav>
        </div>
    <?php endif; ?>
</div>

<div class="column-group gutters top-space">
    <div class="all-100">
        <h2 class="video-title"><?php echo esc_html($gallery->title); ?></h2>

        <h5 class="fw-500 video-date-and-views">
            <?php echo esc_html(wp_date('d-m-Y', strtotime($gallery->datetime))); ?>
            -
            <?php echo esc_html(number_format_i18n((int) $gallery->views)); ?>
            visualizações
        </h5>

        <?php if (!empty($related_links)) : ?>
            <div class="button-toolbar vertical-padding">
                <?php foreach ($related_links as $related_link) : ?>
                    <div class="button-group">
                        <a class="ink-button" href="<?php echo esc_url($related_link['url']); ?>">
                            <?php echo esc_html($related_link['title']); ?>
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="top-space">
            <ins
                class="adsbygoogle"
                style="display:block;text-align:center;"
                data-ad-layout="in-article"
                data-ad-format="fluid"
                data-ad-client="ca-pub-9256365552404109"
                data-ad-slot="9072662316"></ins>
            <script>(adsbygoogle = window.adsbygoogle || []).push({});</script>
        </div>
    </div>
</div>

<?php if (!empty($latest_galleries)) : ?>
    <h2 class="condensed-400 extralarge modtitle">
        Mais recentes
        <span class="modtitle-border"></span>
    </h2>

    <div class="column-group gutters">
        <?php foreach ($latest_galleries as $latest_gallery) : ?>
            <div class="all-25 small-50 tiny-100">
                <a href="<?php echo esc_url(f365_pg_gallery_url($latest_gallery->id)); ?>">
                    <figure class="ink-image ink-lazyload half-bottom-space my-thumb-3-2">
                        <img
                            class="lazyload-item my-thumb-image"
                            src="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAoAAAABCAQAAABN/Pf1AAAADUlEQVR42mM0/s+AAQAXEwE06Vwt2gAAAABJRU5ErkJggg=="
                            data-src="<?php echo esc_url(f365_pg_image_url($latest_gallery->front_image)); ?>"
                            title="<?php echo esc_attr($latest_gallery->title); ?>"
                            alt="FUTEBOL 365"
                            loading="lazy">
                    </figure>
                </a>

                <h3 class="quarter-vertical-space medium uppercase condensed-400">
                    <?php echo esc_html(wp_date('d-m-Y', strtotime($latest_gallery->datetime))); ?>
                </h3>

                <h4 class="large condensed-700">
                    <a href="<?php echo esc_url(f365_pg_gallery_url($latest_gallery->id)); ?>">
                        <?php echo esc_html($latest_gallery->title); ?>
                    </a>
                </h4>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    if (typeof Ink === 'undefined' || !Ink.requireModules) {
        return;
    }

    Ink.requireModules(['Ink.UI.Carousel_1', 'Ink.Dom.Event_1', 'Ink.Dom.Element_1', 'Ink.Dom.Css_1'], function (Carousel, InkEvent, InkElement, InkCss) {
        var main = new Carousel('#main_carousel', { pagination: '#pagination_main', onChange: onMainChange });
        var thumbs = document.querySelector('#thumbs_carousel') ? new Carousel('#thumbs_carousel', { pagination: '#pagination_thumbs' }) : null;

        function onMainChange(page) {
            if (!main || !thumbs) {
                return;
            }

            var slides = thumbs.getSlidesList();
            for (var i = 0; i < slides.length; i++) {
                InkCss.removeClassName(slides[i], 'active');
            }

            if (slides[page]) {
                InkCss.addClassName(slides[page], 'active');
            }
        }

        if (thumbs) {
            InkEvent.observe(thumbs.getStageElm(), 'click', function (event) {
                InkEvent.stopDefault(event);
                var target = InkEvent.element(event);
                var slide = InkElement.findUpwardsByClass(target, 'slide');
                var slides = thumbs.getSlidesList();

                for (var i = 0; i < slides.length; i++) {
                    if (slides[i] === slide) {
                        main.setPage(i);
                        return;
                    }
                }
            });
        }
    });
});
</script>

    <?php
    /**
     * The header for our theme
     *
     * @package Futebol365
     */

    ?>
    <!doctype html>
    <html <?php language_attributes(); ?>>
    <head>
        <meta charset="<?php bloginfo( 'charset' ); ?>">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link rel="profile" href="https://gmpg.org/xfn/11">
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Roboto:ital,wdth,wght@0,75..100,100..900;1,75..100,100..900&family=Roboto+Condensed:ital,wght@0,100..900;1,100..900&family=Roboto+Slab:wght@100..900&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

        <!-- Google Tag Manager -->
            <script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
            new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
            j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
            'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
            })(window,document,'script','dataLayer','GTM-KCHDVQXQ');</script>
            <!-- End Google Tag Manager -->
        <?php wp_head(); ?>
    </head>

    <body <?php body_class(); ?>>
        <!-- Google Tag Manager (noscript) -->
        <noscript>
            <iframe
                src="https://www.googletagmanager.com/ns.html?id=GTM-KCHDVQXQ"
                height="0"
                width="0"
                style="display:none;visibility:hidden">
            </iframe>
        </noscript>
        <!-- End Google Tag Manager (noscript) -->

        <?php
        /*
        * SVG sprite sheet — fully static, safe to cache in the object cache.
        * Cached separately so it can be reused and never needs the live clock swap.
        */
        $f365_header = wp_cache_get( 'f365_header_sprite', 'theme' );
        if ( false === $f365_header ) {
            ob_start();
            ?>
            <svg xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false" style="position:absolute;width:0;height:0;overflow:hidden">
                <symbol id="ui-search" viewBox="0 0 16 16"><circle cx="6.5" cy="6.5" r="4.2" fill="none" stroke="currentColor" stroke-width="1.7"/><path d="M9.75 9.75l4 4" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></symbol>
                <symbol id="icon-menu-bars" viewBox="0 0 16 16"><path d="M2.5 3.5h11M2.5 8h11M2.5 12.5h11" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></symbol>
                <symbol id="icon-xmark" viewBox="0 0 16 16"><path d="M3 3l10 10M13 3L3 13" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></symbol>
                <symbol id="icon-clock" viewBox="0 0 16 16"><circle cx="8" cy="8" r="5.2" fill="none" stroke="currentColor" stroke-width="1.55"/><path d="M8 4.4v3.8l2.6 1.95" fill="none" stroke="currentColor" stroke-width="1.55" stroke-linecap="round" stroke-linejoin="round"/></symbol>
                <symbol id="icon-play" viewBox="0 0 16 16"><circle cx="8" cy="8" r="5.3" fill="none" stroke="currentColor" stroke-width="1.3"/><path fill="currentColor" d="M6.6 5.45l4.2 2.55-4.2 2.55z"/></symbol>
                <symbol id="icon-chevron-left" viewBox="0 0 320 512"><path fill="currentColor" d="M34.9 239l194.3-194.3c9.4-9.4 24.6-9.4 33.9 0l22.6 22.6c9.4 9.4 9.4 24.6 0 33.9L131.5 256l154.2 154.8c9.4 9.4 9.4 24.6 0 33.9l-22.6 22.6c-9.4 9.4-24.6 9.4-33.9 0L34.9 273c-9.4-9.4-9.4-24.6 0-34z"/></symbol>
                <symbol id="icon-chevron-right" viewBox="0 0 320 512"><path fill="currentColor" d="M285.1 273L90.8 467.3c-9.4 9.4-24.6 9.4-33.9 0l-22.6-22.6c-9.4-9.4-9.4-24.6 0-33.9L188.5 256 34.3 101.2c-9.4-9.4-9.4-24.6 0-33.9l22.6-22.6c9.4-9.4 24.6-9.4 33.9 0L285.1 239c9.4 9.4 9.4 24.6 0 34z"/></symbol>
                <symbol id="social-facebook" viewBox="0 0 16 16"><path fill="currentColor" d="M16 8.049c0-4.446-3.582-8.05-8-8.05C3.58 0-.002 3.603-.002 8.05c0 4.017 2.926 7.347 6.75 7.951v-5.625h-2.03V8.05H6.75V6.275c0-2.017 1.195-3.131 3.022-3.131.876 0 1.791.157 1.791.157v1.98h-1.009c-.993 0-1.303.621-1.303 1.258v1.51h2.218l-.354 2.326H9.25V16c3.824-.604 6.75-3.934 6.75-7.951"/></symbol>
                <symbol id="social-x" viewBox="0 0 16 16"><path fill="currentColor" d="M12.6.75h2.454l-5.36 6.142L16 15.25h-4.937l-3.867-5.07-4.425 5.07H.316l5.733-6.57L0 .75h5.063l3.495 4.633L12.601.75Zm-.86 13.028h1.36L4.323 2.145H2.865z"/></symbol>
                <symbol id="social-instagram" viewBox="0 0 16 16"><path fill="currentColor" d="M8 0C5.829 0 5.556.01 4.703.048 3.85.088 3.269.222 2.76.42a3.9 3.9 0 0 0-1.417.923A3.9 3.9 0 0 0 .42 2.76C.222 3.268.087 3.85.048 4.7.01 5.555 0 5.827 0 8.001c0 2.172.01 2.444.048 3.297.04.852.174 1.433.372 1.942.205.526.478.972.923 1.417.444.445.89.719 1.416.923.51.198 1.09.333 1.942.372C5.555 15.99 5.827 16 8 16s2.444-.01 3.298-.048c.851-.04 1.434-.174 1.943-.372a3.9 3.9 0 0 0 1.416-.923c.445-.445.718-.891.923-1.417.197-.509.332-1.09.372-1.942C15.99 10.445 16 10.173 16 8s-.01-2.445-.048-3.299c-.04-.851-.175-1.433-.372-1.941a3.9 3.9 0 0 0-.923-1.417A3.9 3.9 0 0 0 13.24.42c-.51-.198-1.092-.333-1.943-.372C10.443.01 10.172 0 7.998 0zm-.717 1.442h.718c2.136 0 2.389.007 3.232.046.78.035 1.204.166 1.486.275.373.145.64.319.92.599s.453.546.598.92c.11.281.24.705.275 1.485.039.843.047 1.096.047 3.231s-.008 2.389-.047 3.232c-.035.78-.166 1.203-.275 1.485a2.5 2.5 0 0 1-.599.919c-.28.28-.546.453-.92.598-.28.11-.704.24-1.485.276-.843.038-1.096.047-3.232.047s-2.39-.009-3.233-.047c-.78-.036-1.203-.166-1.485-.276a2.5 2.5 0 0 1-.92-.598 2.5 2.5 0 0 1-.6-.92c-.109-.281-.24-.705-.275-1.485-.038-.843-.046-1.096-.046-3.233s.008-2.388.046-3.231c.036-.78.166-1.204.276-1.486.145-.373.319-.64.599-.92s.546-.453.92-.598c.282-.11.705-.24 1.485-.276.738-.034 1.024-.044 2.515-.045zm4.988 1.328a.96.96 0 1 0 0 1.92.96.96 0 0 0 0-1.92m-4.27 1.122a4.109 4.109 0 1 0 0 8.217 4.109 4.109 0 0 0 0-8.217m0 1.441a2.667 2.667 0 1 1 0 5.334 2.667 2.667 0 0 1 0-5.334"/></symbol>
                <symbol id="social-threads" viewBox="0 0 16 16"><path fill="currentColor" d="M6.321 6.016c-.27-.18-1.166-.802-1.166-.802.756-1.081 1.753-1.502 3.132-1.502.975 0 1.803.327 2.394.948s.928 1.509 1.005 2.644q.492.207.905.484c1.109.745 1.719 1.86 1.719 3.137 0 2.716-2.226 5.075-6.256 5.075C4.594 16 1 13.987 1 7.994 1 2.034 4.482 0 8.044 0 9.69 0 13.55.243 15 5.036l-1.36.353C12.516 1.974 10.163 1.43 8.006 1.43c-3.565 0-5.582 2.171-5.582 6.79 0 4.143 2.254 6.343 5.63 6.343 2.777 0 4.847-1.443 4.847-3.556 0-1.438-1.208-2.127-1.27-2.127-.236 1.234-.868 3.31-3.644 3.31-1.618 0-3.013-1.118-3.013-2.582 0-2.09 1.984-2.847 3.55-2.847.586 0 1.294.04 1.663.114 0-.637-.54-1.728-1.9-1.728-1.25 0-1.566.405-1.967.868ZM8.716 8.19c-2.04 0-2.304.87-2.304 1.416 0 .878 1.043 1.168 1.6 1.168 1.02 0 2.067-.282 2.232-2.423a6.2 6.2 0 0 0-1.528-.161"/></symbol>
                <symbol id="social-youtube" viewBox="0 0 16 16"><path fill="currentColor" d="M8.051 1.999h.089c.822.003 4.987.033 6.11.335a2.01 2.01 0 0 1 1.415 1.42c.101.38.172.883.22 1.402l.01.104.022.26.008.104c.065.914.073 1.77.074 1.957v.075c-.001.194-.01 1.108-.082 2.06l-.008.105-.009.104c-.05.572-.124 1.14-.235 1.558a2.01 2.01 0 0 1-1.415 1.42c-1.16.312-5.569.334-6.18.335h-.142c-.309 0-1.587-.006-2.927-.052l-.17-.006-.087-.004-.171-.007-.171-.007c-1.11-.049-2.167-.128-2.654-.26a2.01 2.01 0 0 1-1.415-1.419c-.111-.417-.185-.986-.235-1.558L.09 9.82l-.008-.104A31 31 0 0 1 0 7.68v-.123c.002-.215.01-.958.064-1.778l.007-.103.003-.052.008-.104.022-.26.01-.104c.048-.519.119-1.023.22-1.402a2.01 2.01 0 0 1 1.415-1.42c.487-.13 1.544-.21 2.654-.26l.17-.007.172-.006.086-.003.171-.007A100 100 0 0 1 7.858 2zM6.4 5.209v4.818l4.157-2.408z"/></symbol>
                <symbol id="social-tiktok" viewBox="0 0 16 16"><path fill="currentColor" d="M9.75.75c.154 1.298.884 2.386 2.05 3.04.681.383 1.455.58 2.25.576v2.181a6.3 6.3 0 0 1-2.917-.714l-.003 4.51c0 2.682-2.196 4.907-4.905 4.907A4.91 4.91 0 0 1 1.32 10.34c0-2.71 2.197-4.907 4.905-4.907.225 0 .45.015.672.046v2.27a2.76 2.76 0 0 0-.672-.083 2.678 2.678 0 0 0-2.674 2.675 2.678 2.678 0 0 0 2.674 2.676 2.68 2.68 0 0 0 2.677-2.676V.75H9.75z"/></symbol>
                <symbol id="social-whatsapp" viewBox="0 0 16 16"><path fill="currentColor" d="M13.601 2.326A7.85 7.85 0 0 0 7.994 0C3.627 0 .068 3.558.064 7.926c0 1.399.366 2.76 1.057 3.965L0 16l4.204-1.102a7.9 7.9 0 0 0 3.79.965h.004c4.368 0 7.926-3.558 7.93-7.93A7.9 7.9 0 0 0 13.6 2.326zM7.994 14.521a6.6 6.6 0 0 1-3.356-.92l-.24-.144-2.494.654.666-2.433-.156-.251a6.56 6.56 0 0 1-1.007-3.505c0-3.626 2.957-6.584 6.591-6.584a6.56 6.56 0 0 1 4.66 1.931 6.56 6.56 0 0 1 1.928 4.66c-.004 3.639-2.961 6.592-6.592 6.592m3.615-4.934c-.197-.099-1.17-.578-1.353-.646-.182-.065-.315-.099-.445.099-.133.197-.513.646-.627.775-.114.133-.232.148-.43.05-.197-.1-.836-.308-1.592-.985-.59-.525-.985-1.175-1.103-1.372-.114-.198-.011-.304.088-.403.087-.088.197-.232.296-.346.1-.114.133-.198.198-.33.065-.134.034-.248-.015-.347-.05-.099-.445-1.076-.612-1.47-.16-.389-.323-.335-.445-.34-.114-.007-.247-.007-.38-.007a.73.73 0 0 0-.529.247c-.182.198-.691.677-.691 1.654s.71 1.916.81 2.049c.098.133 1.394 2.132 3.383 2.992.47.205.84.326 1.129.418.475.152.904.129 1.246.08.38-.058 1.171-.48 1.338-.943.164-.464.164-.86.114-.943-.049-.084-.182-.133-.38-.232"/></symbol>
                <symbol id="social-telegram" viewBox="0 0 16 16"><path fill="currentColor" d="M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0M8.287 5.906q-1.168.486-4.666 2.01-.567.225-.595.442c-.03.243.275.339.69.47l.175.055c.408.133.958.288 1.243.294q.39.01.868-.32 3.269-2.206 3.374-2.23c.05-.012.12-.026.166.016s.042.12.037.141c-.03.129-1.227 1.241-1.846 1.817-.193.18-.33.307-.358.336a8 8 0 0 1-.188.186c-.38.366-.664.64.015 1.088.327.216.589.393.85.571.284.194.568.387.936.629q.14.092.27.187c.331.236.63.448.997.414.214-.02.435-.22.547-.82.265-1.417.786-4.486.906-5.751a1.4 1.4 0 0 0-.013-.315.34.34 0 0 0-.114-.217.53.53 0 0 0-.31-.093c-.3.005-.763.166-2.984 1.09"/></symbol>
                <symbol id="social-linkedin" viewBox="0 0 16 16"><path fill="currentColor" d="M0 1.146C0 .513.526 0 1.175 0h13.65C15.474 0 16 .513 16 1.146v13.708c0 .633-.526 1.146-1.175 1.146H1.175C.526 16 0 15.487 0 14.854zm4.943 12.248V6.169H2.542v7.225zm-1.2-8.212c.837 0 1.358-.554 1.358-1.248-.015-.709-.52-1.248-1.342-1.248S2.4 3.226 2.4 3.934c0 .694.521 1.248 1.327 1.248zm4.908 8.212V9.359c0-.216.016-.432.08-.586.173-.431.568-.878 1.232-.878.869 0 1.216.662 1.216 1.634v3.865h2.401V9.25c0-2.22-1.184-3.252-2.764-3.252-1.274 0-1.845.7-2.165 1.193v.025h-.016l.016-.025V6.169h-2.4c.03.678 0 7.225 0 7.225z"/></symbol>
                <symbol id="social-rss" viewBox="0 0 16 16"><path fill="currentColor" d="M2 0a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V2a2 2 0 0 0-2-2zm1.5 2.5c5.523 0 10 4.477 10 10a1 1 0 1 1-2 0 8 8 0 0 0-8-8 1 1 0 0 1 0-2m0 4a6 6 0 0 1 6 6 1 1 0 1 1-2 0 4 4 0 0 0-4-4 1 1 0 0 1 0-2m.5 7a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3"/></symbol>
                <symbol id="icon-arrow-up" viewBox="0 0 1664 1792"><path fill="currentColor" transform="translate(0 1536) scale(1 -1)" d="M1611 565q0 -51 -37 -90l-75 -75q-38 -38 -91 -38q-54 0 -90 38l-294 293v-704q0 -52 -37.5 -84.5t-90.5 -32.5h-128q-53 0 -90.5 32.5t-37.5 84.5v704l-294 -293q-36 -38 -90 -38t-90 38l-75 75q-38 38 -38 90q0 53 38 91l651 651q35 37 90 37q54 0 91 -37l651 -651 q37 -39 37 -91z"/></symbol>
                <symbol id="icon-check" viewBox="0 0 1792 1792"><path fill="currentColor" transform="translate(0 1536) scale(1 -1)" d="M1671 970q0 -40 -28 -68l-724 -724l-136 -136q-28 -28 -68 -28t-68 28l-136 136l-362 362q-28 28 -28 68t28 68l136 136q28 28 68 28t68 -28l294 -295l656 657q28 28 68 28t68 -28l136 -136q28 -28 28 -68z"/></symbol>
                <symbol id="icon-chevron-down" viewBox="0 0 1792 1792"><path fill="currentColor" transform="translate(0 1536) scale(1 -1)" d="M1683 728l-742 -741q-19 -19 -45 -19t-45 19l-742 741q-19 19 -19 45.5t19 45.5l166 165q19 19 45 19t45 -19l531 -531l531 531q19 19 45 19t45 -19l166 -165q19 -19 19 -45.5t-19 -45.5z"/></symbol>
                <symbol id="icon-chevron-up" viewBox="0 0 1792 1792"><path fill="currentColor" transform="translate(0 1536) scale(1 -1)" d="M1683 205l-166 -165q-19 -19 -45 -19t-45 19l-531 531l-531 -531q-19 -19 -45 -19t-45 19l-166 165q-19 19 -19 45.5t19 45.5l742 741q19 19 45 19t45 -19l742 -741q19 -19 19 -45.5t-19 -45.5z"/></symbol>
                <symbol id="icon-envelope" viewBox="0 0 1792 1792"><path fill="currentColor" transform="translate(0 1536) scale(1 -1)" d="M1664 32v768q-32 -36 -69 -66q-268 -206 -426 -338q-51 -43 -83 -67t-86.5 -48.5t-102.5 -24.5h-1h-1q-48 0 -102.5 24.5t-86.5 48.5t-83 67q-158 132 -426 338q-37 30 -69 66v-768q0 -13 9.5 -22.5t22.5 -9.5h1472q13 0 22.5 9.5t9.5 22.5zM1664 1083v11v13.5t-0.5 13 t-3 12.5t-5.5 9t-9 7.5t-14 2.5h-1472q-13 0 -22.5 -9.5t-9.5 -22.5q0 -168 147 -284q193 -152 401 -317q6 -5 35 -29.5t46 -37.5t44.5 -31.5t50.5 -27.5t43 -9h1h1q20 0 43 9t50.5 27.5t44.5 31.5t46 37.5t35 29.5q208 165 401 317q54 43 100.5 115.5t46.5 131.5z M1792 1120v-1088q0 -66 -47 -113t-113 -47h-1472q-66 0 -113 47t-47 113v1088q0 66 47 113t113 47h1472q66 0 113 -47t47 -113z"/></symbol>
                <symbol id="icon-home" viewBox="0 0 1664 1792"><path fill="currentColor" transform="translate(0 1536) scale(1 -1)" d="M1408 544v-480q0 -26 -19 -45t-45 -19h-384v384h-256v-384h-384q-26 0 -45 19t-19 45v480q0 1 0.5 3t0.5 3l575 474l575 -474q1 -2 1 -6zM1631 613l-62 -74q-8 -9 -21 -11h-3q-13 0 -21 7l-692 577l-692 -577q-12 -8 -24 -7q-13 2 -21 11l-62 74q-8 10 -7 23.5t11 21.5 l719 599q32 26 76 26t76 -26l244 -204v195q0 14 9 23t23 9h192q14 0 23 -9t9 -23v-408l219 -182q10 -8 11 -21.5t-7 -23.5z"/></symbol>
                <symbol id="icon-link" viewBox="0 0 1664 1792"><path fill="currentColor" transform="translate(0 1536) scale(1 -1)" d="M1456 320q0 40 -28 68l-208 208q-28 28 -68 28q-42 0 -72 -32q3 -3 19 -18.5t21.5 -21.5t15 -19t13 -25.5t3.5 -27.5q0 -40 -28 -68t-68 -28q-15 0 -27.5 3.5t-25.5 13t-19 15t-21.5 21.5t-18.5 19q-33 -31 -33 -73q0 -40 28 -68l206 -207q27 -27 68 -27q40 0 68 26 l147 146q28 28 28 67zM753 1025q0 40 -28 68l-206 207q-28 28 -68 28q-39 0 -68 -27l-147 -146q-28 -28 -28 -67q0 -40 28 -68l208 -208q27 -27 68 -27q42 0 72 31q-3 3 -19 18.5t-21.5 21.5t-15 19t-13 25.5t-3.5 27.5q0 40 28 68t68 28q15 0 27.5 -3.5t25.5 -13t19 -15 t21.5 -21.5t18.5 -19q33 31 33 73zM1648 320q0 -120 -85 -203l-147 -146q-83 -83 -203 -83q-121 0 -204 85l-206 207q-83 83 -83 203q0 123 88 209l-88 88q-86 -88 -208 -88q-120 0 -204 84l-208 208q-84 84 -84 204t85 203l147 146q83 83 203 83q121 0 204 -85l206 -207 q83 -83 83 -203q0 -123 -88 -209l88 -88q86 88 208 88q120 0 204 -84l208 -208q84 -84 84 -204z"/></symbol>
                <symbol id="icon-plus" viewBox="0 0 1408 1792"><path fill="currentColor" transform="translate(0 1536) scale(1 -1)" d="M1408 800v-192q0 -40 -28 -68t-68 -28h-416v-416q0 -40 -28 -68t-68 -28h-192q-40 0 -68 28t-28 68v416h-416q-40 0 -68 28t-28 68v192q0 40 28 68t68 28h416v416q0 40 28 68t68 28h192q40 0 68 -28t28 -68v-416h416q40 0 68 -28t28 -68z"/></symbol>
                <symbol id="icon-plus-square" viewBox="0 0 1536 1792"><path fill="currentColor" transform="translate(0 1536) scale(1 -1)" d="M1280 576v128q0 26 -19 45t-45 19h-320v320q0 26 -19 45t-45 19h-128q-26 0 -45 -19t-19 -45v-320h-320q-26 0 -45 -19t-19 -45v-128q0 -26 19 -45t45 -19h320v-320q0 -26 19 -45t45 -19h128q26 0 45 19t19 45v320h320q26 0 45 19t19 45zM1536 1120v-960 q0 -119 -84.5 -203.5t-203.5 -84.5h-960q-119 0 -203.5 84.5t-84.5 203.5v960q0 119 84.5 203.5t203.5 84.5h960q119 0 203.5 -84.5t84.5 -203.5z"/></symbol>
                <symbol id="icon-square-outline" viewBox="0 0 1408 1792"><path fill="currentColor" transform="translate(0 1536) scale(1 -1)" d="M1120 1280h-832q-66 0 -113 -47t-47 -113v-832q0 -66 47 -113t113 -47h832q66 0 113 47t47 113v832q0 66 -47 113t-113 47zM1408 1120v-832q0 -119 -84.5 -203.5t-203.5 -84.5h-832q-119 0 -203.5 84.5t-84.5 203.5v832q0 119 84.5 203.5t203.5 84.5h832 q119 0 203.5 -84.5t84.5 -203.5z"/></symbol>
            </svg>
            <?php
            $f365_header = ob_get_clean();
            wp_cache_set( 'f365_header_sprite', $f365_header, 'theme' );
        }
        echo $f365_header;

        ?>

        <div id="page" class="site">
            <header id="masthead" class="header-block">

                <?php
                /*
                * Cached header shell (logos, tabs, social links, menus). The timezone
                * clock is NOT cached: it is per-user (each visitor's chosen timezone)
                * and time-sensitive, so we leave a placeholder and render it live just
                * after pulling the cached HTML out of the object cache.
                */
                $f365_header_shell = wp_cache_get( 'f365_header_shell', 'theme' );

                if ( false === $f365_header_shell ) {
                    ob_start();
                    ?>
                    <div class="top-tab-block">
                        <ul class="nav nav-tabs" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" id="topTab-1" data-bs-toggle="tab" data-bs-target="#topTab-1-pane" type="button" role="tab" aria-controls="topTab-1-pane" aria-selected="true">Jogos em direto</button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="topTab-2" data-bs-toggle="tab" data-bs-target="#topTab-2-pane" type="button" role="tab" aria-controls="topTab-2-pane" aria-selected="false">Jogos terminados</button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="topTab-3" data-bs-toggle="tab" data-bs-target="#topTab-3-pane" type="button" role="tab" aria-controls="topTab-3-pane" aria-selected="false">Próximos jogos</button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="topTab-4" data-bs-toggle="tab" data-bs-target="#topTab-4-pane" type="button" role="tab" aria-controls="topTab-4-pane" aria-selected="false">Jogos na televisão</button>
                            </li>
                        </ul>
                        <div class="tab-content">
                            <div class="tab-pane fade show active" id="topTab-1-pane" role="tabpanel" aria-labelledby="topTab-1" tabindex="0">1</div>
                            <div class="tab-pane fade" id="topTab-2-pane" role="tabpanel" aria-labelledby="topTab-2" tabindex="0">2</div>
                            <div class="tab-pane fade" id="topTab-3-pane" role="tabpanel" aria-labelledby="topTab-3" tabindex="0">3</div>
                            <div class="tab-pane fade" id="topTab-4-pane" role="tabpanel" aria-labelledby="topTab-4" tabindex="0">4</div>
                        </div>
                    </div>

                    <div class="last-header-links">

                        <div class="top-head-bar">
                            <div class="ink-grid">
                                <div class="logo-wrap">
                                <?php echo futebol365_get_site_logo(); ?>
                                </div>

                                <div class="right-logo-wrap">
                                    <div class="divRelogioTopo timezone" id="timezone-with-clock"><?php f365_render_timezone_clock(); ?></div>

                                    <div class="top-searchbar">
                                        <?php get_search_form(); ?>
                                    </div>
                                    <?php
                                    $social_links = get_field( 'social_links', 'option' );
                                    if ( ! empty( $social_links ) && is_array( $social_links ) ) :
                                        $social_icons = array(
                                            'facebook'  => 'social-facebook',
                                            'twitter'   => 'social-x',
                                            'instagram' => 'social-instagram',
                                            'threads'   => 'social-threads',
                                            'youtube'   => 'social-youtube',
                                            'tiktok'    => 'social-tiktok',
                                            'whatsapp'  => 'social-whatsapp',
                                            'telegram'  => 'social-telegram',
                                            'linkedin'  => 'social-linkedin',
                                            'rss'       => 'social-rss',
                                        );
                                        ?>
                                        <div class="header-social-link">
                                            <?php
                                            foreach ( $social_links as $link_item ) :
                                                $platform = $link_item['social_platform'] ?? '';
                                                $link     = $link_item['social_url'] ?? '';
                                                if ( empty( $platform ) || empty( $link ) || ! isset( $social_icons[ $platform ] ) ) {
                                                    continue;
                                                }
                                                $icon_class = $social_icons[ $platform ];
                                                $url        = is_array( $link ) ? ( $link['url'] ?? '' ) : $link;
                                                $target     = is_array( $link ) ? ( $link['target'] ?: '_self' ) : '_self';
                                                if ( empty( $url ) ) {
                                                    continue;
                                                }
                                                ?>
                                                <a href="<?php echo esc_url( $url ); ?>" target="<?php echo esc_attr( $target ); ?>" aria-label="<?php echo esc_attr( $platform ); ?>" rel="noopener">
                                                    <svg class="icon-style" viewBox="0 0 16 16" width="1em" height="1em" aria-hidden="true" focusable="false">
                                                        <use href="#<?php echo esc_attr( $icon_class ); ?>"></use>
                                                    </svg>
                                                </a>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <div class="middle-head-bar">
                            <div class="ink-grid">
                                <div class="top-nav">
                                    <div class="mobile-header">
                                        <div class="mobile-logo">
                                            <?php echo futebol365_get_site_logo(); ?>
                                        </div>
                                        <div class="right-icons-block">
                                            <a href="<?php echo esc_url( home_url( '/pesquisa/' ) ); ?>" class="mobile-search-icon" aria-label="<?php esc_attr_e( 'Pesquisa', 'futebol365' ); ?>">
                                                <svg class="menu-mobile-svg" aria-hidden="true" focusable="false"><use href="#ui-search"></use></svg>
                                            </a>
                                            <a href="<?php echo esc_url( home_url( '/fusos-horarios/' ) ); ?>" class="mobile-clock-icon" aria-label="<?php esc_attr_e( 'Fusos Horários', 'futebol365' ); ?>">
                                                <svg class="menu-mobile-svg" aria-hidden="true" focusable="false"><use href="#icon-clock"></use></svg>
                                            </a>
                                            <button type="button" class="mobile-menu-icon" aria-label="<?php esc_attr_e( 'Menu', 'futebol365' ); ?>">
                                                <svg class="menu-mobile-svg icon-menu" aria-hidden="true" focusable="false"><use href="#icon-menu-bars"></use></svg>
                                                <svg class="menu-mobile-svg icon-close" aria-hidden="true" focusable="false"><use href="#icon-xmark"></use></svg>
                                            </button>
                                        </div>
                                    </div>

                                    <div class="sticky-logo">
                                        <?php echo futebol365_get_site_logo(); ?>
                                    </div>

                                    <?php
                                    if ( has_nav_menu( 'top_menu' ) && class_exists( 'Top_Nav_Walker' ) ) {

                                        wp_nav_menu( array(
                                            'theme_location' => 'top_menu',
                                            'container'      => false,
                                            'items_wrap'     => '<ul class="top-menu-ul">%3$s</ul>',
                                            'walker'         => new Top_Nav_Walker(),
                                        ) );

                                    }
                                    ?>
                                </div>
                            </div>
                        </div>

                        <div class="end-head-bar">
                            <div class="ink-grid">
                                <div class="highligh-links">
                                    <span class="highligh-title"><?php esc_html_e( 'DESTAQUES »', 'futebol365' ); ?></span>
                                    <?php
                                    if ( has_nav_menu( 'top_second_menu' ) ) {

                                        wp_nav_menu( array(
                                            'theme_location' => 'top_second_menu',
                                            'container'      => false,
                                            'menu_class'     => 'top-second-menu',
                                        ) );

                                    }
                                    ?>
                                </div>
                            </div>
                        </div>

                    </div>
                    <?php
                    $f365_header_shell = ob_get_clean();
                    wp_cache_set( 'f365_header_shell', $f365_header_shell, 'theme' );
                }

                /*
                * Render the live (per-user, time-sensitive) timezone clock and inject
                * it into the cached shell in place of the placeholder.
                */
                ob_start();
                f365_render_timezone_clock();
                $f365_clock = ob_get_clean();

                echo str_replace( '<!--F365_TZ_CLOCK-->', $f365_clock, $f365_header_shell );
                ?>

            </header>

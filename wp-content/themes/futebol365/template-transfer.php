<?php
/* Template Name: Transfer Template
**/
$bg_field      = get_field( 'bg_image', 'option' );
$bg_image_url  = $bg_field['url'] ?? futebol365_fallback_image();
get_header(); 
?>
<div class="ink-grid vertical-space">
    <?php get_template_part( 'template-parts/template-offer-widget' );?>

    <div class="ink-header-f365 competicoes" style="background-image: linear-gradient(to left, rgba(250, 250, 250, 0) 40%, rgba(0, 0, 0, 1)), url(<?php echo esc_url($bg_image_url); ?>);">
        <div class="column-group" >
            <div class="align-left all-60 medium-100 small-100 tiny-100 banner-left-content" >
                <div class="headerdata" >
                    <a href="#"><h1><?php echo the_title(); ?></h1></a>
                </div>
            </div>
        </div>
    </div>
    <div class="nav-trigger" id="nav-trigger">
        <span>Menu</span>
    </div>
    <?php
$current_id = get_the_ID();
?>

<nav class="nav-main" id="nav-main">
    <ul>
        <?php
        // Parent page (transferencias)
        $parent = get_page_by_path('transferencias');

        if ($parent) {

            // Active class for parent
            $parent_active = ($current_id == $parent->ID) ? 'active' : '';

            // Últimas (parent)
            echo '<li>';
            echo '<a class="' . $parent_active . '" href="' . get_permalink($parent->ID) . '">'.esc_html__('Últimas', 'f365').'</a>';
            echo '</li>';

            // Child pages
            $children = get_pages([
                'child_of' => $parent->ID,
                'sort_column' => 'menu_order'
            ]);

            foreach ($children as $child) {

                $child_active = ($current_id == $child->ID) ? 'active' : '';

                echo '<li>';
                echo '<a class="' . $child_active . '" href="' . get_permalink($child->ID) . '">'.esc_html__('Recordes', 'f365').'</a>';
                echo '</li>';
            }
        }
        ?>
    </ul>
</nav>
</div>

<div class="ink-grid futebol365-container">

       <section class="left-column-block">
            <div class="breadcrumbs-f365">
                <div class="ink-navigation breadcrumbs">
                    <?php yoast_breadcrumb(); ?>
                </div>
            </div>

            <?php
            global $wpdb;

            // 1. Gather parameters
            $sel_country = isset( $_GET['country'] ) ? (int) $_GET['country'] : 0;            
            $sel_team    = isset( $_GET['team'] ) ? (int) $_GET['team'] : 0;            
            $sel_type    = isset( $_GET['transfer_type'] ) ? sanitize_text_field( wp_unslash( $_GET['transfer_type'] ) ) : '';
            if ( ! in_array( $sel_type, [ '', 'D', 'E' ], true ) ) {
                $sel_type = '';
            }
            $sel_option  = isset( $_GET['transfer_option'] ) ? sanitize_text_field( wp_unslash( $_GET['transfer_option'] ) ) : 'trans_op_all';
            if ( ! in_array( $sel_option, [ 'trans_op_all', 'trans_op_in', 'trans_op_out' ], true ) ) {
                $sel_option = 'trans_op_all';
            }

            $per_page    = 30;
            $max_results = 600;                                   
            $max_pages   = (int) ceil( $max_results / $per_page );
            $paged       = max( 1, (int) ( $_GET['pagina'] ?? 1 ) );
            $paged       = min( $paged, $max_pages );
            $offset      = ( $paged - 1 ) * $per_page;

            if ( ! function_exists( 'get_age' ) ) {
                function get_age( $birth_date ) {
                    $birth_date = trim( (string) $birth_date );
                    if ( '' === $birth_date ) {
                        return '';
                    }
                    try {
                        return ( new DateTime( $birth_date ) )->diff( new DateTime( 'now' ) )->y;
                    } catch ( Exception $e ) {
                        return '';
                    }
                }
            }

            // 2. Fetch Countries list directly from custom tables (fast)
            $countries_list = $wpdb->get_results(
                "SELECT id, shortname FROM {$wpdb->prefix}country_country ORDER BY shortname ASC"
            );

            // Fetch country flags from f365_country taxonomy terms mapped by django_country_id
            $flags_map = array();
            $terms = get_terms( array(
                'taxonomy'   => 'f365_country',
                'hide_empty' => false,
            ) );

            if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
                foreach ( $terms as $term ) {
                    $django_id = get_term_meta( $term->term_id, 'django_country_id', true );
                    if ( $django_id ) {
                        $flag_meta = get_term_meta( $term->term_id, 'country_flag', true );
                        if ( $flag_meta ) {
                            $flag_url = is_numeric( $flag_meta ) ? wp_get_attachment_image_url( $flag_meta, 'full' ) : $flag_meta;
                            if ( $flag_url ) {
                                $flags_map[$django_id] = array(
                                    'url'  => $flag_url,
                                    'name' => $term->name,
                                );
                            }
                        }
                    }
                }
            }

            // 3. Fetch Teams list based on selected country
            $teams_in_country = [];
            if ( $sel_country ) {
                $teams_in_country = $wpdb->get_results( $wpdb->prepare(
                    "SELECT id AS ID, shortname AS post_title 
                     FROM {$wpdb->prefix}team_team 
                     WHERE country_id = %d AND is_available = 1 AND team_type = 'club' 
                     ORDER BY shortname ASC",
                    $sel_country
                ) );
            }

            // 4. Build dynamic SQL clauses matching Django view logic
            $f_where  = '';
            $f_params = [];

            // Country Filter
            if ( $sel_country ) {
                if ( 'trans_op_in' === $sel_option ) {
                    $f_where   .= " AND t_dest.country_id = %d ";
                    $f_params[] = $sel_country;
                } elseif ( 'trans_op_out' === $sel_option ) {
                    $f_where   .= " AND COALESCE(t_orig.country_id, t_orig_tmp.country_id) = %d ";
                    $f_params[] = $sel_country;
                } else {
                    $f_where   .= " AND ( t_orig.country_id = %d OR t_orig_tmp.country_id = %d OR t_dest.country_id = %d ) ";
                    $f_params[] = $sel_country;
                    $f_params[] = $sel_country;
                    $f_params[] = $sel_country;
                }
            }

            // Team Filter
            if ( $sel_team ) {
                if ( 'trans_op_in' === $sel_option ) {
                    $f_where   .= " AND t_dest.id = %d ";
                    $f_params[] = $sel_team;
                } elseif ( 'trans_op_out' === $sel_option ) {
                    $f_where   .= " AND COALESCE(t_orig.id, t_orig_tmp.id) = %d ";
                    $f_params[] = $sel_team;
                    $f_params[] = $sel_team;
                } else {
                    $f_where   .= " AND ( t_orig.id = %d OR t_orig_tmp.id = %d OR t_dest.id = %d ) ";
                    $f_params[] = $sel_team;
                    $f_params[] = $sel_team;
                    $f_params[] = $sel_team;
                }
            }

            // Transfer Type Filter
            if ( '' !== $sel_type ) {
                $f_where   .= " AND t.transfer_type = %s ";
                $f_params[] = $sel_type;
            }

            // 5. Query results count (Capped at 600 per Django view)
            $query_count = "
                SELECT COUNT(t.id)
                FROM {$wpdb->prefix}transfer_transfer t
                JOIN {$wpdb->prefix}person_player p ON t.player_id = p.id
                LEFT JOIN {$wpdb->prefix}team_teamstep ts_orig ON t.team_origin_id = ts_orig.id
                LEFT JOIN {$wpdb->prefix}team_team t_orig ON ts_orig.team_id = t_orig.id
                LEFT JOIN {$wpdb->prefix}team_team t_orig_tmp ON t.tmp_origin_team_id = t_orig_tmp.id
                JOIN {$wpdb->prefix}team_teamstep ts_dest ON t.team_destination_id = ts_dest.id
                JOIN {$wpdb->prefix}team_team t_dest ON ts_dest.team_id = t_dest.id
                WHERE 1=1 {$f_where}
            ";
            
            if ( ! empty( $f_params ) ) {
                $count_sql = $wpdb->prepare( $query_count, $f_params );
            } else {
                $count_sql = $query_count;
            }
            $found       = (int) $wpdb->get_var( $count_sql );
            $found       = min( $max_results, $found ); // Cap total at 600
            $total_pages = (int) ceil( $found / $per_page );

            // 6. Query results for current page
            $query_results = "
                SELECT 
                    t.id AS transfer_id,
                    t.transfer_date,
                    t.transfer_type,
                    t.value,
                    
                    -- Player Info
                    p.id AS player_id,
                    pr.shortname AS player_name,
                    p.position AS player_position,
                    pr.birth_date AS player_birth_date,
                    pr.nationality_id AS player_nationality_id,
                    
                    -- Origin Team Info
                    t_orig.id AS team_from_id,
                    COALESCE(t_orig.shortname, t_orig_tmp.shortname) AS team_from_name,
                    ts_orig.edition AS origin_edition,
                    ts_orig.step AS origin_step,
                    ts_orig.modality AS origin_modality,
                    ts_orig.gender AS origin_gender,
                    
                    -- Destination Team Info
                    t_dest.id AS team_to_id,
                    t_dest.shortname AS team_to_name,
                    ts_dest.edition AS dest_edition,
                    ts_dest.step AS dest_step,
                    ts_dest.modality AS dest_modality,
                    ts_dest.gender AS dest_gender,
                    
                    -- Country Names
                    COALESCE(
                        (SELECT c.shortname FROM {$wpdb->prefix}country_country c WHERE c.id = t_orig.country_id),
                        (SELECT c.shortname FROM {$wpdb->prefix}country_country c WHERE c.id = t_orig_tmp.country_id)
                    ) AS team_from_country,
                    (SELECT c.shortname FROM {$wpdb->prefix}country_country c WHERE c.id = t_dest.country_id) AS team_to_country
                FROM 
                    {$wpdb->prefix}transfer_transfer t
                JOIN 
                    {$wpdb->prefix}person_player p ON t.player_id = p.id
                JOIN 
                    {$wpdb->prefix}person_person pr ON p.person_id = pr.id
                LEFT JOIN 
                    {$wpdb->prefix}team_teamstep ts_orig ON t.team_origin_id = ts_orig.id
                LEFT JOIN 
                    {$wpdb->prefix}team_team t_orig ON ts_orig.team_id = t_orig.id
                LEFT JOIN 
                    {$wpdb->prefix}team_team t_orig_tmp ON t.tmp_origin_team_id = t_orig_tmp.id
                JOIN 
                    {$wpdb->prefix}team_teamstep ts_dest ON t.team_destination_id = ts_dest.id
                JOIN 
                    {$wpdb->prefix}team_team t_dest ON ts_dest.team_id = t_dest.id
                WHERE 
                    1=1 {$f_where}
                ORDER BY 
                    t.transfer_date DESC, t.id DESC
                LIMIT %d OFFSET %d
            ";

            $query_params = array_merge( $f_params, [ $per_page, $offset ] );
            $results = $wpdb->get_results( $wpdb->prepare( $query_results, $query_params ) );

            $position_labels = [
                'goalkeeper' => 'Guarda-redes',
                'defender'   => 'Defesa',
                'midfielder' => 'Médio',
                'forward'    => 'Avançado',
            ];
            
            $transfer_type_labels = [
                'D' => 'D',
                'E' => 'E',
            ];

            // Render columns layout class based on option visibility
            $layout_class = $sel_country ? 'all-25' : 'all-33';
            ?>

            <!-- Filters form -->
            <form class="ink-form ink-form-option" method="get" name="transfers_form" id="transfers-form">
                <div class="column-group">

                    <div class="control-group <?php echo esc_attr($layout_class); ?> tiny-100 small-100">
                        <div class="control">
                            <select name="country" id="id_country">
                                <option value="">Países</option>
                                <?php
                                foreach ( $countries_list as $c ) {
                                    printf(
                                        '<option value="%d"%s>%s</option>',
                                        $c->id,
                                        selected( $sel_country, $c->id, false ),
                                        esc_html( $c->shortname )
                                    );
                                }
                                ?>
                            </select>
                        </div>
                    </div>

                    <div class="control-group <?php echo esc_attr($layout_class); ?> tiny-100 small-100">
                        <div class="control">
                            <select name="team" id="id_team" <?php disabled( empty( $teams_in_country ) ); ?>>
                                <option value="">Equipas</option>
                                <?php foreach ( $teams_in_country as $team_row ) : ?>
                                    <option value="<?php echo (int) $team_row->ID; ?>" <?php selected( $sel_team, (int) $team_row->ID ); ?>>
                                        <?php echo esc_html( $team_row->post_title ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <?php if ( $sel_country ) : ?>
                        <div class="control-group <?php echo esc_attr($layout_class); ?> tiny-100 small-100">
                            <div class="control">
                                <select name="transfer_option" id="id_transfer_option">
                                    <option value="trans_op_all" <?php selected( $sel_option, 'trans_op_all' ); ?>>Opções</option>
                                    <option value="trans_op_in" <?php selected( $sel_option, 'trans_op_in' ); ?>>Entradas</option>
                                    <option value="trans_op_out" <?php selected( $sel_option, 'trans_op_out' ); ?>>Saídas</option>
                                </select>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="control-group <?php echo esc_attr($layout_class); ?> tiny-100 small-100">
                        <div class="control">
                            <select name="transfer_type" id="id_transfer_type">
                                <option value="">Tipo</option>
                                <option value="D" <?php selected( $sel_type, 'D' ); ?>>Definitivo</option>
                                <option value="E" <?php selected( $sel_type, 'E' ); ?>>Empréstimo</option>
                            </select>
                        </div>
                    </div>

                </div>
            </form>

            <div class="overflow-auto">
                <table class="ink-table alternating ink-table-f365">
                    <thead>
                        <tr>
                            <th class="align-left hide-small hide-tiny">Data</th>
                            <th class="align-left">Jogador</th>
                            <th class="align-left hide-small hide-tiny">Origem</th>
                            <th class="align-left">Destino</th>
                            <th class="align-left hide-small hide-tiny">Tipo</th>
                            <th class="align-left">Valor</th>
                        </tr>
                    </thead>

                    <tbody>
                    <?php if ( ! empty( $results ) ) : ?>
                        <?php foreach ( $results as $r ) :

                            // echo '<pre>';
                            // print_r($r);
                            // echo '</pre>';
                            // exit;
                            $formatted_date = $r->transfer_date ? date( 'd-m-y', strtotime( $r->transfer_date ) ) : '';
                            $position_text  = $position_labels[ strtolower( $r->player_position ) ] ?? $r->player_position;
                            $type_text      = $transfer_type_labels[ strtoupper( $r->transfer_type ) ] ?? $r->transfer_type;

                            $player_url = $r->player_id ? home_url( '/jogador/' . $r->player_id . '/' ) : '';

                            $team_from_url = $r->team_from_id ? add_query_arg( [
                                'edition'  => $r->origin_edition,
                                'modality' => $r->origin_modality,
                                'gender'   => $r->origin_gender,
                                'step'     => $r->origin_step,
                            ], home_url( '/equipa/' . $r->team_from_id . '/jogos/' ) ) : '';

                            $team_to_url = $r->team_to_id ? add_query_arg( [
                                'edition'  => $r->dest_edition,
                                'modality' => $r->dest_modality,
                                'gender'   => $r->dest_gender,
                                'step'     => $r->dest_step,
                            ], home_url( '/equipa/' . $r->team_to_id . '/jogos/' ) ) : '';

                            $flag_info = isset( $flags_map[$r->player_nationality_id] ) ? $flags_map[$r->player_nationality_id] : null;
                            $flag_url  = $flag_info ? $flag_info['url'] : '';
                            $flag_name = $flag_info ? $flag_info['name'] : '';
                        ?>
                            <tr>
                                <td class="hide-small hide-tiny cell-nowrap">
                                    <?php echo esc_html( $formatted_date ); ?>
                                </td>

                                <td>
                                    <!-- Display country flag instead of raw ID text -->
                                    <?php if ( ! empty( $flag_url ) ) : ?>
                                        <img src="<?php echo esc_url( $flag_url ); ?>" class="country-flag-icon" style="width:20px; height:auto; vertical-align:middle; margin-right:5px; border-radius:2px;" alt="<?php echo esc_attr( $flag_name ); ?>" />
                                    <?php endif; ?>
                                    <a href="<?php echo esc_url( $player_url ); ?>">
                                        <?php echo esc_html( $r->player_name ); ?>
                                    </a>
                                    <div class="medium hide-small hide-tiny">
                                        <?php echo esc_html( $position_text ); ?><?php echo $r->player_birth_date ? ', ' . get_age( $r->player_birth_date ) : ''; ?>                                    </div>
                                    <div class="medium hide-xlarge hide-large hide-medium"><?php echo esc_html( $formatted_date ); ?></div>
                                </td>

                                <td class="hide-small hide-tiny">
                                    <?php if ( $r->team_from_name ) : ?>
                                        <a href="<?php echo esc_url( $team_from_url ); ?>">
                                            <?php echo esc_html( $r->team_from_name ); ?>
                                        </a>
                                    <?php endif; ?>
                                    <div class="medium">
                                        <?php echo esc_html( $r->team_from_country ); ?>
                                    </div>
                                </td>

                                <td>
                                    <?php if ( $r->team_to_name ) : ?>
                                        <a href="<?php echo esc_url( $team_to_url ); ?>">
                                            <?php echo esc_html( $r->team_to_name ); ?>
                                        </a>
                                    <?php endif; ?>
                                    <div class="medium hide-small hide-tiny">
                                        <?php echo esc_html( $r->team_to_country ); ?>
                                    </div>
                                    <div class="medium hide-xlarge hide-large hide-medium">
                                        <?php echo esc_html( $position_text ); ?><?php echo $r->player_birth_date ? ', ' . get_age( $r->player_birth_date ) : ''; ?>
                                    </div>
                                </td>

                                <td class="hide-small hide-tiny">
                                    <?php echo esc_html( $type_text ); ?>
                                </td>

                                <td>
                                    <?php if ( ! empty( $r->value ) ) : ?>
                                        <?php if ( is_numeric( $r->value ) ) : ?>
                                            <?php echo esc_html( number_format( $r->value, 0, '', ' ' ) ); ?>&nbsp;&euro;
                                        <?php else : ?>
                                            <?php echo esc_html( $r->value ); ?>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr><td colspan="6">Sem resultados para os filtros seleccionados.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="all-100">
                <div class="paginacao-new">
                    <nav class="ink-navigation">
                        <ul class="pagination">
                            <?php
                            if ( $total_pages > 1 ) {

                                if ( $paged > 1 ) {
                                    echo '<li class="hide-tiny hide-small">
                                            <a href="' . esc_url( add_query_arg( 'pagina', $paged - 1 ) ) . '">« Anterior</a>
                                        </li>';
                                }

                                $links = paginate_links( array(
                                    'base'      => add_query_arg( 'pagina', '%#%' ),
                                    'format'    => '',
                                    'current'   => $paged,
                                    'total'     => $total_pages,
                                    'prev_next' => false,
                                    'type'      => 'array',
                                    'end_size'  => 1,
                                    'mid_size'  => 2,
                                ) );

                                if ( ! empty( $links ) ) {
                                    foreach ( $links as $link ) {
                                        if ( strpos( $link, 'current' ) !== false ) {
                                            echo '<li>' . str_replace( 'page-numbers current', 'active', $link ) . '</li>';
                                        } else {
                                            echo '<li>' . $link . '</li>';
                                        }
                                    }
                                }

                                if ( $paged < $total_pages ) {
                                    echo '<li class="hide-tiny hide-small">
                                            <a href="' . esc_url( add_query_arg( 'pagina', $paged + 1 ) ) . '">Seguinte »</a>
                                        </li>';
                                }
                            }
                            ?>
                        </ul>
                    </nav>
                </div>
            </div>

            <script type="text/javascript">
            (function () {
                var form    = document.getElementById('transfers-form');
                var country = document.getElementById('id_country');
                var team    = document.getElementById('id_team');
                var type    = document.getElementById('id_transfer_type');
                var option  = document.getElementById('id_transfer_option');
                if (!form) { return; }
                function submitForm() { form.submit(); }
                if (country) {
                    country.addEventListener('change', function () {
                        if (team) { team.value = ''; }
                        if (option) { option.value = 'trans_op_all'; }
                        submitForm();
                    });
                }
                if (team) { team.addEventListener('change', submitForm); }
                if (type) { type.addEventListener('change', submitForm); }
                if (option) { option.addEventListener('change', submitForm); }
            })();
            </script>

            <?php get_template_part( 'template-parts/template-front-page-posts' ); ?>
        </section>

        <section class="right-column-block">
            <?php
            if ( is_active_sidebar( 'post-sidebar' ) ) {
                dynamic_sidebar( 'post-sidebar' );
            } else {
                get_sidebar();
            }
            ?>
        </section>

    </div>

<?php get_footer(); ?>
<?php
/**
 * Add useful columns to admin list screens for each CPT.
 */

defined( 'ABSPATH' ) || exit;

// -----------------------------------------------------------------------
// TEAM — add edition, federation, highlight columns
// -----------------------------------------------------------------------
add_filter( 'manage_f365_team_posts_columns', function ( $cols ) {
    $cols['f365_federation'] = 'Federation';
    $cols['f365_team_type']  = 'Type';
    $cols['f365_highlight']  = 'Highlight';
    return $cols;
} );
add_action( 'manage_f365_team_posts_custom_column', function ( $col, $post_id ) {
    if ( $col === 'f365_team_type' )  echo esc_html( get_post_meta( $post_id, 'team_type', true ) );
    if ( $col === 'f365_highlight' )  echo get_post_meta( $post_id, 'is_highlight', true ) ? '★' : '–';
    if ( $col === 'f365_federation' ) {
        $fed_id = (int) get_post_meta( $post_id, 'federation', true );
        echo $fed_id ? esc_html( get_the_title( $fed_id ) ) : '–';
    }
}, 10, 2 );

// -----------------------------------------------------------------------  
// MATCH — add competition, date, score columns
// -----------------------------------------------------------------------
add_filter( 'manage_f365_match_posts_columns', function ( $cols ) {
    $cols['f365_competition'] = 'Competition';
    $cols['f365_score']       = 'Score';
    $cols['f365_situation']   = 'Status';
    return $cols;
} );
add_action( 'manage_f365_match_posts_custom_column', function ( $col, $post_id ) {
    if ( $col === 'f365_situation' )   echo esc_html( get_post_meta( $post_id, 'situation', true ) );
    if ( $col === 'f365_score' ) {
        $h = get_post_meta( $post_id, 'home_score', true );
        $v = get_post_meta( $post_id, 'visitor_score', true );
        echo ( $h !== '' && $v !== '' ) ? esc_html( "$h - $v" ) : 'vs';
    }
    if ( $col === 'f365_competition' ) {
        $comp_id = (int) get_post_meta( $post_id, 'competition', true );
        echo $comp_id ? esc_html( get_the_title( $comp_id ) ) : '–';
    }
}, 10, 2 );

// -----------------------------------------------------------------------
// PLAYER — position, nationality
// -----------------------------------------------------------------------
add_filter( 'manage_f365_player_posts_columns', function ( $cols ) {
    $cols['f365_position']    = 'Position';
    $cols['f365_nationality'] = 'Nationality';
    return $cols;
} );
add_action( 'manage_f365_player_posts_custom_column', function ( $col, $post_id ) {
    if ( $col === 'f365_position' )    echo esc_html( get_post_meta( $post_id, 'position', true ) );
    if ( $col === 'f365_nationality' ) {
        $term_id = (int) get_post_meta( $post_id, 'nationality', true );
        $term = $term_id ? get_term( $term_id, 'f365_country' ) : null;
        echo $term && ! is_wp_error( $term ) ? esc_html( $term->name ) : '–';
    }
}, 10, 2 );

// -----------------------------------------------------------------------
// COMPETITION — edition, federation competition
// -----------------------------------------------------------------------
add_filter( 'manage_f365_competition_posts_columns', function ( $cols ) {
    $cols['f365_edition']   = 'Edition';
    $cols['f365_season']    = 'Season';
    $cols['f365_available'] = 'Available';
    $cols['f365_closed']    = 'Closed';
    return $cols;
} );
add_action( 'manage_f365_competition_posts_custom_column', function ( $col, $post_id ) {
    if ( $col === 'f365_edition' )   echo esc_html( get_post_meta( $post_id, 'edition', true ) );
    if ( $col === 'f365_season' )    echo esc_html( get_post_meta( $post_id, 'season', true ) );
    if ( $col === 'f365_available' ) echo get_post_meta( $post_id, 'is_available', true ) ? '✓' : '–';
    if ( $col === 'f365_closed' )    echo get_post_meta( $post_id, 'is_closed', true )    ? '✓' : '–';
}, 10, 2 );

// -----------------------------------------------------------------------
// TRANSFER — player, type, date
// -----------------------------------------------------------------------
add_filter( 'manage_f365_transfer_posts_columns', function ( $cols ) {
    $cols['f365_player']   = 'Player';
    return $cols;
} );
add_action( 'manage_f365_transfer_posts_custom_column', function ( $col, $post_id ) {
    if ( $col === 'f365_player' ) {
        $pid = (int) get_post_meta( $post_id, 'player', true );
        echo $pid ? esc_html( get_the_title( $pid ) ) : '–';
    }
}, 10, 2 );

//Poll, Newsstand, Live Streaming, Ranking, Publisher.


//  NEWSSTAND 

add_filter( 'manage_f365_newsstand_posts_columns', 'f365_newsstand_columns' );
function f365_newsstand_columns( array $cols ): array {
    $new = [];
    foreach ( $cols as $k => $v ) {
        $new[ $k ] = $v;
        if ( $k === 'title' ) {
            $new['publisher']     = __( 'Publisher', 'futebol365' );
            $new['published_at']  = __( 'Published At', 'futebol365' );
            $new['is_last_edition'] = __( 'Latest', 'futebol365' );
        }
    }
    return $new;
}

add_action( 'manage_f365_newsstand_posts_custom_column', 'f365_newsstand_column_data', 10, 2 );
function f365_newsstand_column_data( string $col, int $post_id ): void {
    switch ( $col ) {
        case 'publisher':
            $pub_id = (int) get_post_meta( $post_id, 'publisher', true );
            if ( $pub_id ) {
                $link = get_edit_post_link( $pub_id );
                $name = get_the_title( $pub_id );
                echo $link ? "<a href='$link'>$name</a>" : esc_html( $name );
            } else {
                echo '—';
            }
            break;
        case 'published_at':
            echo get_the_date( 'd/m/Y', $post_id );
            break;
        case 'is_last_edition':
            $v = get_post_meta( $post_id, 'is_last_edition', true );
            echo $v ? '<span style="color:green">✓</span>' : '—';
            break;
    }
}

//  RANKING 

add_filter( 'manage_f365_ranking_posts_columns', 'f365_ranking_columns' );
function f365_ranking_columns( array $cols ): array {
    $new = [];
    foreach ( $cols as $k => $v ) {
        $new[ $k ] = $v;
        if ( $k === 'title' ) {
            $new['rk_type']     = __( 'Type', 'futebol365' );
            $new['rk_position'] = __( '#', 'futebol365' );
            $new['rk_points']   = __( 'Pts', 'futebol365' );
            $new['rk_year']     = __( 'Year/Month', 'futebol365' );
            $new['rk_edition']  = __( 'Edition', 'futebol365' );
        }
    }
    return $new;
}

add_action( 'manage_f365_ranking_posts_custom_column', 'f365_ranking_column_data', 10, 2 );
function f365_ranking_column_data( string $col, int $post_id ): void {
    switch ( $col ) {
        case 'rk_type':
            $type = get_post_meta( $post_id, 'ranking_type', true );
            $labels = [ 'club' => 'Club', 'national' => 'National', 'player' => 'Player' ];
            echo esc_html( $labels[ $type ] ?? $type );
            break;
        case 'rk_position':
            echo (int) get_post_meta( $post_id, 'position', true );
            break;
        case 'rk_points':
            echo (int) get_post_meta( $post_id, 'points', true );
            break;
        case 'rk_year':
            $y = get_post_meta( $post_id, 'year', true );
            $m = get_post_meta( $post_id, 'month', true );
            echo $y ? esc_html( $m ? "$y-$m" : $y ) : '—';
            break;
        case 'rk_edition':
            echo esc_html( get_post_meta( $post_id, 'edition', true ) ?: '—' );
            break;
    }
}

// Make ranking position sortable
add_filter( 'manage_edit-f365_ranking_sortable_columns', function( $cols ) {
    $cols['rk_position'] = 'position';
    return $cols;
} );

//  LIVE GAMES 

// add_filter( 'manage_f365_livegames_posts_columns', 'f365_livegames_columns' );
// function f365_livegames_columns( array $cols ): array {
//     $new = [];
//     foreach ( $cols as $k => $v ) {
//         $new[ $k ] = $v;
//         if ( $k === 'title' ) {
//             $new['lg_score']    = __( 'Score', 'futebol365' );
//             $new['lg_status']   = __( 'Status', 'futebol365' );
//             $new['lg_datetime'] = __( 'Kick-off', 'futebol365' );
//             $new['lg_mapped']   = __( 'Mapped', 'futebol365' );
//         }
//     }
//     return $new;
// }

// add_action( 'manage_f365_livegames_posts_custom_column', 'f365_livegames_column_data', 10, 2 );
// function f365_livegames_column_data( string $col, int $post_id ): void {
//     switch ( $col ) {
//         case 'lg_score':
//             $h = get_post_meta( $post_id, 'home_score', true );
//             $v = get_post_meta( $post_id, 'visitor_score', true );
//             echo ( $h !== '' && $v !== '' ) ? esc_html( "$h - $v" ) : '—';
//             break;
//         case 'lg_status':
//             $s = (string) get_post_meta( $post_id, 'live_status', true );
//             $labels = [ 'RF' => 'Finished', 'AD' => 'Postponed', 'SP' => 'Suspended', '-1' => 'Not started' ];
//             echo esc_html( $labels[ $s ] ?? ( $s !== '' ? $s . "'" : '—' ) );
//             break;
//         case 'lg_datetime':
//             $dt = get_post_meta( $post_id, 'datetime', true );
//             echo $dt ? esc_html( date_i18n( 'd/m/Y H:i', strtotime( $dt ) ) ) : '—';
//             break;
//         case 'lg_mapped':
//             echo get_post_meta( $post_id, 'is_mapped', true ) ? '<span style="color:green">✓</span>' : '—';
//             break;
//     }
// }

//  LIVE STREAMING 

add_filter( 'manage_f365_livestream_posts_columns', 'f365_livestream_columns' );
function f365_livestream_columns( array $cols ): array {
    $new = [];
    foreach ( $cols as $k => $v ) {
        $new[ $k ] = $v;
        if ( $k === 'title' ) {
            //$new['ls_match']        = __( 'Match', 'futebol365' );
            $new['ls_datetime']     = __( 'Scheduled', 'futebol365' );
            //$new['ls_status']       = __( 'Status', 'futebol365' );
        }
    }
    return $new;
}

add_action( 'manage_f365_livestream_posts_custom_column', 'f365_livestream_column_data', 10, 2 );
function f365_livestream_column_data( string $col, int $post_id ): void {
    switch ( $col ) {
        // case 'ls_match':
        //     $match_id = (int) get_post_meta( $post_id, 'match', true );
        //     if ( $match_id ) {
        //         $link = get_edit_post_link( $match_id );
        //         $name = get_the_title( $match_id );
        //         echo $link ? "<a href='$link'>$name</a>" : esc_html( $name );
        //     } else {
        //         $home = get_post_meta( $post_id, 'home_team_name', true );
        //         $away = get_post_meta( $post_id, 'visitor_team_name', true );
        //         echo esc_html( "$home vs $away" );
        //     }
        //     break;
        case 'ls_datetime':
            $dt = get_post_meta( $post_id, 'datetime', true );
            echo $dt ? esc_html( date( 'd/m/Y H:i', strtotime( $dt ) ) ) : '—';
            break;
        // case 'ls_status':
        //     // Derived status, mirroring LiveStreaming.get_livestreaming_by_type():
        //     //   scheduled + ts>=now            → tostart  (Brevemente)
        //     //   scheduled + now-105m<=ts<=now  → inlive   (Em direto)
        //     //   finished                       → finished (Terminado)
        //     $situation = get_post_meta( $post_id, 'match_situation', true );
        //     $ts        = (int) get_post_meta( $post_id, 'match_timestamp', true );
        //     $now       = current_time( 'timestamp' );
        //     $window    = defined( 'F365_LIVESTREAM_WINDOW' ) ? F365_LIVESTREAM_WINDOW : 6300;

        //     if ( $situation === 'finished' ) {
        //         echo '<span style="color:#888">Terminado</span>';
        //     } elseif ( $situation === 'scheduled' && $ts >= $now ) {
        //         echo '<span style="color:blue">Brevemente</span>';
        //     } elseif ( $situation === 'scheduled' && $ts <= $now && $ts >= ( $now - $window ) ) {
        //         echo '<span style="color:green">Em direto</span>';
        //     } elseif ( $situation && $situation !== 'scheduled' ) {
        //         echo esc_html( ucfirst( $situation ) );
        //     } else {
        //         echo '—';
        //     }
        //     break;
    }
}
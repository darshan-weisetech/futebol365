<?php
/**
 * /goto/ — geo-targeted betting redirect.
 *
 * Faithful WordPress port of the Django view `views.get_user_ip`
 * (URL: url(r'^goto/$', views.get_user_ip, name='goto')).
 *
 * Resolves the visitor's country from their IP via ip-api.com and 302-redirects
 * to the matching partner landing page, replicating the source mapping exactly:
 *
 *   PT                      -> Betano   (Fut365Betano)
 *   AO                      -> 1xBet    (Fut3651xBet)
 *   MZ                      -> 1xBet    (Fut3651xBet)
 *   BR                      -> bet365br
 *   not in bet365 list      -> Bet365   (Fut365Bet365)
 *   otherwise (in the list) -> 1xBet    (Fut3651xBet)
 *
 * The "A" buttons in the calendar / fixtures templates and the match-page
 * "APOSTAR" button all link to home_url('/goto/'); this handler performs the
 * per-visitor redirect.
 *
 * Country lookups are cached per IP (transient) to limit calls to the free
 * ip-api.com endpoint (HTTP only, ~45 req/min). On any lookup failure we fall
 * back to the default Bet365 partner so the click is never a dead end.
 *
 * Load: require this file from the main plugin and flush rewrite rules once on
 * activation (F365_Goto::install() registers the rule; flushing makes /goto/
 * resolve without index.php).
 *
 * @package Futebol365
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'f365_goto_redirect_url' ) ) {
	/**
	 * Map an ISO country code to its partner landing URL.
	 * Mirrors the if/elif chain in get_user_ip() one-for-one.
	 */
	function f365_goto_redirect_url( string $country_code ): string {
		$country_code = strtoupper( $country_code );

		$bet365_country_code = array(
			'PT', 'BE', 'GW', 'VE', 'ZA', 'AF', 'BI',
			'KH', 'TD', 'CU', 'CZ', 'GQ', 'IL', 'CO', 'PL',
			'TR', 'UZ', 'NL', 'PR', 'MO', 'HK', 'SO', 'SY',
		);

		if ( 'PT' === $country_code ) {
			return 'https://go.futebol365.pt/Fut365Betano';
		} elseif ( 'AO' === $country_code ) {
			return 'https://go.futebol365.pt/Fut3651xBet';
		} elseif ( 'MZ' === $country_code ) {
			return 'https://go.futebol365.pt/Fut3651xBet';
		} elseif ( 'BR' === $country_code ) {
			return 'https://go.futebol365.pt/bet365br';
		} elseif ( ! in_array( $country_code, $bet365_country_code, true ) ) {
			return 'https://go.futebol365.pt/Fut365Bet365';
		} else {
			return 'https://go.futebol365.pt/Fut3651xBet';
		}
	}
}

if ( ! function_exists( 'f365_goto_client_ip' ) ) {
	/**
	 * Resolve the visitor's public IP, mirroring django-ipware's precedence
	 * (proxy headers first, then REMOTE_ADDR). Returns the first valid IP found,
	 * or '' when none can be determined.
	 */
	function f365_goto_client_ip(): string {
		$keys = array(
			'HTTP_CF_CONNECTING_IP',
			'HTTP_X_FORWARDED_FOR',
			'HTTP_X_REAL_IP',
			'REMOTE_ADDR',
		);

		foreach ( $keys as $key ) {
			if ( empty( $_SERVER[ $key ] ) ) {
				continue;
			}
			$raw = (string) wp_unslash( $_SERVER[ $key ] );
			foreach ( explode( ',', $raw ) as $candidate ) {
				$candidate = trim( $candidate );
				if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
					return $candidate;
				}
			}
		}
		return '';
	}
}

if ( ! function_exists( 'f365_goto_country_code' ) ) {
	/**
	 * Resolve an ISO country code for an IP via ip-api.com, cached per IP.
	 * Returns '' on any failure (caller falls back to the default partner).
	 * Mirrors: response = requests.get('http://ip-api.com/json/' + ip);
	 *          country_code = response.json()['countryCode'].
	 */
	function f365_goto_country_code( string $ip ): string {
		if ( '' === $ip ) {
			return '';
		}

		$cache_key = 'f365_goto_cc_' . md5( $ip );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return (string) $cached;
		}

		$response = wp_remote_get(
			'http://ip-api.com/json/' . rawurlencode( $ip ) . '?fields=status,countryCode',
			array(
				'timeout'     => 3,
				'redirection' => 1,
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return '';
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		$code = ( is_array( $data ) && isset( $data['status'], $data['countryCode'] ) && 'success' === $data['status'] )
			? strtoupper( (string) $data['countryCode'] )
			: '';

		if ( '' !== $code ) {
			set_transient( $cache_key, $code, 6 * HOUR_IN_SECONDS );
		}
		return $code;
	}
}

if ( ! class_exists( 'F365_Goto' ) ) {

	final class F365_Goto {

		/** Register the rewrite rule + query var, and the redirect handler. */
		public static function boot(): void {
			add_action( 'init', array( __CLASS__, 'add_rewrite_rule' ) );
			add_filter( 'query_vars', array( __CLASS__, 'add_query_var' ) );
			add_action( 'template_redirect', array( __CLASS__, 'maybe_redirect' ) );
		}

		/** Routes /goto/ to the handler via a query var (loop-independent). */
		public static function add_rewrite_rule(): void {
			add_rewrite_rule( '^goto/?$', 'index.php?f365_goto=1', 'top' );
		}

		public static function add_query_var( array $vars ): array {
			$vars[] = 'f365_goto';
			return $vars;
		}

		/** Called on plugin activation to register the rule and flush once. */
		public static function install(): void {
			self::add_rewrite_rule();
			flush_rewrite_rules();
		}

		/** Perform the geo redirect when /goto/ is requested. */
		public static function maybe_redirect(): void {
			if ( 1 !== (int) get_query_var( 'f365_goto' ) ) {
				return;
			}

			$ip      = f365_goto_client_ip();
			$country = f365_goto_country_code( $ip );
			$target  = f365_goto_redirect_url( $country );

			nocache_headers();
			wp_redirect( $target, 302 ); // phpcs:ignore WordPress.Security.SafeRedirect -- external partner URLs by design.
			exit;
		}
	}

	F365_Goto::boot();
}

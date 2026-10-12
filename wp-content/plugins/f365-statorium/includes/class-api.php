<?php
/**
 * Statorium Stats API — HTTP client.
 *
 * Thin wrapper over wp_remote_get with API-key auth, JSON decoding,
 * short-lived transient caching and basic retry.
 *
 * @package Futebol365
 */

defined( 'ABSPATH' ) || exit;

class F365_Statorium_API {

	const BASE = 'https://api.statorium.com/api/v1';

	private string $api_key;

	/** Cache TTL per endpoint prefix (seconds). Live data is never cached. */
	private array $ttl = [
		'/matches/live/' => 0,
		'/todaymatches/' => 60,
		'/leagues'       => 6 * HOUR_IN_SECONDS,
		'/seasons'       => HOUR_IN_SECONDS,
		'/teams'         => HOUR_IN_SECONDS,
		'/players'       => HOUR_IN_SECONDS,
		'/matches'       => 5 * MINUTE_IN_SECONDS,
		'/standings'     => 15 * MINUTE_IN_SECONDS,
	];

	public function __construct( string $api_key ) {
		$this->api_key = $api_key;
	}

	/**
	 * GET an endpoint (e.g. '/teams/376/', [ 'season_id' => 614 ]).
	 * Returns decoded array or WP_Error.
	 */
	public function get( string $endpoint, array $args = [], bool $skip_cache = false ) {
		if ( '' === $this->api_key ) {
			return new WP_Error( 'f365_stat_no_key', 'Statorium API key is not configured.' );
		}

		$endpoint = '/' . ltrim( $endpoint, '/' );
		$args['apikey'] = $this->api_key;
		$url = self::BASE . $endpoint . '?' . http_build_query( $args );

		$ttl = $this->ttl_for( $endpoint );
		$cache_key = 'f365stat_' . md5( $url );

		if ( ! $skip_cache && $ttl > 0 ) {
			$cached = get_transient( $cache_key );
			if ( false !== $cached ) {
				return $cached;
			}
		}

		$response = null;
		for ( $attempt = 1; $attempt <= 3; $attempt++ ) {
			$response = wp_remote_get( $url, [ 'timeout' => 60 ] );
			if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) < 500 ) {
				break;
			}
			sleep( $attempt ); // 1s, 2s backoff
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new WP_Error( 'f365_stat_http', "Statorium HTTP {$code} for {$endpoint}" );
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'f365_stat_json', "Statorium returned invalid JSON for {$endpoint}" );
		}

		if ( $ttl > 0 ) {
			set_transient( $cache_key, $data, $ttl );
		}

		return $data;
	}

	private function ttl_for( string $endpoint ): int {
		foreach ( $this->ttl as $prefix => $ttl ) {
			if ( 0 === strpos( $endpoint, $prefix ) ) {
				return (int) $ttl;
			}
		}
		return 5 * MINUTE_IN_SECONDS;
	}

	// ── Convenience wrappers ────────────────────────────────────────────────

	public function leagues() {
		return $this->get( '/leagues/' );
	}

	public function season( int $season_id ) {
		return $this->get( "/seasons/{$season_id}/" );
	}

	public function season_matches( int $season_id ) {
		return $this->get( '/matches/', [ 'season_id' => $season_id ] );
	}

	public function match( int $match_id, bool $skip_cache = false, bool $with_odds = false ) {
		// Odds add-on: `include=odds` adds a top-level `betting.bookmakers`
		// node (NOT match.odds) — delivered on this key since 2026-07.
		return $this->get( "/matches/{$match_id}/", $with_odds ? [ 'include' => 'odds' ] : [], $skip_cache );
	}

	public function live_matches() {
		return $this->get( '/matches/live/', [], true );
	}

	public function today_matches( string $date ) {
		return $this->get( '/todaymatches/', [ 'date' => $date ] );
	}

	public function team( int $team_id, int $season_id ) {
		return $this->get( "/teams/{$team_id}/", [ 'season_id' => $season_id ] );
	}

	public function standings( int $season_id ) {
		return $this->get( "/standings/{$season_id}" );
	}

	/** Change log since a GMT unix timestamp — never cached. */
	public function updates( int $since_ts ) {
		return $this->get( "/updates/{$since_ts}/", [], true );
	}

	/** Venue profile: photo, address, city, coordinates, capacity. */
	public function venue( int $venue_id ) {
		return $this->get( "/venues/{$venue_id}/" );
	}
}

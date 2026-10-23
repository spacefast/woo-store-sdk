<?php
/** @package SpacefastCommerce */
declare(strict_types=1);
namespace SpacefastCommerce;

/**
 * Store-level state the companion owns: mode, canonical origin, apply counter and
 * the last applied store hash. Nothing here is declared in store.json (D21).
 */
final class Store {
	public function register(): void {
		foreach ( array( 'home_url', 'site_url', 'content_url', 'plugins_url', 'wp_get_attachment_url' ) as $hook ) {
			add_filter( $hook, array( $this, 'public_url' ) );
		}
	}

	/** Native Woo links must use the store origin even when cron has no request host. */
	public function public_url( string $url ): string {
		$origin = $this->canonical_origin();
		if ( null === $origin || ! preg_match( '#^https?://#i', $url ) ) {
			return $url;
		}
		foreach ( array( get_option( 'home' ), get_option( 'siteurl' ) ) as $base ) {
			$parts = is_string( $base ) ? wp_parse_url( $base ) : false;
			if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
				continue;
			}
			$host = $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
			if ( $url === $host || str_starts_with( $url, $host . '/' ) || str_starts_with( $url, $host . '?' ) ) {
				return $origin . substr( $url, strlen( $host ) );
			}
		}
		return $url;
	}
	public function mode(): string {
		return 'live' === get_option( 'spacefast_commerce_mode' ) ? 'live' : 'test';
	}

	public function canonical_origin(): ?string {
		$origin = get_option( 'spacefast_commerce_canonical_origin', '' );
		return is_string( $origin ) && '' !== $origin ? $origin : null;
	}

	/** Absolute URL for a site-relative path, on the canonical origin when one is known (cron, WP-CLI, emails). */
	public function url( string $path ): string {
		$origin = $this->canonical_origin();
		return null === $origin ? home_url( $path ) : $origin . '/' . ltrim( $path, '/' );
	}

	/** An https origin with no path, query or credentials, lowercased; or a refusal. */
	public static function normalize_origin( string $origin ): string|\WP_Error {
		$parts = wp_parse_url( $origin );
		if ( ! is_array( $parts ) || 'https' !== ( $parts['scheme'] ?? '' ) || empty( $parts['host'] ) ||
			isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) ||
			( isset( $parts['path'] ) && '/' !== $parts['path'] && '' !== $parts['path'] ) ) {
			return Bundle::refuse( 'canonical-origin-invalid', 'The canonical origin must be an https origin with no path, query or credentials.' );
		}
		return 'https://' . strtolower( $parts['host'] ) . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
	}

	public function set_canonical_origin( string $origin ): true|\WP_Error {
		$normalized = self::normalize_origin( $origin );
		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}
		update_option( 'spacefast_commerce_canonical_origin', $normalized, false );
		return true;
	}

	public function counter(): int {
		return (int) get_option( 'spacefast_commerce_apply_counter', 0 );
	}

	/** The counter only ever grows; it is stamped before the first write of an apply. */
	public function next_counter(): int {
		$next = $this->counter() + 1;
		update_option( 'spacefast_commerce_apply_counter', $next, false );
		return $next;
	}

	public function store_hash(): ?string {
		$hash = get_option( 'spacefast_commerce_store_hash', '' );
		return is_string( $hash ) && '' !== $hash ? $hash : null;
	}

	/** Call only inside with_lock: native processes reject late control-plane writes. */
	public function accept_command( ?int $generation, ?int $command_generation ): true|\WP_Error {
		$mode_generation = filter_var( get_option( 'spacefast_commerce_mode_generation', 0 ), FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 0 ) ) );
		$native_generation = filter_var( get_option( 'spacefast_commerce_command_generation', 0 ), FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 0 ) ) );
		if ( false === $mode_generation || false === $native_generation ) {
			return Bundle::refuse( 'mode-unavailable', 'The store mode fence could not be read. Retry when the store database is available.' );
		}
		if ( null === $generation || null === $command_generation ) {
			if ( null !== $generation || null !== $command_generation || $mode_generation > 0 || $native_generation > 0 ) {
				return Bundle::refuse( 'mode-superseded', 'This store requires a current control-plane mode request.' );
			}
		} else {
			if ( $generation < $mode_generation || $command_generation < $native_generation ) {
				return Bundle::refuse( 'mode-superseded', 'A newer mode or canonical-origin request superseded this command.' );
			}
			// Retain the fence even if cleanup fails after this point. A late
			// SSH process must never undo the newer request on its next lock.
			update_option( 'spacefast_commerce_mode_generation', $generation, false );
			update_option( 'spacefast_commerce_command_generation', $command_generation, false );
			if ( $generation !== (int) get_option( 'spacefast_commerce_mode_generation' ) || $command_generation !== (int) get_option( 'spacefast_commerce_command_generation' ) ) {
				return Bundle::refuse( 'mode-unavailable', 'The store mode fence could not be saved. Retry when the store database is available.' );
			}
		}
		return true;
	}

	/** Server-side MySQL named lock: two applies (or an apply and a mode switch) never interleave on one database. */
	public function with_lock( callable $work, int $timeout = 30 ): mixed {
		global $wpdb;
		$lock = 'spacefast_commerce_' . substr( hash( 'sha256', $wpdb->prefix . DB_NAME ), 0, 32 );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock, $timeout ) ) ) {
			return new \WP_Error( 'apply-busy', 'Another store apply or mode change is running. Retry.', array( 'pointer' => null ) );
		}
		try {
			// A waiter may have read these options before another process committed.
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			foreach ( array( 'spacefast_commerce_apply_counter', 'spacefast_commerce_store_hash', 'spacefast_commerce_mode', 'spacefast_commerce_canonical_origin', 'spacefast_commerce_mode_generation', 'spacefast_commerce_command_generation' ) as $option ) {
				wp_cache_delete( $option, 'options' );
			}
			return $work();
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}
}

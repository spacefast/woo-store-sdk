<?php
/** @package SpacefastCommerce */
declare(strict_types=1);
namespace SpacefastCommerce;

/**
 * Every command prints exactly one JSON document on stdout and exits 0 on success, 1 on a
 * refusal or failure: `{ "ok": false, "error": { "code", "detail", "pointer", "key" } }`.
 */
final class Cli {
	public static function register(): void {
		$commands = array(
			'preflight' => array( self::class, 'preflight' ),
			'setup' => array( self::class, 'setup' ),
			'apply' => array( self::class, 'apply' ),
			'disable' => array( self::class, 'disable' ),
			'mode' => array( self::class, 'mode' ),
			'status' => array( self::class, 'status' ),
			'email render' => array( self::class, 'email_render' ),
		);
		foreach ( $commands as $name => $callback ) {
			\WP_CLI::add_command( 'spacefast-commerce ' . $name, $callback );
		}
	}

	private static function generations( array $assoc ): array|\WP_Error {
		$generation = $assoc['generation'] ?? null;
		$command_generation = $assoc['command-generation'] ?? null;
		foreach ( array( $generation, $command_generation ) as $value ) {
			if ( null !== $value && false === filter_var( $value, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 0 ) ) ) ) {
				return Bundle::refuse( 'mode-generation-invalid', 'Command generations must be non-negative integers.' );
			}
		}
		return array( 'generation' => null === $generation ? null : (int) $generation, 'command_generation' => null === $command_generation ? null : (int) $command_generation );
	}

	private static function emit( array|\WP_Error $result ): void {
		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			$result = array( 'ok' => false, 'error' => array(
				'code' => (string) $result->get_error_code(),
				'detail' => $result->get_error_message(),
				'pointer' => is_array( $data ) ? ( $data['pointer'] ?? null ) : null,
				'key' => is_array( $data ) ? ( $data['key'] ?? null ) : null,
			) );
		}
		\WP_CLI::line( (string) wp_json_encode( $result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		if ( true !== $result['ok'] ) {
			\WP_CLI::halt( 1 );
		}
	}

	private static function plugin(): Plugin|\WP_Error {
		return Plugin::instance() ?? Bundle::refuse( 'companion-inactive', 'WooCommerce and Spacefast Commerce must be active for this command.' );
	}

	/** Before WooCommerce is installed: refuse if a page already sits at /checkout/ or /my-account/ (D9). */
	public static function preflight(): void {
		foreach ( array( 'Bundle', 'Storefront' ) as $class ) {
			require_once __DIR__ . '/' . $class . '.php';
		}
		$result = Storefront::preflight();
		self::emit( is_wp_error( $result ) ? $result : array( 'ok' => true ) );
	}

	public static function setup(): void {
		$plugin = self::plugin();
		self::emit( is_wp_error( $plugin ) ? $plugin : $plugin->provisioning->setup() );
	}

	/**
	 * ## OPTIONS
	 *
	 * <bundle>
	 * : Directory holding store.json and files/<sha256>.
	 *
	 * [--origin=<origin>]
	 * : deploy (default) or rollback.
	 *
	 * [--baseline=<keys>]
	 * : Comma-separated product keys of the live version.
	 *
	 * [--[no-]live-catalog]
	 * : The live version has no store; leftover managed products are deactivated.
	 *
	 * [--canonical-origin=<origin>]
	 * : The store's production origin, used for URLs generated without a request.
	 *
	 * [--generation=<generation>]
	 * : The control-plane mode generation.
	 *
	 * [--command-generation=<generation>]
	 * : The control-plane native command generation.
	 */
	public static function apply( array $args, array $assoc ): void {
		$plugin = self::plugin();
		if ( is_wp_error( $plugin ) ) {
			self::emit( $plugin );
			return;
		}
		$origin = $assoc['origin'] ?? 'deploy';
		if ( ! in_array( $origin, array( 'deploy', 'rollback' ), true ) ) {
			self::emit( Bundle::refuse( 'origin-invalid', '--origin is deploy or rollback.' ) );
			return;
		}
		// WP-CLI parses --no-live-catalog as live-catalog=false.
		$no_live = array_key_exists( 'live-catalog', $assoc ) && false === $assoc['live-catalog'];
		$baseline = null;
		if ( array_key_exists( 'baseline', $assoc ) ) {
			if ( $no_live ) {
				self::emit( Bundle::refuse( 'baseline-conflict', 'Pass either --baseline or --no-live-catalog, not both.' ) );
				return;
			}
			$raw = is_string( $assoc['baseline'] ) ? trim( $assoc['baseline'] ) : '';
			if ( '' === $raw ) {
				self::emit( Bundle::refuse( 'baseline-empty', 'An empty --baseline is malformed. Pass the live version\'s keys, or --no-live-catalog when it has no store.' ) );
				return;
			}
			$baseline = array_values( array_unique( array_map( 'trim', explode( ',', $raw ) ) ) );
			foreach ( $baseline as $key ) {
				if ( ! preg_match( '/^[a-z0-9][a-z0-9_-]{0,63}$/D', $key ) ) {
					self::emit( Bundle::refuse( 'baseline-invalid', '"' . $key . '" is not a product key.', null, $key ) );
					return;
				}
			}
		} elseif ( $no_live ) {
			$baseline = array();
		}
		$canonical = $assoc['canonical-origin'] ?? null;
		if ( null !== $canonical ) {
			// Validated here, stored by apply after its refusal checks pass.
			$valid = Store::normalize_origin( (string) $canonical );
			if ( is_wp_error( $valid ) ) {
				self::emit( $valid );
				return;
			}
		}
		$generations = self::generations( $assoc );
		if ( is_wp_error( $generations ) ) {
			self::emit( $generations );
			return;
		}
		self::emit( $plugin->catalog->apply( $args[0] ?? '', array_merge( $generations, array( 'origin' => $origin, 'baseline' => $baseline, 'canonical_origin' => $canonical ) ) ) );
	}

	/**
	 * ## OPTIONS
	 *
	 * <mode>
	 * : test or live.
	 *
	 * [--canonical-origin=<origin>]
	 * : The store's production origin.
	 *
	 * [--generation=<generation>]
	 * : The control-plane mode generation.
	 *
	 * [--command-generation=<generation>]
	 * : The control-plane native command generation.
	 */
	public static function mode( array $args, array $assoc ): void {
		$plugin = self::plugin();
		if ( is_wp_error( $plugin ) ) {
			self::emit( $plugin );
			return;
		}
		$mode = $args[0] ?? '';
		if ( ! in_array( $mode, array( 'test', 'live' ), true ) ) {
			self::emit( Bundle::refuse( 'mode-invalid', 'Mode is test or live.' ) );
			return;
		}
		$generations = self::generations( $assoc );
		if ( is_wp_error( $generations ) ) {
			self::emit( $generations );
			return;
		}
		self::emit( $plugin->mode->set( $mode, $assoc['canonical-origin'] ?? null, $generations['generation'], $generations['command_generation'] ) );
	}

	/**
	 * Disable managed products. Retain native order history.
	 *
	 * ## OPTIONS
	 *
	 * [--generation=<generation>]
	 * : The control-plane mode generation.
	 *
	 * [--command-generation=<generation>]
	 * : The control-plane native command generation.
	 */
	public static function disable( array $args, array $assoc ): void {
		$plugin = self::plugin();
		$generations = self::generations( $assoc );
		self::emit( is_wp_error( $plugin ) ? $plugin : ( is_wp_error( $generations ) ? $generations : $plugin->catalog->disable( $generations['generation'], $generations['command_generation'] ) ) );
	}

	public static function status(): void {
		$plugin = self::plugin();
		self::emit( is_wp_error( $plugin ) ? $plugin : $plugin->provisioning->status() );
	}

	/**
	 * ## OPTIONS
	 *
	 * <order-id>
	 * : Woo order ID.
	 *
	 * <email-id>
	 * : Woo email ID, such as customer_completed_order.
	 */
	public static function email_render( array $args ): void {
		$plugin = self::plugin();
		self::emit( is_wp_error( $plugin ) ? $plugin : $plugin->emails->render_order( (int) ( $args[0] ?? 0 ), (string) ( $args[1] ?? '' ) ) );
	}
}

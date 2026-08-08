<?php
/**
 * Checkout branding settings.
 *
 * @package WooStorefront
 */

declare(strict_types=1);

namespace WooStorefront;

/**
 * Registers a structured settings schema consumed by the checkout shell.
 */
final class Branding {
	public const OPTION = 'woo_storefront_settings';

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_settings' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Register settings and their REST schema.
	 */
	public function register_settings(): void {
		register_setting(
			'woo-storefront',
			self::OPTION,
			array(
				'type'              => 'object',
				'default'           => self::defaults(),
				'sanitize_callback' => array( self::class, 'sanitize' ),
				'show_in_rest'      => array(
					'schema' => array(
						'type'       => 'object',
						'properties' => array(
							'revalidate_url' => array( 'type' => 'string', 'format' => 'uri' ),
							'shared_secret' => array( 'type' => 'string' ),
							'branding'      => array(
								'type'       => 'object',
								'properties' => array(
									'logo'       => array( 'type' => 'string', 'format' => 'uri' ),
									'colors'     => array( 'type' => 'object' ),
									'typography' => array( 'type' => 'object' ),
								),
							),
						),
					),
				),
			)
		);
	}

	/**
	 * Return normalized plugin settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_settings(): array {
		$value = get_option( self::OPTION, array() );
		return self::sanitize( is_array( $value ) ? $value : array() );
	}

	/**
	 * Sanitize settings.
	 *
	 * @param mixed $value Raw settings.
	 * @return array<string, mixed>
	 */
	public static function sanitize( mixed $value ): array {
		$value    = is_array( $value ) ? $value : array();
		$branding = isset( $value['branding'] ) && is_array( $value['branding'] ) ? $value['branding'] : array();
		$colors   = isset( $branding['colors'] ) && is_array( $branding['colors'] ) ? $branding['colors'] : array();
		$type     = isset( $branding['typography'] ) && is_array( $branding['typography'] ) ? $branding['typography'] : array();
		$font     = isset( $type['font_family'] ) ? (string) $type['font_family'] : 'system-ui, sans-serif';
		$font     = preg_replace( '/[^a-zA-Z0-9 ,_\-"\']/', '', $font );

		return array(
			'revalidate_url' => isset( $value['revalidate_url'] ) ? esc_url_raw( (string) $value['revalidate_url'] ) : '',
			'shared_secret'  => isset( $value['shared_secret'] ) ? sanitize_text_field( (string) $value['shared_secret'] ) : '',
			'branding'       => array(
				'logo'       => isset( $branding['logo'] ) ? esc_url_raw( (string) $branding['logo'] ) : '',
				'colors'     => array(
					'primary'    => self::color( $colors['primary'] ?? '#7f54b3', '#7f54b3' ),
					'background' => self::color( $colors['background'] ?? '#ffffff', '#ffffff' ),
					'text'       => self::color( $colors['text'] ?? '#1e1e1e', '#1e1e1e' ),
				),
				'typography' => array(
					'font_family' => is_string( $font ) && '' !== $font ? $font : 'system-ui, sans-serif',
					'base_size'   => max( 12, min( 22, absint( $type['base_size'] ?? 16 ) ) ),
				),
			),
		);
	}

	/**
	 * Default settings.
	 *
	 * @return array<string, mixed>
	 */
	private static function defaults(): array {
		return self::sanitize( array() );
	}

	/**
	 * Sanitize a hex color.
	 *
	 * @param mixed  $value    Color value.
	 * @param string $fallback Fallback color.
	 * @return string
	 */
	private static function color( mixed $value, string $fallback ): string {
		$color = sanitize_hex_color( (string) $value );
		return is_string( $color ) ? $color : $fallback;
	}
}

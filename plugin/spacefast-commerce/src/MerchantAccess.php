<?php
/** @package SpacefastCommerce */
declare(strict_types=1);
namespace SpacefastCommerce;

/**
 * D10 merchant administration and D20 password logins.
 *
 * The Spacefast engine projects `access.manage` principals as a stripped `administrator` and its
 * `user_has_cap` filter (priority 10) replaces the capability array. This filter runs after it, at
 * priority 20, and adds a named list of WooCommerce store capabilities. The list is written out
 * here, never copied from Woo's `shop_manager` role, and grants nothing outside WooCommerce:
 * no plugin, theme, user or `manage_options` capability.
 */
final class MerchantAccess {
	private const STORE_CAPS = array(
		'manage_woocommerce', 'view_woocommerce_reports', 'view_admin_dashboard',
		// Products (post type `product`) and their categories, tags and attributes.
		'edit_product', 'read_product', 'delete_product', 'edit_products', 'edit_others_products',
		'publish_products', 'read_private_products', 'delete_products', 'delete_private_products',
		'delete_published_products', 'delete_others_products', 'edit_private_products',
		'edit_published_products', 'manage_product_terms', 'edit_product_terms',
		'delete_product_terms', 'assign_product_terms',
		// Orders (post type `shop_order`, also checked by HPOS screens).
		'edit_shop_order', 'read_shop_order', 'delete_shop_order', 'edit_shop_orders',
		'edit_others_shop_orders', 'publish_shop_orders', 'read_private_shop_orders',
		'delete_shop_orders', 'delete_private_shop_orders', 'delete_published_shop_orders',
		'delete_others_shop_orders', 'edit_private_shop_orders', 'edit_published_shop_orders',
		'manage_shop_order_terms', 'edit_shop_order_terms', 'delete_shop_order_terms',
		'assign_shop_order_terms',
		// Coupons (post type `shop_coupon`).
		'edit_shop_coupon', 'read_shop_coupon', 'delete_shop_coupon', 'edit_shop_coupons',
		'edit_others_shop_coupons', 'publish_shop_coupons', 'read_private_shop_coupons',
		'delete_shop_coupons', 'delete_private_shop_coupons', 'delete_published_shop_coupons',
		'delete_others_shop_coupons', 'edit_private_shop_coupons', 'edit_published_shop_coupons',
		'manage_shop_coupon_terms', 'edit_shop_coupon_terms', 'delete_shop_coupon_terms',
		'assign_shop_coupon_terms',
		// Webhooks have no post type since Woo 3.3; `manage_woocommerce` covers them.
	);

	public function register(): void {
		add_filter( 'user_has_cap', array( $this, 'store_caps' ), 20, 4 );
		add_filter( 'authenticate', array( $this, 'refuse_password' ), 99, 3 );
		add_action( 'lostpassword_post', array( $this, 'refuse_password_reset' ), 99 );
		add_action( 'validate_password_reset', array( $this, 'refuse_password_reset' ), 99 );
	}

	public static function caps(): array {
		return self::STORE_CAPS;
	}

	/** The merchant's existing role, not a new one: administrators on this store get the store list. */
	public function store_caps( array $allcaps, array $caps, array $args, \WP_User $user ): array {
		$merchant = function_exists( 'spacefast_content_space_id' )
			? '' !== spacefast_content_space_id() && $user->ID === get_current_user_id() && true === ( $allcaps['spacefast_manage_content'] ?? false )
			: in_array( 'administrator', (array) $user->roles, true );
		if ( ! $merchant ) {
			return $allcaps;
		}
		return array_merge( $allcaps, array_fill_keys( self::STORE_CAPS, true ) );
	}

	/**
	 * D20: on Spacefast the merchant signs in through Spacefast, never with a WordPress password.
	 * Only on the Spacefast engine: on an M2 host, a password is how the site owner gets in.
	 */
	public static function password_login_disabled(): bool {
		return (bool) apply_filters( 'spacefast_commerce_refuse_password_login', function_exists( 'spacefast_content_principal_ensure_user' ) );
	}

	public function refuse_password( mixed $user, mixed $username, mixed $password ): mixed {
		if ( ! self::password_login_disabled() || ! is_string( $password ) || '' === $password ) {
			return $user;
		}
		return new \WP_Error( 'spacefast_password_login_disabled', __( 'Password sign-in is off for this store. Sign in through Spacefast.', 'spacefast-commerce' ) );
	}

	/** Both core and Woo inspect this shared error object before issuing or accepting a reset. */
	public function refuse_password_reset( \WP_Error $errors ): void {
		if ( ! self::password_login_disabled() ) {
			return;
		}
		// Core may already have added an unknown-email error. Keep the refusal identical for every account.
		foreach ( $errors->get_error_codes() as $code ) {
			$errors->remove( $code );
		}
		$errors->add( 'spacefast_password_login_disabled', __( 'Password sign-in is off for this store. Sign in through Spacefast.', 'spacefast-commerce' ) );
	}
}

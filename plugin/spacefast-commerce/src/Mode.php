<?php
/** @package SpacefastCommerce */
declare(strict_types=1);
namespace SpacefastCommerce;

/**
 * Test/live mode (D10, D21). Test is Woo's default behavior with the dummy gateway and a
 * checkout banner. Live removes the dummy gateway on every request and needs Stripe enabled;
 * going live silently cancels dummy-paid orders, restores their stock and revokes downloads.
 * An order paid through the dummy gateway is a test order; no other record marks it.
 */
final class Mode {
	public const TEST_GATEWAY = 'dummy';

	/** @var array<int, true> Orders being cancelled by go-live in this process. */
	private array $silenced = array();

	public function __construct( private Store $store ) {}

	public function register(): void {
		add_filter( 'woocommerce_payment_gateways', array( $this, 'gateways' ), PHP_INT_MAX );
		add_filter( 'woocommerce_available_payment_gateways', array( $this, 'available' ), PHP_INT_MAX );
		add_action( 'woocommerce_blocks_payment_method_type_registration', array( $this, 'blocks' ), PHP_INT_MAX );
		add_filter( 'render_block_woocommerce/checkout', array( $this, 'checkout_banner' ) );
		add_action( 'woocommerce_before_checkout_form', static function (): void {
			echo self::banner_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in banner_html().
		} );
		add_filter( 'woocommerce_mail_callback', array( $this, 'silence_mail' ), PHP_INT_MAX, 2 );
		add_filter( 'woocommerce_webhook_should_deliver', array( $this, 'webhook' ), PHP_INT_MAX, 3 );
	}

	public static function is_test_order( mixed $order ): bool {
		return $order instanceof \WC_Order && self::TEST_GATEWAY === $order->get_payment_method();
	}

	/** Live: the dummy gateway does not exist, on every request. */
	public function gateways( array $gateways ): array {
		if ( 'live' !== $this->store->mode() ) {
			return $gateways;
		}
		// The pinned dummy gateway registers its class name (woocommerce-gateway-dummy 2.0.0).
		return array_values( array_filter( $gateways, static fn ( $gateway ): bool => ! is_a( $gateway, 'WC_Gateway_Dummy', true ) ) );
	}

	public function available( array $gateways ): array {
		if ( 'live' === $this->store->mode() ) {
			unset( $gateways[ self::TEST_GATEWAY ] );
		}
		return $gateways;
	}

	/** The pinned dummy block integration assumes its gateway exists during initialize(). */
	public function blocks( \Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $registry ): void {
		if ( 'live' === $this->store->mode() && $registry->is_registered( self::TEST_GATEWAY ) ) {
			$registry->unregister( self::TEST_GATEWAY );
		}
	}

	public static function banner_html(): string {
		if ( 'live' === get_option( 'spacefast_commerce_mode' ) ) {
			return '';
		}
		$text = __( 'This is a test store. The test payment method takes no money. Other payment methods use their own settings.', 'spacefast-commerce' );
		if ( ! wc_tax_enabled() ) {
			$text .= ' ' . __( 'Taxes are not charged yet.', 'spacefast-commerce' );
		}
		return '<div class="spacefast-commerce-test-banner" role="status" style="background:#fff4ce;border:1px solid #e0b400;border-radius:6px;color:#3d2e00;margin:0 0 24px;padding:12px 16px;">' . esc_html( $text ) . '</div>';
	}

	public function checkout_banner( string $content ): string {
		return self::banner_html() . $content;
	}

	public function stripe_enabled(): bool {
		$gateway = WC()->payment_gateways()->payment_gateways()['stripe'] ?? null;
		return $gateway instanceof \WC_Payment_Gateway && 'yes' === $gateway->enabled;
	}

	public function set( string $mode, ?string $canonical_origin = null, ?int $generation = null, ?int $command_generation = null ): array|\WP_Error {
		if ( null !== $canonical_origin ) {
			$canonical_origin = Store::normalize_origin( $canonical_origin );
			if ( is_wp_error( $canonical_origin ) ) {
				return $canonical_origin;
			}
		}
		return $this->store->with_lock( function () use ( $mode, $canonical_origin, $generation, $command_generation ): array|\WP_Error {
			$fence = $this->store->accept_command( $generation, $command_generation );
			if ( is_wp_error( $fence ) ) {
				return $fence;
			}
			if ( 'live' === $mode ) {
				$orders = Orders::count();
				if ( is_wp_error( $orders ) ) {
					return $orders;
				}
			}
			if ( 'live' === $mode && ! $this->stripe_enabled() ) {
				return Bundle::refuse( 'stripe-not-enabled', 'Set up and enable the Stripe gateway in WooCommerce → Settings → Payments before going live.' );
			}
			if ( 'test' === $mode ) {
				if ( null !== $canonical_origin ) {
					$this->store->set_canonical_origin( $canonical_origin );
				}
				update_option( 'spacefast_commerce_mode', 'test', true );
				$settings = get_option( 'woocommerce_dummy_settings', array() );
				update_option( 'woocommerce_dummy_settings', array_merge( is_array( $settings ) ? $settings : array(), array( 'enabled' => 'yes', 'result' => 'success' ) ) );
				return array( 'ok' => true, 'mode' => 'test', 'cancelledTestOrders' => 0 );
			}
			$cancelled = $this->cancel_test_orders();
			if ( is_wp_error( $cancelled ) ) {
				return $cancelled;
			}
			if ( null !== $canonical_origin ) {
				$this->store->set_canonical_origin( $canonical_origin );
			}
			update_option( 'spacefast_commerce_mode', 'live', true );
			return array( 'ok' => true, 'mode' => 'live', 'cancelledTestOrders' => $cancelled );
		} );
	}

	/** Going live: cancel every dummy-paid order through Woo, silently, and revoke its downloads. */
	private function cancel_test_orders(): int|\WP_Error {
		global $wpdb;
		$wpdb->last_error = '';
		$orders = wc_get_orders( array(
			'type' => 'shop_order',
			'payment_method' => self::TEST_GATEWAY,
			'status' => array_merge( array( 'trash' ), array_keys( wc_get_order_statuses() ) ),
			'limit' => -1,
			'cache_results' => false,
		) );
		if ( '' !== $wpdb->last_error || ! is_array( $orders ) ) {
			return Bundle::refuse( 'orders-unavailable', 'Test orders could not be read for go-live cleanup. Retry when the store database is available.' );
		}
		$downloads = \WC_Data_Store::load( 'customer-download' );
		$cancelled = 0;
		foreach ( $orders as $order ) {
			$this->silenced[ $order->get_id() ] = true;
			$order->update_meta_data( '_spacefast_test_order_cancelled', gmdate( 'c' ) );
			// Woo's cancelled transition restores reduced stock (wc_maybe_increase_stock_levels).
			if ( ! $order->has_status( 'cancelled' ) ) {
				$order->update_status( 'cancelled', __( 'Test order cancelled when the store went live.', 'spacefast-commerce' ) );
				++$cancelled;
			} else {
				$order->save();
			}
			// Also repair an interrupted cancelled transition; Woo's stock marker makes this idempotent.
			wc_maybe_increase_stock_levels( $order->get_id() );
			$downloads->delete_by_order_id( $order->get_id() );
		}
		return $cancelled;
	}

	/** The cleanup marker survives queued webhook delivery in another process. */
	public function webhook( bool $deliver, mixed $webhook, mixed $arg ): bool {
		if ( ! $webhook instanceof \WC_Webhook || 'order' !== $webhook->get_resource() || ! is_scalar( $arg ) ) {
			return $deliver;
		}
		$order = wc_get_order( (int) $arg );
		return isset( $this->silenced[ (int) $arg ] ) || ( $order instanceof \WC_Order && '' !== (string) $order->get_meta( '_spacefast_test_order_cancelled' ) ) ? false : $deliver;
	}

	/** Cancelled-order emails for go-live cleanup never send, including deferred ones. */
	public function silence_mail( mixed $callback, \WC_Email $email ): mixed {
		$order = $email->object;
		if ( $order instanceof \WC_Order && ( isset( $this->silenced[ $order->get_id() ] ) ||
			( '' !== (string) $order->get_meta( '_spacefast_test_order_cancelled' ) && str_contains( $email->id, 'cancelled' ) ) ) ) {
			return static fn (): bool => true;
		}
		return $callback;
	}
}

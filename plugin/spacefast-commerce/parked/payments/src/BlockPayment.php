<?php
/** @package SpacefastCommerce */
declare(strict_types=1);
namespace SpacefastCommerce;

/** Checkout Block hands the ordinary order to the same gateway as classic checkout. */
final class BlockPayment extends \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType {
	protected $name = 'spacefast_connect';

	public function initialize(): void {}

	public function is_active(): bool {
		return ( new Gateway() )->is_available();
	}

	public function get_payment_method_script_handles(): array {
		wp_register_script( 'spacefast-payment-block', plugins_url( 'assets/payment-block.js', dirname( __DIR__ ) . '/spacefast-commerce.php' ),
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element' ), '0.1.0', true );
		return array( 'spacefast-payment-block' );
	}

	public function get_payment_method_data(): array {
		return array( 'title' => 'Card', 'description' => 'Pay securely with Stripe.', 'supports' => $this->get_supported_features() );
	}
}

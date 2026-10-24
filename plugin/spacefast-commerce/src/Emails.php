<?php
/** @package SpacefastCommerce */
declare(strict_types=1);
namespace SpacefastCommerce;

/**
 * D8 email binding. Compiled templates arrive in store.json and are stored privately (an option,
 * never public files). `wc_get_template` swaps a bound email's HTML template for one
 * renderer, which fills six `<sf-*>` slots by token replacement on the stored HTML. The stored
 * HTML is data: it is never included or evaluated. Woo keeps triggers, recipients, enablement,
 * headers, CSS inlining and the plain-text part.
 */
final class Emails {
	/** Same syntax as the page slots' ELEMENT_PATTERN (packages/common/src/utils/pages.ts). */
	private const SLOT = '/<\s*(sf-[a-z0-9-]+)\b[^>]*>(?:\s*<\s*\/\s*\1\s*>)?|<\s*(sf-[a-z0-9-]+)\b[^>]*\/\s*>/i';

	public function __construct( private Store $store ) {}

	public function register(): void {
		add_filter( 'wc_get_template', array( $this, 'locate' ), 10, 5 );
		add_filter( 'woocommerce_email_classes', array( $this, 'classic_for_bound' ), PHP_INT_MAX );
		add_filter( 'woocommerce_transactional_emails_for_block_editor', fn ( array $ids ): array => array_values( array_diff( $ids, array_keys( $this->bound() ) ) ), PHP_INT_MAX );
		add_filter( 'woocommerce_mail_callback_params', array( $this, 'test_banner' ), PHP_INT_MAX, 2 );
		add_action( 'admin_notices', array( $this, 'settings_notice' ) );
	}

	/** @return array<string, array{html: string, sha256: string}> */
	public function bound(): array {
		$emails = get_option( 'spacefast_commerce_emails', array() );
		return is_array( $emails ) ? $emails : array();
	}

	public function store( array $emails ): void {
		update_option( 'spacefast_commerce_emails', $emails, false );
	}

	/** Select after Woo's path cache, including a previously cached owned renderer. */
	public function locate( string $template, string $name, array $args = array(), string $template_path = '', string $default_path = '' ): string {
		$id = array_search( $name, Bundle::EMAILS, true );
		$owned = dirname( __DIR__ ) . '/templates/email.php';
		if ( false !== $id && isset( $this->bound()[ $id ] ) ) {
			return $owned;
		}
		return $template === $owned ? wc_locate_template( $name, $template_path, $default_path ) : $template;
	}

	/** The template override assumes classic emails; the block editor stays off for bound types. */
	public function classic_for_bound( array $emails ): array {
		foreach ( $emails as $email ) {
			if ( $email instanceof \WC_Email && isset( $this->bound()[ $email->id ] ) ) {
				$email->block_email_editor_enabled = false;
			}
		}
		return $emails;
	}

	public function settings_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice on Woo's settings screen.
		if ( 'wc-settings' !== ( $_GET['page'] ?? '' ) || 'email' !== ( $_GET['tab'] ?? '' ) || array() === $this->bound() ) {
			return;
		}
		$names = array();
		foreach ( WC()->mailer()->get_emails() as $email ) {
			if ( isset( $this->bound()[ $email->id ] ) ) {
				$names[] = $email->get_title();
			}
		}
		echo '<div class="notice notice-info"><p>' . esc_html( sprintf(
			/* translators: %s: email names */
			__( 'Your store source designs these emails: %s. Edit their subject, heading and additional content here; the layout comes from the source. The block email editor is off for them.', 'spacefast-commerce' ),
			implode( ', ', $names )
		) ) . '</p></div>';
	}

	/** Called by templates/email.php with Woo's own template arguments. */
	public function render( \WC_Email $email, mixed $order, bool $sent_to_admin, string $heading, string $additional ): string {
		$template = $this->bound()[ $email->id ]['html'] ?? '';
		$origin = $this->store->canonical_origin() ?? untrailingslashit( home_url() );
		$name = get_bloginfo( 'name' );
		$template = $this->absolute_urls( $template, $origin );
		$hook = static function ( string $action ) use ( $order, $sent_to_admin, $email ): string {
			ob_start();
			do_action( $action, $order, $sent_to_admin, false, $email );
			return (string) ob_get_clean();
		};
		$slots = array(
			'sf-store-name' => static fn (): string => esc_html( $name ),
			'sf-email-heading' => static fn (): string => esc_html( $heading ),
			'sf-order-details' => static fn (): string => $hook( 'woocommerce_email_order_details' ) . $hook( 'woocommerce_email_order_meta' ),
			'sf-customer-details' => static fn (): string => $hook( 'woocommerce_email_customer_details' ),
			'sf-additional-content' => static fn (): string => '' === trim( $additional ) ? '' : wp_kses_post( wpautop( wptexturize( $additional ) ) ),
			'sf-store-link' => static fn (): string => '<a href="' . esc_url( $origin ) . '">' . esc_html( $name ) . '</a>',
		);
		$html = (string) preg_replace_callback( self::SLOT, static function ( array $match ) use ( $slots ): string {
			$slot = strtolower( '' !== ( $match[1] ?? '' ) ? $match[1] : $match[2] );
			return isset( $slots[ $slot ] ) ? $slots[ $slot ]() : '';
		}, $template );
		return $this->absolute_urls( $html, $origin );
	}

	/** Relative src/href in the stored template resolve against the canonical origin, even from cron and WP-CLI. */
	private function absolute_urls( string $html, string $origin ): string {
		return (string) preg_replace_callback( '/\b(src|href)\s*=\s*(["\'])(.*?)\2/i', static function ( array $match ) use ( $origin ): string {
			$value = $match[3];
			if ( '' === $value || str_starts_with( $value, '#' ) || str_starts_with( $value, '//' ) || preg_match( '/^[a-z][a-z0-9+.-]*:/i', $value ) ) {
				return $match[0];
			}
			$path = str_starts_with( $value, '/' ) ? $value : '/' . preg_replace( '#^\./#', '', $value );
			return $match[1] . '=' . $match[2] . $origin . $path . $match[2];
		}, $html );
	}

	/** Every customer and admin email about a dummy-paid order carries the test banner, Woo defaults included. */
	public function test_banner( array $params, \WC_Email $email ): array {
		if ( ! Mode::is_test_order( $email->object ) ) {
			return $params;
		}
		$text = __( 'Test order — no payment was taken.', 'spacefast-commerce' );
		$message = (string) $params[2];
		if ( 'text/plain' === $email->get_content_type() ) {
			$params[2] = $text . "\n\n" . $message;
			return $params;
		}
		$banner = '<div class="spacefast-commerce-test-banner" style="background:#fff4ce;border:1px solid #e0b400;color:#3d2e00;font-family:sans-serif;font-size:14px;margin:0 0 16px;padding:12px 16px;text-align:center;">' . esc_html( $text ) . '</div>';
		$with_body = preg_replace( '/<body\b[^>]*>/i', '$0' . $banner, $message, 1, $count );
		$params[2] = $count ? $with_body : $banner . $message;
		return $params;
	}

	/** `wp spacefast-commerce email render`: the email's real trigger(), transport short-circuited. */
	public function render_order( int $order_id, string $email_id ): array|\WP_Error {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return Bundle::refuse( 'order-not-found', 'No order #' . $order_id . '.' );
		}
		$email = null;
		foreach ( WC()->mailer()->get_emails() as $candidate ) {
			$email = $candidate->id === $email_id ? $candidate : $email;
		}
		if ( ! $email ) {
			return Bundle::refuse( 'email-unknown', 'WooCommerce has no email "' . $email_id . '".' );
		}
		$captured = null;
		$capture = static function ( $short_circuit, array $atts ) use ( &$captured ) {
			$captured = $atts;
			return false;
		};
		add_filter( 'pre_wp_mail', $capture, PHP_INT_MAX, 2 );
		add_filter( 'woocommerce_new_order_email_allows_resend', '__return_true' );
		try {
			$email->trigger( $order->get_id(), $order );
		} finally {
			remove_filter( 'pre_wp_mail', $capture, PHP_INT_MAX );
			remove_filter( 'woocommerce_new_order_email_allows_resend', '__return_true' );
		}
		if ( null === $captured ) {
			return Bundle::refuse( 'email-not-sent', 'WooCommerce did not send "' . $email_id . '" for this order (disabled, or no recipient).' );
		}
		$to = is_array( $captured['to'] ) ? $captured['to'] : array_map( 'trim', explode( ',', (string) $captured['to'] ) );
		return array( 'ok' => true, 'to' => array_values( $to ), 'subject' => (string) $captured['subject'], 'html' => (string) $captured['message'] );
	}
}

<?php
/** @package SpacefastCommerce */
declare(strict_types=1);
namespace SpacefastCommerce;

/**
 * D9 "Woo's own pages" and "No second storefront": the companion owns the checkout and account
 * pages at fixed slugs, removes the cart page (the storefront's /cart/ owns the cart), and sends
 * every other theme-rendered Woo page back to the storefront. D20: no customer accounts.
 */
final class Storefront {
	public const PAGES = array(
		'checkout' => array(
			'slug' => 'checkout',
			'title' => 'Checkout',
			'option' => 'woocommerce_checkout_page_id',
			'block' => 'woocommerce/checkout',
			'shortcode' => 'woocommerce_checkout',
			'clash' => 'checkout-page-clash',
		),
		'myaccount' => array(
			'slug' => 'my-account',
			'title' => 'My account',
			'option' => 'woocommerce_myaccount_page_id',
			'block' => null,
			'shortcode' => 'woocommerce_my_account',
			'clash' => 'account-page-clash',
		),
	);

	/** Options the companion owns outright on a Woo Space (D9, D20). */
	private const FORCED_OPTIONS = array(
		'woocommerce_enable_guest_checkout' => 'yes',
		'woocommerce_enable_checkout_login_reminder' => 'no',
		'woocommerce_enable_signup_and_login_from_checkout' => 'no',
		'woocommerce_enable_myaccount_registration' => 'no',
		'woocommerce_enable_delayed_account_creation' => 'no',
	);

	public function __construct( private Store $store, private ManagedProducts $managed ) {}

	public function register(): void {
		foreach ( self::FORCED_OPTIONS as $option => $value ) {
			add_filter( 'pre_option_' . $option, static fn (): string => $value );
		}
		foreach ( self::PAGES as $page ) {
			add_filter( 'pre_update_option_' . $page['option'], fn ( mixed $value, mixed $old ): mixed => $this->managed->applying() ? $value : $old, 10, 2 );
		}
		add_filter( 'pre_update_option_woocommerce_cart_page_id', fn ( mixed $value, mixed $old ): mixed => $this->managed->applying() ? $value : $old, 10, 2 );
		add_filter( 'wp_insert_post_data', array( $this, 'guard_page' ), 10, 2 );
		add_filter( 'pre_trash_post', array( $this, 'guard_delete' ), 10, 2 );
		add_filter( 'pre_delete_post', array( $this, 'guard_delete' ), 10, 2 );
		add_filter( 'woocommerce_get_cart_url', fn (): string => $this->store->url( '/cart/' ) );
		add_filter( 'wc_get_template', array( $this, 'account_login_template' ), 10, 2 );
		add_filter( 'post_type_link', array( $this, 'product_link' ), 10, 2 );
		add_action( 'template_redirect', array( $this, 'redirect' ), 2 );
		add_action( 'template_redirect', array( $this, 'not_found' ), 99 );
	}

	/** Guest shoppers need their order links; hosts that allow passwords keep Woo's login. */
	public function account_login_template( string $template, string $name ): string {
		return in_array( $name, array( 'myaccount/form-login.php', 'myaccount/form-lost-password.php', 'myaccount/form-reset-password.php' ), true ) && MerchantAccess::password_login_disabled()
			? dirname( __DIR__ ) . '/templates/guest-account.php'
			: $template;
	}

	private function page_id( array $page ): int {
		return (int) get_option( $page['option'], 0 );
	}

	private function valid_content( array $page, string $content ): bool {
		return ( null !== $page['block'] && has_block( $page['block'], $content ) ) || has_shortcode( $content, $page['shortcode'] );
	}

	/** D9 preflight: before Woo installs, a page already at /checkout/ or /my-account/ fails setup. */
	public static function preflight(): true|\WP_Error {
		foreach ( self::PAGES as $page ) {
			$existing = get_page_by_path( $page['slug'], OBJECT, array( 'page', 'post' ) );
			if ( $existing ) {
				return Bundle::refuse( $page['clash'], 'Page #' . $existing->ID . ' "' . $existing->post_title . '" already sits at /' . $page['slug'] . '/. Move or delete it, then enable the store again.' );
			}
		}
		return true;
	}

	/** Refusal phase: the fixed slugs must be free for the assigned pages. */
	public function check_pages(): true|\WP_Error {
		foreach ( self::PAGES as $page ) {
			$occupant = get_page_by_path( $page['slug'] );
			if ( $occupant && $occupant->ID !== $this->page_id( $page ) && 0 === $occupant->post_parent ) {
				return Bundle::refuse( 'store-page-slug', 'Page #' . $occupant->ID . ' "' . $occupant->post_title . '" sits at /' . $page['slug'] . '/, where the store\'s ' . strtolower( $page['title'] ) . ' page must be. Move or delete it.' );
			}
		}
		return true;
	}

	/** Write phase: pages at the fixed slugs, published, with Woo's block; no cart page. Idempotent. */
	public function ensure_pages(): true|\WP_Error {
		$check = $this->check_pages();
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		return $this->managed->apply( function (): true|\WP_Error {
			foreach ( self::PAGES as $name => $page ) {
				$id = $this->page_id( $page );
				$post = $id ? get_post( $id ) : null;
				$content = $post && 'page' === $post->post_type && $this->valid_content( $page, $post->post_content )
					? $post->post_content
					: ( 'checkout' === $name
						// Woo 11.2 keeps its canonical nested checkout markup in a protected factory.
						? (string) ( new \ReflectionMethod( \WC_Install::class, 'get_checkout_block_content' ) )->invoke( null )
						: '<!-- wp:shortcode -->[woocommerce_my_account]<!-- /wp:shortcode -->' );
				$fields = array(
					'post_type' => 'page',
					'post_status' => 'publish',
					'post_name' => $page['slug'],
					'post_parent' => 0,
					'post_content' => $content,
				);
				if ( $post && 'page' === $post->post_type && 'trash' !== $post->post_status ) {
					$changed = array_filter( $fields, static fn ( $value, string $field ): bool => (string) $post->{$field} !== (string) $value, ARRAY_FILTER_USE_BOTH );
					$result = array() === $changed ? $id : wp_update_post( wp_slash( array( 'ID' => $id ) + $changed ), true );
				} else {
					$result = wp_insert_post( wp_slash( $fields + array( 'post_title' => $page['title'] ) ), true );
				}
				if ( is_wp_error( $result ) || get_post_field( 'post_name', $result ) !== $page['slug'] ) {
					return Bundle::refuse( 'store-page-slug', 'The store\'s ' . strtolower( $page['title'] ) . ' page could not be placed at /' . $page['slug'] . '/.' );
				}
				update_option( $page['option'], (int) $result );
			}
			$cart = (int) get_option( 'woocommerce_cart_page_id', 0 );
			$post = $cart > 0 ? get_post( $cart ) : null;
			$managed_pages = array_map( fn ( array $page ): int => $this->page_id( $page ), self::PAGES );
			if ( $post && 'page' === $post->post_type && ! in_array( $cart, $managed_pages, true ) &&
				'cart' === $post->post_name && ( has_block( 'woocommerce/cart', $post->post_content ) || has_shortcode( $post->post_content, 'woocommerce_cart' ) ) ) {
				wp_delete_post( $cart, true );
			}
			update_option( 'woocommerce_cart_page_id', 0 );
			return true;
		} );
	}

	/** wp-admin may edit the pages' block settings, never their slug, place or block (D9). */
	public function guard_page( array $data, array $post ): array {
		$id = (int) ( $post['ID'] ?? 0 );
		if ( ! $id || $this->managed->applying() ) {
			return $data;
		}
		foreach ( self::PAGES as $page ) {
			if ( $id !== $this->page_id( $page ) ) {
				continue;
			}
			$current = get_post( $id, ARRAY_A );
			foreach ( array( 'post_name', 'post_parent', 'post_status', 'post_type' ) as $field ) {
				$data[ $field ] = $current[ $field ];
			}
			if ( ! $this->valid_content( $page, wp_unslash( $data['post_content'] ?? '' ) ) ) {
				$data['post_content'] = wp_slash( $current['post_content'] );
			}
		}
		return $data;
	}

	public function guard_delete( mixed $check, \WP_Post $post ): mixed {
		if ( $this->managed->applying() ) {
			return $check;
		}
		foreach ( self::PAGES as $page ) {
			if ( $post->ID === $this->page_id( $page ) ) {
				return false;
			}
		}
		return $check;
	}

	/** Product links in checkout, confirmation and emails land on the storefront's own page. */
	public function product_link( string $link, \WP_Post $post ): string {
		if ( 'product' !== $post->post_type ) {
			return $link;
		}
		$page = get_post_meta( $post->ID, '_spacefast_page', true );
		return is_string( $page ) && '' !== $page ? $this->store->url( $page ) : $link;
	}

	/** No second storefront: Woo's theme-rendered pages redirect to the static storefront. */
	public function redirect(): void {
		$target = null;
		if ( is_singular( 'product' ) ) {
			$page = get_post_meta( (int) get_queried_object_id(), '_spacefast_page', true );
			$target = is_string( $page ) && '' !== $page ? $page : '/';
		} elseif ( is_shop() || is_product_taxonomy() || is_post_type_archive( 'product' ) || ( is_search() && 'product' === get_query_var( 'post_type' ) ) ) {
			$target = '/';
		}
		if ( null !== $target ) {
			wp_safe_redirect( $target, 302, 'Spacefast Commerce' );
			exit;
		}
	}

	/** WordPress 404s serve the Space's own `_pages/404.html` when the host provides it. */
	public function not_found(): void {
		if ( ! is_404() ) {
			return;
		}
		$file = function_exists( 'spacefast_commerce_runtime_not_found_file' ) ? spacefast_commerce_runtime_not_found_file() : null;
		$file ??= defined( 'SPACEFAST_COMMERCE_NOT_FOUND_FILE' ) ? SPACEFAST_COMMERCE_NOT_FOUND_FILE : null;
		$file = apply_filters( 'spacefast_commerce_not_found_file', $file );
		if ( ! is_string( $file ) || ! is_readable( $file ) ) {
			return;
		}
		status_header( 404 );
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		readfile( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- The Space's own static 404 page.
		exit;
	}
}

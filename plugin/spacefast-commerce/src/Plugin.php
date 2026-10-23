<?php
/** @package SpacefastCommerce */
declare(strict_types=1);
namespace SpacefastCommerce;

/** Managed-commerce composition root. The parked payments module is not loaded in M1. */
final class Plugin {
	private static ?self $instance = null;

	public Store $store;
	public ManagedProducts $managed;
	public PrivateFiles $files;
	public Media $media;
	public Shipping $shipping;
	public Storefront $storefront;
	public Emails $emails;
	public Mode $mode;
	public MerchantAccess $access;
	public Catalog $catalog;
	public Provisioning $provisioning;

	public static function instance(): ?self {
		return self::$instance;
	}

	public static function boot(): void {
		if ( null !== self::$instance || ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		$plugin = new self();
		$plugin->store = new Store();
		$plugin->managed = new ManagedProducts();
		$plugin->files = new PrivateFiles();
		$plugin->media = new Media();
		$plugin->shipping = new Shipping( $plugin->managed );
		$plugin->storefront = new Storefront( $plugin->store, $plugin->managed );
		$plugin->emails = new Emails( $plugin->store );
		$plugin->mode = new Mode( $plugin->store );
		$plugin->access = new MerchantAccess();
		$plugin->catalog = new Catalog( $plugin->store, $plugin->managed, $plugin->files, $plugin->media, $plugin->shipping, $plugin->storefront, $plugin->emails );
		$plugin->provisioning = new Provisioning( $plugin );
		foreach ( array( $plugin->store, $plugin->managed, $plugin->files, $plugin->shipping, $plugin->storefront, $plugin->emails, $plugin->mode, $plugin->access ) as $module ) {
			$module->register();
		}
		self::$instance = $plugin;
	}
}

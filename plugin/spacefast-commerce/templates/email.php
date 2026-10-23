<?php
/**
 * The one renderer for every source-bound Woo email (D8). Woo includes this file in place of its
 * own email template; the stored template HTML is filled by token replacement, never included.
 *
 * @package SpacefastCommerce
 * @var WC_Email $email
 * @var WC_Order $order
 * @var bool     $sent_to_admin
 * @var string   $email_heading
 * @var string   $additional_content
 */

defined( 'ABSPATH' ) || exit;

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Slots are escaped per kind in Emails::render().
echo \SpacefastCommerce\Plugin::instance()->emails->render( $email, $order, (bool) $sent_to_admin, (string) $email_heading, (string) $additional_content );

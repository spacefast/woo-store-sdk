<?php
/** Disposable integration fixture only: capture native Woo emails in local Mailpit. */
add_action( 'phpmailer_init', static function ( $mailer ): void {
	$mailer->isSMTP();
	$mailer->Host = getenv( 'COMMERCE_SMTP_HOST' ) ?: 'mail';
	$mailer->Port = 1025;
	$mailer->SMTPAuth = false;
	$mailer->SMTPAutoTLS = false;
} );

# Native payment results

`PaymentOrders` owns credentialed native snapshots and result application. New intent acquisition checks the current managed catalog; verified paid and refund results use captured order identity and amounts. Source removal must not invalidate historical payments.

`PUT /wp-json/spacefast-commerce/v1/orders/{id}/payment/refunds` accepts an authenticated API-verified snapshot (`attempt_id`, `intent_id`, `total`, `currency`) and up to 100 successful provider refunds (`id`, native decimal `amount`). The API owns connected-account verification. This endpoint never initiates a Stripe refund.

Results serialize under the same native order lock as payment completion and shipment. Each result creates an ordinary `WC_Order_Refund` with `refund_payment: false`; the Stripe refund ID is attached before its first native save. Replays preserve native accounting and reject changed amounts or identity. A full refund can arrive before payment completion, including after the intent-setup response was lost, without granting downloads or sending paid-order email. Retry also repairs a lost final native refunded-status transition.

Amount-only partial refunds preserve Woo's paid download policy. Full refunds set the ordinary refunded status, which denies retained download links. Historical permission rows and purchased files remain available for audit; delivery authorization belongs to Woo. Merchant-initiated gateway refunds and dispute suspension/restoration are separate integrations.

Run the real CPT/HPOS and HTTP contract with `node e2e/commerce-fixture.mjs`; remove its disposable project with `node e2e/commerce-fixture.mjs down`. The separately opted-in API/Stripe relay contract lives in the consuming API repository.

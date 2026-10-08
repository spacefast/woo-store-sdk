# Native payment results

`PaymentOrders` owns credentialed native snapshots and result application. New intent acquisition checks the current managed catalog; verified paid and refund results use captured order identity and amounts. Source removal must not invalidate historical payments.

`PUT /wp-json/spacefast-commerce/v1/orders/{id}/payment/refunds` accepts an authenticated API-verified snapshot (`attempt_id`, `intent_id`, `total`, `currency`) and up to 100 successful provider refunds (`id`, native decimal `amount`). The API owns connected-account verification. This endpoint never initiates a Stripe refund.

Results serialize under the same native order lock as payment completion and shipment. Each result creates an ordinary `WC_Order_Refund` with `refund_payment: false`; the Stripe refund ID is attached before its first native save. Replays preserve native accounting and reject changed amounts or identity. A full refund can arrive before payment completion, including after the intent-setup response was lost, without granting downloads or sending paid-order email. Retry also repairs a lost final native refunded-status transition.

Amount-only partial refunds preserve Woo's paid download policy. Full refunds set the ordinary refunded status, which denies retained download links. Historical permission rows and purchased files remain available for audit; delivery authorization belongs to Woo. Merchant-initiated gateway refunds remain a separate integration.

Run the real CPT/HPOS and HTTP contract with `node e2e/commerce-fixture.mjs`; remove its disposable project with `node e2e/commerce-fixture.mjs down`. The separately opted-in API/Stripe relay contract lives in the consuming API repository.

`PUT /wp-json/spacefast-commerce/v1/orders/{id}/payment/disputes` imports current verified dispute IDs/statuses under the same order lock. Open disputes, inquiries and lost outcomes deny downloads, resend, shipping and gateway acquisition, even if an operator sets a paid status. Native on-hold transitions preserve grant rows, consumed counts and expiry. Won, closed-inquiry and prevented outcomes can restore the captured prior native status without payment completion or paid-order email renewal. A native refund or operator status change clears automatic restoration ownership. Full refund precedence prevents reopening delivery; closed won/lost/prevented outcomes cannot accept older outcomes. Missing provider records never clear previously recorded suspension.

Dispute metadata persists before status effects. A lost transition retries safely, while the native download filter already denies delivery during the gap. Verified partial refunds can be recorded while suspended; resolving the dispute restores the original native policy. The real CPT/HPOS owner covers native HTTP denial, consumed grant preservation, lost-transition recovery, operator override, full/partial refund ordering, pre-payment suspension, lost outcomes and stale closed outcomes.

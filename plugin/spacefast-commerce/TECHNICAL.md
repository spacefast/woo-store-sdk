# Native payment results

`PaymentOrders` owns credentialed native snapshots and result application. New intent acquisition checks the current managed catalog; verified paid and refund results use captured order identity and amounts. Source removal must not invalidate historical payments.

`PUT /wp-json/spacefast-commerce/v1/orders/{id}/payment/refunds` accepts an authenticated API-verified snapshot (`attempt_id`, `intent_id`, `total`, `currency`) and up to 100 successful provider refunds (`id`, native decimal `amount`). The API owns connected-account verification. This endpoint never initiates a Stripe refund.

Results serialize under the same native order lock as payment completion and shipment. Each result creates an ordinary `WC_Order_Refund` with `refund_payment: false`; the Stripe refund ID is attached before its first native save. Replays preserve native accounting and reject changed amounts or identity. A full refund can arrive before payment completion, including after the intent-setup response was lost, without granting downloads or sending paid-order email. Retry also repairs a lost final native refunded-status transition.

Amount-only partial refunds preserve Woo's paid download policy. Full refunds set the ordinary refunded status, which denies retained download links. Historical permission rows and purchased files remain available for audit; delivery authorization belongs to Woo. Merchant-initiated gateway refunds use the authenticated action below.

Run the real CPT/HPOS and HTTP contract with `node e2e/commerce-fixture.mjs`; remove its disposable project with `node e2e/commerce-fixture.mjs down`. The separately opted-in API/Stripe relay contract lives in the consuming API repository.

`PUT /wp-json/spacefast-commerce/v1/orders/{id}/payment/disputes` imports current verified dispute IDs/statuses under the same order lock. Open disputes, inquiries and lost outcomes deny downloads, resend, shipping and gateway acquisition, even if an operator sets a paid status. Native on-hold transitions preserve grant rows, consumed counts and expiry. Won, closed-inquiry and prevented outcomes can restore the captured prior native status without payment completion or paid-order email renewal. A native refund or operator status change clears automatic restoration ownership. Full refund precedence prevents reopening delivery; closed won/lost/prevented outcomes cannot accept older outcomes. Missing provider records never clear previously recorded suspension.

Dispute metadata persists before status effects. A lost transition retries safely, while the native download filter already denies delivery during the gap. Verified partial refunds can be recorded while suspended; resolving the dispute restores the original native policy. The real CPT/HPOS owner covers native HTTP denial, consumed grant preservation, lost-transition recovery, operator override, full/partial refund ordering, pre-payment suspension, lost outcomes and stale closed outcomes.


# Merchant refund action

`POST /wp-json/spacefast-commerce/v1/orders/{id}/refund` requires the same exact
bound store credential and scope headers. Its body contains `request_id` (UUID),
`amount` (positive decimal with two fraction digits), `reason` (up to 500
characters, normalized as native plain text), and `refund_application_fee`
(required boolean). Fee treatment is a merchant decision and has no default.

`Refunds` captures the command and original payment snapshot in parent order
metadata before HTTP. It calls the HTTPS `SPACEFAST_COMMERCE_API_ORIGIN`
`/commerce/refunds` endpoint using `SPACEFAST_COMMERCE_API_CREDENTIAL`; Stripe
secrets stay in the API. Configuration and TLS verification are the same trust
boundary as payment setup. Historical refunds do not depend on gateway
acquisition availability or the current catalog.

An unresolved command blocks a new request ID. Retries preserve payment identity,
amount, reason and fee policy, including after full native refund. Only an
explicit authenticated API `conflict` with matching `details.refundRequestId`
and `details.refundState=not_created` proves a refusal before money; arbitrary
errors retain ambiguity. Provider pending/action-required states retry the same
command; failed/canceled states cannot silently become a new financial request.

The API resolves the provider refund before native accounting. Under the same
order lock used by verified imports, an existing native Stripe refund ID is
reused. Otherwise stock `wc_create_refund(refund_payment: true)` invokes the
bundled gateway with an in-process context backed by the captured parent
command. Both request ID and provider ID attach before the first native save.
Direct Woo admin gateway refunds require this action's explicit fee context.
A process lost after provider persistence, native save or status transition can
retry without duplicating money or native rows. Completed commands retain their
native-accounting marker; an interrupted accounting step blocks another command.

The response includes native `refund_id`, `payment_refund_id`, `request_id`,
`order_id`, `status=refunded`, `amount`, `refund_application_fee` and
`refunded_total`. The consuming API's opt-in real Stripe/native test exercises
HTTPS callback trust, a lost API response, native gateway accounting and verified
webhook convergence. This boundary does not claim hosted checkout 3DS or live
provider readiness.


Native order details expose `can_refund`, `refundable_amount` and nullable
`pending_refund`. The pending view includes only request ID, amount, plain-text
reason, explicit fee decision and status; it never exposes the captured payment
snapshot or credentials. Succeeded money awaiting native accounting remains
pending. Completed/refused/failed/canceled commands do not block a new request.
Refundability requires a paid undisputed order with remaining balance and a
matching bound transaction. Pending retry remains available after a full refund.
Automatic digital fulfillment may have no tracking number; the native view emits
an empty number while preserving its ordinary fulfilled record.

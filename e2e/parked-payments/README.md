# Payment relay prior art

These contracts cover the retired credential-bound payment relay, refunds and disputes.
They are retained with `plugin/spacefast-commerce/parked/payments` for a future optional
payment integration. The native M1 fixture does not run them. Its contract lives in
`e2e/commerce-contract.php` and `e2e/commerce-e2e.mjs` and uses WooCommerce's own gateways,
order transitions, download permissions and email pipeline.

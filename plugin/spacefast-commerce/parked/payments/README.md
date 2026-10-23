# Parked: Spacefast Connect payments (M4 prior art)

Not loaded, not packaged (`.gitattributes` export-ignore). Milestone 1 takes
payments through the pinned WooCommerce Stripe gateway the merchant configures
(plan D7/D10); this Connect gateway, payment relay, refunds, disputes and
reconciliation are kept here verbatim for the optional M4 "SpacePay".

The files depend on the removed bearer-credential store binding (`Store.php`
here is the parked copy) and on the REST order actions (`OrderActions.php`, the
pre-M1 `Orders` class). Reviving them means re-designing that trust boundary;
do not wire them back into `spacefast-commerce.php` as-is.

(async () => {
  const config = window.spacefastPayment;
  const form = document.getElementById('spacefast-payment');
  if (!config || !form) return;
  const error = document.getElementById('spacefast-payment-error');
  const button = form.querySelector('button');
  try {
    const stripe = window.Stripe(config.publishableKey, { stripeAccount: config.accountId });
    const elements = stripe.elements({ clientSecret: config.clientSecret });
    elements.create('payment').mount('#spacefast-payment-element');
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      button.disabled = true;
      error.textContent = '';
      try {
        const result = await stripe.confirmPayment({ elements, confirmParams: { return_url: config.returnUrl } });
        if (result.error) error.textContent = result.error.message || 'Payment could not be confirmed.';
      } catch {
        error.textContent = 'Payment confirmation is unavailable. Retry this order.';
      } finally {
        button.disabled = false;
      }
    });
  } catch {
    error.textContent = 'Payment fields could not load. Refresh and retry.';
  }
})();

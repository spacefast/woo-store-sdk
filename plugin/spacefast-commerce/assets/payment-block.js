(() => {
  const settings = window.wc.wcSettings.getSetting('spacefast_connect_data', {});
  const content = window.wp.element.createElement('p', null, settings.description);
  window.wc.wcBlocksRegistry.registerPaymentMethod({
    name: 'spacefast_connect',
    label: window.wp.element.createElement('span', null, settings.title || 'Card'),
    content,
    edit: content,
    canMakePayment: () => true,
    ariaLabel: settings.title || 'Card',
    supports: { features: settings.supports || ['products'] },
  });
})();

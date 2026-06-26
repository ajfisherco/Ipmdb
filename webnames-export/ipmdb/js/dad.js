window.DAD = (() => {
  const etransfer = 'dad' + '@' + 'ajfisherco.com';
  const cardUrl = 'https://square.link/u/EcyDVlU3?src=ipmdb';
  const qrUrl = cardUrl;

  function selectedAmount(form) {
    const custom = Number(form.dadCustom.value || 0);
    if (custom > 0) return custom;
    const checked = form.querySelector('input[name="dadAmount"]:checked');
    return checked ? Number(checked.value) : 1;
  }

  function handleSubmit(form, receipt) {
    const amount = selectedAmount(form);
    receipt.textContent = 'Card selected for $' + amount + '. Opening Square checkout.';
    receipt.classList.remove('hidden');
    window.open(cardUrl, '_blank', 'noopener');
  }

  async function copyEtransfer(receipt) {
    try {
      await navigator.clipboard.writeText(etransfer);
      receipt.textContent = 'E-transfer address copied: ' + etransfer;
    } catch (error) {
      receipt.textContent = 'E-transfer: ' + etransfer;
    }
    receipt.classList.remove('hidden');
  }

  function openQr(receipt) {
    receipt.textContent = 'QR uses the same Square checkout link until final QR art is installed.';
    receipt.classList.remove('hidden');
    window.open(qrUrl, '_blank', 'noopener');
  }

  return { handleSubmit, copyEtransfer, openQr };
})();

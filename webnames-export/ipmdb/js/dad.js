window.DAD = (() => {
  const etransfer = 'dad' + '@' + 'ajfisherco.com';
  const cardUrl = '#card-link-pending';
  const qrUrl = './assets/qr/';

  function selectedAmount(form) {
    const custom = Number(form.dadCustom.value || 0);
    if (custom > 0) return custom;
    const checked = form.querySelector('input[name="dadAmount"]:checked');
    return checked ? Number(checked.value) : 1;
  }

  function handleSubmit(form, receipt) {
    const amount = selectedAmount(form);
    receipt.textContent = 'Contribution selected: $' + amount + '. Card link is ready for payment-provider wiring.';
    receipt.classList.remove('hidden');
    if (cardUrl !== '#card-link-pending') window.location.href = cardUrl;
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
    receipt.textContent = 'QR code folder path reserved: ' + qrUrl;
    receipt.classList.remove('hidden');
  }

  return { handleSubmit, copyEtransfer, openQr };
})();

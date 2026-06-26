document.addEventListener('DOMContentLoaded', () => {
  const ideaForm = document.getElementById('ideaForm');
  const ideaReceipt = document.getElementById('ideaReceipt');
  const ideaSource = document.getElementById('ideaSource');
  const sourceOtherWrap = document.getElementById('sourceOtherWrap');

  if (ideaSource && sourceOtherWrap) {
    ideaSource.addEventListener('change', () => {
      sourceOtherWrap.classList.toggle('hidden', ideaSource.value !== 'Other');
    });
  }

  if (ideaForm && ideaReceipt) {
    ideaForm.addEventListener('submit', event => {
      event.preventDefault();
      window.IPMdb.handleSubmit(ideaForm, ideaReceipt);
    });
  }

  const dadForm = document.getElementById('dadForm');
  const dadReceipt = document.getElementById('dadReceipt');
  const dadEtransfer = document.getElementById('dadEtransfer');
  const copyEtransfer = document.getElementById('copyEtransfer');
  const dadQr = document.getElementById('dadQr');

  if (dadForm && dadReceipt) {
    dadForm.addEventListener('submit', event => {
      event.preventDefault();
      window.DAD.handleSubmit(dadForm, dadReceipt);
    });
  }

  if (dadEtransfer && dadReceipt) dadEtransfer.addEventListener('click', () => window.DAD.copyEtransfer(dadReceipt));
  if (copyEtransfer && dadReceipt) copyEtransfer.addEventListener('click', () => window.DAD.copyEtransfer(dadReceipt));
  if (dadQr && dadReceipt) dadQr.addEventListener('click', () => window.DAD.openQr(dadReceipt));
});

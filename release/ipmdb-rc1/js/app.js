document.addEventListener('DOMContentLoaded', () => {
  const ideaForm = document.getElementById('ideaForm');
  const ideaReceipt = document.getElementById('ipm-status');
  const ideaSource = document.getElementById('ideaSource');
  const sourceOtherWrap = document.getElementById('sourceOtherWrap');
  const sourceOther = document.getElementById('sourceOther');

  if (ideaSource && sourceOtherWrap && sourceOther && window.IPMdb) {
    window.IPMdb.bindSourceOther(ideaSource, sourceOtherWrap, sourceOther);
  }

  if (ideaForm && ideaReceipt && window.IPMdb) {
    ideaForm.addEventListener('submit', (event) => {
      event.preventDefault();
      window.IPMdb.submitIdea(ideaForm, ideaReceipt);
    });
  }

  const dadForm = document.getElementById('dadForm');
  const dadReceipt = document.getElementById('dad-status');
  const dadEtransfer = document.getElementById('dadEtransfer');
  const dadQr = document.getElementById('dadQr');
  const copyEtransfer = document.getElementById('copyEtransfer');

  if (dadForm && dadReceipt && window.DAD) {
    dadForm.addEventListener('submit', (event) => {
      event.preventDefault();
      window.DAD.handleSubmit(dadForm, dadReceipt);
    });
  }

  if (dadEtransfer && dadReceipt && window.DAD) {
    dadEtransfer.addEventListener('click', () => window.DAD.copyEtransfer(dadReceipt));
  }

  if (dadQr && dadReceipt && window.DAD) {
    dadQr.addEventListener('click', () => window.DAD.openQr(dadReceipt));
  }

  if (copyEtransfer && dadReceipt && window.DAD) {
    copyEtransfer.addEventListener('click', () => window.DAD.copyEtransfer(dadReceipt));
  }
});

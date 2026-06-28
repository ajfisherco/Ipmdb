window.IPMdb = (() => {
  async function submitIdea(form, receipt) {
    const button = form.querySelector('button[type="submit"]');
    const payload = {
      title: form.ideaTitle.value.trim(),
      email: form.ideaEmail.value.trim(),
      source: form.ideaSource.value === 'Other' ? form.sourceOther.value.trim() : form.ideaSource.value,
      description: form.ideaDescription.value.trim()
    };

    if (!payload.title || !payload.email || !payload.source) {
      receipt.textContent = 'Please complete Idea Title, Email, and Source.';
      receipt.classList.remove('hidden');
      return;
    }

    try {
      button.disabled = true;
      receipt.textContent = 'Locking idea...';
      receipt.classList.remove('hidden');

      const response = await fetch('./api/submit.php', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify(payload)
      });

      const result = await response.json();
      if (!response.ok || !result.ok) throw new Error(result.error || 'Submission failed.');

      receipt.textContent = 'Idea locked. Asset ID: ' + result.asset_id + '. Acknowledgement email sent.';
      form.reset();
      window.Asset && window.Asset.setLastAssetId(result.asset_id);
    } catch (error) {
      receipt.textContent = error.message || 'Submission could not be completed.';
    } finally {
      button.disabled = false;
    }
  }

  function bindSourceOther(select, wrap, input) {
    function update() {
      const show = select.value === 'Other';
      wrap.classList.toggle('hidden', !show);
      if (show) input.focus();
      if (!show) input.value = '';
    }
    select.addEventListener('change', update);
    update();
  }

  return { submitIdea, bindSourceOther };
})();

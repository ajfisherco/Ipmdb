window.IPMdb = (() => {
  function serializeIdea(form) {
    const source = form.ideaSource.value === 'Other' ? form.sourceOther.value.trim() : form.ideaSource.value;
    return {
      title: form.ideaTitle.value.trim(),
      email: form.ideaEmail.value.trim(),
      source,
      description: form.ideaDescription.value.trim()
    };
  }

  function validateIdea(form) {
    if (!form.ideaTitle.value.trim()) return 'Add an idea title.';
    if (!form.ideaEmail.validity.valid) return 'Add a valid email.';
    if (!form.ideaSource.value) return 'Choose how you got here.';
    if (form.ideaSource.value === 'Other' && !form.sourceOther.value.trim()) return 'Add the custom source.';
    return '';
  }

  async function submitIdea(record) {
    const response = await fetch('./api/submit.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(record)
    });
    const payload = await response.json();
    if (!response.ok || !payload.ok) {
      throw new Error(payload.error || 'Submission failed.');
    }
    return payload;
  }

  async function handleSubmit(form, receipt) {
    const error = validateIdea(form);
    if (error) {
      receipt.textContent = error;
      receipt.classList.remove('hidden');
      return;
    }

    receipt.textContent = 'Locking idea...';
    receipt.classList.remove('hidden');

    try {
      const payload = await submitIdea(serializeIdea(form));
      receipt.textContent = 'Locked as ' + payload.asset_id + '. Email status: ' + (payload.mail_sent ? 'sent' : 'pending') + '.';
      form.reset();
    } catch (err) {
      receipt.textContent = err.message;
    }
  }

  return { handleSubmit };
})();

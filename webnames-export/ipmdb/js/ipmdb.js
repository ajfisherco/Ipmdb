window.IPMdb = (() => {
  const destination = 'ajfisherco' + '@' + 'gmail.com';

  function serializeIdea(form) {
    const source = form.ideaSource.value === 'Other' ? form.sourceOther.value.trim() : form.ideaSource.value;
    return {
      assetId: window.IPMAsset.createAssetId(),
      timestamp: window.IPMAsset.timestamp(),
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

  function mailto(record) {
    const subject = encodeURIComponent('IPMdb Idea: ' + record.title);
    const body = encodeURIComponent([
      'ASSET ID: ' + record.assetId,
      'TIMESTAMP: ' + record.timestamp,
      'IDEA TITLE: ' + record.title,
      'EMAIL: ' + record.email,
      'SOURCE: ' + record.source,
      '',
      'DESCRIPTION:',
      record.description || '(empty)'
    ].join('\n'));
    return 'mailto:' + destination + '?subject=' + subject + '&body=' + body;
  }

  function handleSubmit(form, receipt) {
    const error = validateIdea(form);
    if (error) {
      receipt.textContent = error;
      receipt.classList.remove('hidden');
      return;
    }
    const record = serializeIdea(form);
    receipt.textContent = 'Locked locally as ' + record.assetId + '. Your email client will open next.';
    receipt.classList.remove('hidden');
    window.location.href = mailto(record);
  }

  return { handleSubmit };
})();

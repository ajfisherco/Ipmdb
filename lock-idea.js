const form = document.querySelector('#lockIdeaForm');
const preview = document.querySelector('#issuePreview');
const copyButton = document.querySelector('#copyIssueText');
const openIssueButton = document.querySelector('#openGitHubIssue');
const copyStatus = document.querySelector('#copyStatus');
const sourceSelect = document.querySelector('#sourceSelect');
const sentBySelect = document.querySelector('#sentBySelect');
const customEntry = document.querySelector('#customEntry');
const copyInterac = document.querySelector('#copyInterac');

const interacEmail = 'ajfisherco@gmail.com';

const valueOf = (id) => document.querySelector(`#${id}`)?.value.trim() || '';

const lineOrBlank = (label, value) => `${label}: ${value || ''}`;

function shouldShowCustomEntry() {
  return sourceSelect?.value === 'Other' || sentBySelect?.value === 'Other';
}

function updateCustomEntry() {
  if (!customEntry) return;

  customEntry.hidden = !shouldShowCustomEntry();

  if (customEntry.hidden) {
    customEntry.value = '';
  }
}

function buildIssueMarkdown() {
  const title = valueOf('ideaTitle') || 'Untitled idea';
  const email = valueOf('email');
  const dadEmail = valueOf('dadEmail');
  const source = valueOf('sourceSelect');
  const sentBy = valueOf('sentBySelect');
  const custom = valueOf('customEntry');

  return `# IPMdb.ai — LOCK IDEA

Capture an idea before it disappears.

---

## IDEA
${title}

---

## ORIGINATOR
${lineOrBlank('Email', email)}

---

## ATTRIBUTION
${lineOrBlank('How did you get here', source)}
${lineOrBlank('Who sent you', sentBy)}
${lineOrBlank('Custom entry', custom)}

---

## DOLLAR A DAY
${lineOrBlank('DAD email', dadEmail)}

---

## ASSET LEDGER
Status: Draft
Version: 1.0
Parent: AJF & Co.
Related: IPMdb.ai, DAD

---

## NOTICE
Submission into IPMdb records origin, contribution, and development history as part of a public intellectual property management process.`;
}

function updatePreview() {
  updateCustomEntry();

  if (preview) {
    preview.value = buildIssueMarkdown();
  }
}

async function writeClipboard(text, successMessage, failMessage) {
  try {
    await navigator.clipboard.writeText(text);
    copyStatus.textContent = successMessage;
  } catch (error) {
    copyStatus.textContent = failMessage;
  }

  window.setTimeout(() => {
    copyStatus.textContent = '';
  }, 3500);
}

function copyIssueText() {
  updatePreview();
  writeClipboard(preview.value, 'Idea record copied to clipboard.', 'Copy failed. Select the generated text and copy manually.');
}

function copyInteracEmail() {
  writeClipboard(interacEmail, 'Interac email copied.', 'Copy failed. Use ajfisherco@gmail.com.');
}

function openPrefilledGitHubIssue() {
  updatePreview();

  const title = valueOf('ideaTitle') || 'IPMdb LOCK IDEA';
  const url = new URL('https://github.com/ajfisherco/Ipmdb/issues/new');

  url.searchParams.set('title', title);
  url.searchParams.set('body', preview.value);
  url.searchParams.set('labels', 'Draft,Core System');

  window.open(url.toString(), '_blank', 'noopener,noreferrer');
}

form?.addEventListener('input', updatePreview);
form?.addEventListener('change', updatePreview);
copyButton?.addEventListener('click', copyIssueText);
openIssueButton?.addEventListener('click', openPrefilledGitHubIssue);
copyInterac?.addEventListener('click', copyInteracEmail);

updatePreview();

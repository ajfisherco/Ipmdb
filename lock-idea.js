const form = document.querySelector('#lockIdeaForm');
const preview = document.querySelector('#issuePreview');
const copyButton = document.querySelector('#copyIssueText');
const openIssueButton = document.querySelector('#openGitHubIssue');
const copyStatus = document.querySelector('#copyStatus');

const valueOf = (id) => document.querySelector(`#${id}`)?.value.trim() || '';

const checkedValues = (name) => Array.from(document.querySelectorAll(`input[name="${name}"]:checked`))
  .map((input) => input.value);

const lineOrBlank = (label, value) => `${label}: ${value || ''}`;

const checkboxList = (allValues, selectedValues) => allValues
  .map((value) => `- ${selectedValues.includes(value) ? '[x]' : '[ ]'} ${value}`)
  .join('\n');

const categories = [
  'Housing',
  'Governance',
  'Transportation',
  'PCWM',
  'Economic Development',
  'Public Services',
  'Other'
];

const nextActions = [
  'Discussion',
  'Research',
  'Prototype',
  'Partnership',
  'Funding',
  'Implementation'
];

function buildIssueMarkdown() {
  const selectedCategories = checkedValues('category');
  const selectedNextActions = checkedValues('nextAction');
  const status = valueOf('status') || 'Draft';

  return `# IPM.db — LOCK IDEA

Capture an idea before it disappears.

---

## IDEA TITLE
${valueOf('ideaTitle') || 'One clear sentence.'}

---

## ORIGINATOR
${lineOrBlank('Name', valueOf('originatorName'))}
${lineOrBlank('Handle', valueOf('originatorHandle'))}
${lineOrBlank('Organization', valueOf('originatorOrganization'))}

---

## CATEGORY
Select one or more:

${checkboxList(categories, selectedCategories)}

---

## SUMMARY
Describe the idea in plain language.

What is it?

${valueOf('summaryWhat')}

Why does it matter?

${valueOf('summaryWhy')}

Who benefits?

${valueOf('summaryWho')}

---

## PROBLEM
What problem does this solve?

${valueOf('problem')}

---

## PROPOSED OUTCOME
What should exist if this succeeds?

${valueOf('proposedOutcome')}

---

## NEXT ACTION
What should happen next?

${checkboxList(nextActions, selectedNextActions)}

---

## CONTRIBUTORS
List contributors if applicable.

${valueOf('contributors')}

---

## STATUS

- ${status}

---

## REFERENCES
Links, files, images, notes, sketches, patents, videos, or related material.

${valueOf('references')}

---

## ATTRIBUTION NOTICE

Submission into IPM.db records origin, contribution, and development history as part of a public intellectual property management process.`;
}

function updatePreview() {
  preview.value = buildIssueMarkdown();
}

async function copyIssueText() {
  updatePreview();

  try {
    await navigator.clipboard.writeText(preview.value);
    copyStatus.textContent = 'Issue text copied to clipboard.';
  } catch (error) {
    preview.focus();
    preview.select();
    copyStatus.textContent = 'Copy failed. Select the generated text and copy manually.';
  }

  window.setTimeout(() => {
    copyStatus.textContent = '';
  }, 3500);
}

function openPrefilledGitHubIssue() {
  updatePreview();

  const title = valueOf('ideaTitle') || 'IPM.db LOCK IDEA';
  const url = new URL('https://github.com/ajfisherco/Ipmdb/issues/new');

  url.searchParams.set('title', title);
  url.searchParams.set('body', preview.value);
  url.searchParams.set('labels', 'Draft,Core System');

  window.open(url.toString(), '_blank', 'noopener,noreferrer');
}

form.addEventListener('input', updatePreview);
form.addEventListener('change', updatePreview);
copyButton.addEventListener('click', copyIssueText);
openIssueButton.addEventListener('click', openPrefilledGitHubIssue);

updatePreview();

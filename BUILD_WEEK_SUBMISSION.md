# OpenAI Build Week 2026 submission

## Project name

**IPMdb.ai — The Provenance Graph for Ideas**

## Tagline

**Turn ideas into verifiable, connected assets.**

## Category

**Work & Productivity**

## Short description

IPMdb is a public idea-to-asset ledger. It gives an idea a stable identity, preserves every version, maps dependencies and outcomes, and generates a cryptographic provenance receipt. GPT-5.6 proposes high-value relationships across the ledger while a human remains in control of every write.

## Full project description

Good ideas often disappear into inboxes, documents, and chat history. Even when a team acts on one, the origin, decisions, revisions, and downstream outcomes are easy to lose. IPMdb turns that fragmented trail into a durable asset graph.

An originator submits an idea and receives a stable `IPMDB-####` identifier. The public ledger then makes the asset discoverable while keeping the originator's contact information private. Each edit archives the previous version. Typed edges—such as `depends_on`, `implements`, `documents`, and `supersedes`—show how assets influence one another. A public provenance receipt calculates SHA-256 fingerprints for the asset, its versions, and the surrounding graph context, with a machine-readable JSON representation for independent verification.

The GPT-5.6 AI Map feature compares a selected asset against a bounded candidate set and returns strict, schema-validated relationship proposals with confidence and rationale. Asset text is treated as untrusted data, candidate IDs are constrained, and no relationship is written until an administrator explicitly approves it. This turns the model into a careful analyst rather than an unsupervised database writer.

IPMdb is intentionally portable: reviewers can run the full PHP/MariaDB application with one Docker Compose command and a reproducible sample ledger. The public and private surfaces are separated, credentials come from the environment, mutations require authentication and CSRF validation, and public views do not expose submitter email.

## How we used Codex

Codex served as the engineering partner for the submission. It audited the inherited application, traced a blank graph to a package in which CSS had overwritten the JavaScript bundle, restored the correct client, repaired a truncated PHP route, removed production credentials, closed public PII and raw-error leaks, added security controls, implemented provenance receipts and the GPT-5.6 workflow, created the reproducible environment, and validated the resulting branch.

## How we used GPT-5.6

IPMdb calls `gpt-5.6` through the OpenAI Responses API for relationship analysis. Structured Outputs constrain the response to known asset IDs and supported relationship types. The model supplies a concise rationale and confidence score; a human approves or rejects every proposed edge.

## Built with

- Codex
- OpenAI Responses API
- GPT-5.6
- PHP 8.3
- MariaDB 11.4
- JavaScript and CSS
- Docker Compose
- GitHub Actions

## Links

- Repository: https://github.com/ajfisherco/Ipmdb/tree/build-week-2026
- Local judge URL: http://localhost:8080/ipmdb/
- Public demo: **ADD AFTER DEPLOYMENT**
- Public YouTube demo: **ADD AFTER UPLOAD**
- Codex `/feedback` session ID: **ADD BEFORE SUBMISSION**

## Judge setup

```bash
git clone --branch build-week-2026 https://github.com/ajfisherco/Ipmdb.git
cd Ipmdb
docker compose up --build
```

Open `http://localhost:8080/ipmdb/`.

Local admin: `judge@ipmdb.local` / `IPMdbBuildWeek2026!`

Set `OPENAI_API_KEY` before `docker compose up` to run live GPT-5.6 analysis. The seeded ledger and all non-AI functionality work without an API key.

## Final submission checklist

- [x] Working application and reproducible sample data
- [x] Uses Codex and GPT-5.6
- [x] Category selected: Work & Productivity
- [x] Public repository branch with setup instructions
- [x] Project description prepared
- [x] Under-three-minute narrated demo script prepared
- [ ] Deploy the submission branch to the public demo URL
- [ ] Record and upload the public YouTube demo
- [ ] Run `/feedback` in the Build Week Codex session and paste the session ID
- [ ] Submit on Devpost before **July 21, 2026 at 5:00 PM PDT**

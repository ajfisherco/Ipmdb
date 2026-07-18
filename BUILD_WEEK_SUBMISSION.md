# OpenAI Build Week 2026 submission

## Project name

**IPMdb.ai + DAD — Ideas into Funded Action**

## Elevator pitch

**IPMdb.ai turns ideas into verifiable assets. DAD is its Priority 1 implementation; GPT-5.6 maps seven nodes while Sandola records funding, work, evidence, and outcomes.**

## Tagline

**One graph connecting ideas, evidence, funding, action, and outcomes.**

## Category

**Work & Productivity**

## Short description

IPMdb.ai is a public idea-to-action platform. It gives an idea a stable identity, aligns it through a relationship graph, connects it to implementation pathways, and preserves a verifiable record. DAD—Dollar a Day—is its Priority 1 flagship implementation: $1/day, one goal, end homelessness.

## Full project description

Ideas, evidence, funding, decisions, implementation work, and outcomes usually live in separate systems. That fragmentation makes origins hard to prove, relationships hard to see, and public action hard to audit. IPMdb.ai brings the complete trail into one living asset graph.

An originator locks an idea and receives a stable `IPMDB-####` identity. Every version remains visible. The Relationship Explorer maps dependencies, evidence, decisions, implementation, contributions, and outcomes. The Mill integrates people, resources, nodes, and pathways into the working graph.

DAD—Dollar a Day—is IPMdb.ai's Priority 1 flagship implementation: **$1/day, one goal, end homelessness**. DADS provides stewardship, governance, administration, financial oversight, and public accountability. Sandola is the contribution, evidence, implementation, attribution, and transparent-reserve ledger/archive; it is not presented as a speculative currency.

Seven action nodes organize the ecosystem: Housing, COPO (Court of Public Opinion), Governance, Transportation/TDM, PCWM, Public Service, and Economic Security. Work advances through the Seven Ms: Make, Measure, Map, Model, Memorize, Merge, and Mature.

GPT-5.6 compares a selected asset with a bounded candidate set and proposes strict, schema-validated relationships with confidence and rationale. Asset text is treated as untrusted data, candidate IDs are constrained, and no edge is written until a human explicitly approves it. Public SHA-256 receipts fingerprint the asset, archived versions, and surrounding graph context in both HTML and JSON.

The complete PHP/MariaDB application runs with one Docker Compose command and a reproducible 17-asset ecosystem graph. Public and private surfaces are separated, submitter contact information is redacted from public pages, credentials come from the environment, and administrative writes require authentication and CSRF validation.

## How we used Codex

Codex served as the engineering partner for the Build Week extension. It audited the inherited application; recovered a graph client whose JavaScript had been replaced by CSS; repaired a truncated PHP route; removed production credentials, public PII, and unsafe error exposure; added security controls; integrated DAD, DADS, Sandola, The Mill, COPO, the seven nodes, and the Seven Ms into one public system map and seeded graph; implemented provenance receipts and the GPT-5.6 workflow; and created the reproducible judge environment and automated validation.

## How we used GPT-5.6

IPMdb.ai calls `gpt-5.6` through the OpenAI Responses API for relationship analysis across ideas, nodes, DAD activity, Sandola records, decisions, work, and outcomes. Structured Outputs constrain recommendations to known asset IDs and supported relationship types. The model supplies concise rationale and confidence; a human approves or rejects every proposed edge.

## Build Week extension of an existing project

Before the July 13 submission period, IPMdb.ai had PHP/MariaDB idea intake, ledger, search, version records, manual relationship tooling, a DAD prototype, and separate node, COPO, and Sandola materials. During Build Week, Codex helped recover and harden that inherited application and meaningfully extend it with the GPT-5.6 human-review workflow, public provenance receipts, a restored interactive graph, a unified public ecosystem map, a reproducible 17-asset graph connecting the full system, Docker setup, automated checks, and deployment/submission documentation. The dated work is isolated on the `build-week-2026` branch and documented in pull request #90.

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
- Pull request: https://github.com/ajfisherco/Ipmdb/pull/90
- Local judge URL: http://localhost:8080/ipmdb/
- Local system map: http://localhost:8080/ipmdb/ecosystem.php
- Local DAD page: http://localhost:8080/ipmdb/dad/
- Public demo: **ADD AFTER DEPLOYMENT**
- Public YouTube demo: **ADD AFTER UPLOAD**
- Codex `/feedback` session ID: **ADD BEFORE SUBMISSION**
- Devpost thumbnail: `graphics/ipmdb-build-week-thumbnail-v2.png` (1536×1024, official IPMdb.ai, DAD, and Sandola marks)

## Judge setup

```bash
git clone --branch build-week-2026 https://github.com/ajfisherco/Ipmdb.git
cd Ipmdb
docker compose up --build
```

Open `http://localhost:8080/ipmdb/`.

Local admin: `judge@ipmdb.local` / `IPMdbBuildWeek2026!`

Set `OPENAI_API_KEY` before `docker compose up` to run live GPT-5.6 analysis. The ecosystem graph and all non-AI functionality work without an API key.

## Final submission checklist

- [x] Working application and reproducible ecosystem graph
- [x] IPMdb.ai, DAD, DADS, Sandola, The Mill, COPO, seven nodes, and Seven Ms represented
- [x] Uses Codex and GPT-5.6
- [x] Category selected: Work & Productivity
- [x] Public repository branch with setup instructions and CC0 license
- [x] Project description prepared
- [x] Prior work and Build Week additions distinguished
- [x] Under-three-minute narrated demo script prepared
- [x] Official locked logos recovered for the submission thumbnail
- [ ] Deploy the submission branch to the public demo URL
- [ ] Record and upload the public YouTube demo
- [ ] Run `/feedback` in the core Build Week Codex session and paste the session ID
- [ ] Submit on Devpost before **July 21, 2026 at 5:00 PM PDT**

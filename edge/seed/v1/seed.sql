-- E.D.G.E. article-lock seed v1
-- Additive and idempotent: no CREATE, ALTER, UPDATE, DELETE, or DROP statements.
-- Rich normalized records remain in the two JSON files beside this seed.

START TRANSACTION;

INSERT INTO ideas (asset_id, title, originator_email, source, description, status)
SELECT 'EDGE-20260905-001', 'U.S. strikes three Iranian oil tankers', 'edge@ipmdb.ai', 'Reuters', 'CENTCOM says U.S. forces struck three Iranian crude carriers after Iran fired missiles at two U.S. Navy ships. Reuters reported the claim, and AP separately reported the strikes. Metadata and original IPMdb summary only.', 'locked'
WHERE NOT EXISTS (SELECT 1 FROM ideas WHERE asset_id = 'EDGE-20260905-001');
INSERT INTO ideas (asset_id, title, originator_email, source, description, status)
SELECT 'EDGE-20260905-002', 'Kyiv gets a 72-hour strike pause during U.S. envoy visits', 'edge@ipmdb.ai', 'Associated Press', 'The Kremlin announced a 72-hour pause in strikes on Kyiv. Ukraine also said it would pause strikes during the U.S. visit. A short pause is not a ceasefire.', 'locked'
WHERE NOT EXISTS (SELECT 1 FROM ideas WHERE asset_id = 'EDGE-20260905-002');
INSERT INTO ideas (asset_id, title, originator_email, source, description, status)
SELECT 'EDGE-20260905-003', 'Canada loses 42,000 jobs before new counter-tariffs', 'edge@ipmdb.ai', 'Statistics Canada; Finance Canada', 'Statistics Canada says employment fell by 42,000 in August. Finance Canada says new counter-tariffs on 27.6 billion dollars of U.S. goods begin September 8.', 'locked'
WHERE NOT EXISTS (SELECT 1 FROM ideas WHERE asset_id = 'EDGE-20260905-003');
INSERT INTO ideas (asset_id, title, originator_email, source, description, status)
SELECT 'EDGE-20260905-004', 'Victoria transit overtime resumes as mediation starts', 'edge@ipmdb.ai', 'Times Colonist', 'Victoria transit drivers and mechanics are taking overtime shifts again while mediation begins. Service pressure is lower, but the contract dispute is not settled.', 'locked'
WHERE NOT EXISTS (SELECT 1 FROM ideas WHERE asset_id = 'EDGE-20260905-004');
INSERT INTO ideas (asset_id, title, originator_email, source, description, status)
SELECT 'EDGE-20260905-005', 'OpenAI admits a wiki incident and calls for more disclosure', 'edge@ipmdb.ai', 'Reuters', 'OpenAI acknowledged that AI agents used wiki sites as unofficial message boards and said disclosure rules need to improve. Company findings and outside reporting remain separate.', 'locked'
WHERE NOT EXISTS (SELECT 1 FROM ideas WHERE asset_id = 'EDGE-20260905-005');

INSERT INTO assets (asset_id, idea_id, version, status)
SELECT i.asset_id, i.id, '1.0', 'locked' FROM ideas i
WHERE i.asset_id LIKE 'EDGE-20260905-00_'
  AND NOT EXISTS (SELECT 1 FROM assets a WHERE a.asset_id = i.asset_id);

INSERT INTO ledger (asset_id, event_type, event_payload)
SELECT 'EDGE-20260905-001', 'article.locked', '{"edition":"EDGE-2026-09-05","url":"https://www.reuters.com/world/middle-east/us-military-strikes-three-iranian-crude-oil-carriers-central-command-says-2026-09-05/","publisher":"source:reuters","published_at":"2026-09-05T13:55:00Z","categories":["GLOBAL","DJT","WHITE_HOUSE","MIL","ENERGY"],"evidence_status":"OFFICIAL_CLAIM_CORROBORATED","first_claimant":"authority:centcom","corroboration":"https://apnews.com/article/b3650799901c56a6deeca962cbaa4109","rights":"metadata-and-original-summary-only"}'
WHERE NOT EXISTS (SELECT 1 FROM ledger WHERE asset_id='EDGE-20260905-001' AND event_type='article.locked');
INSERT INTO ledger (asset_id, event_type, event_payload)
SELECT 'EDGE-20260905-002', 'article.locked', '{"edition":"EDGE-2026-09-05","url":"https://apnews.com/article/russia-ukraine-war-kushner-witkoff-visit-36a991feaff241bb8f24857ac159202a","publisher":"source:associated-press","published_at":"2026-09-05","categories":["GLOBAL","WHITE_HOUSE","MIL","GOV","DIPLOMACY"],"evidence_status":"MULTI_PARTY_REPORTED","claimants":["authority:kremlin","authority:president-ukraine"],"deadline":"2026-09-08","rights":"metadata-and-original-summary-only"}'
WHERE NOT EXISTS (SELECT 1 FROM ledger WHERE asset_id='EDGE-20260905-002' AND event_type='article.locked');
INSERT INTO ledger (asset_id, event_type, event_payload)
SELECT 'EDGE-20260905-003', 'article.locked', '{"edition":"EDGE-2026-09-05","url":"https://www150.statcan.gc.ca/n1/daily-quotidien/260904/dq260904a-eng.htm","publisher":"source:statistics-canada","published_at":"2026-09-04","categories":["CANADA","ECONOMY","LABOUR","TRADE"],"evidence_status":"PRIMARY_CONFIRMED","policy_authority":"source:finance-canada","effective_at":"2026-09-08T00:01:00-04:00","rights":"metadata-and-original-summary-only"}'
WHERE NOT EXISTS (SELECT 1 FROM ledger WHERE asset_id='EDGE-20260905-003' AND event_type='article.locked');
INSERT INTO ledger (asset_id, event_type, event_payload)
SELECT 'EDGE-20260905-004', 'article.locked', '{"edition":"EDGE-2026-09-05","url":"https://www.timescolonist.com/local-news/overtime-ban-lifted-for-victoria-transit-workers-as-mediation-begins-12747451","publisher":"source:times-colonist","published_at":"2026-09-04","categories":["VICTORIA","BRITISH_COLUMBIA","TRANSPORTATION","LABOUR"],"evidence_status":"REPORTED_UNRESOLVED","parties":["authority:bc-transit","authority:unifor-local-333"],"rights":"metadata-and-original-summary-only"}'
WHERE NOT EXISTS (SELECT 1 FROM ledger WHERE asset_id='EDGE-20260905-004' AND event_type='article.locked');
INSERT INTO ledger (asset_id, event_type, event_payload)
SELECT 'EDGE-20260905-005', 'article.locked', '{"edition":"EDGE-2026-09-05","url":"https://www.reuters.com/business/media-telecom/openai-acknowledges-wiki-incident-need-more-transparency-around-unintended-ai-2026-09-05/","publisher":"source:reuters","published_at":"2026-09-05T14:55:00Z","categories":["AI","TECH","SECURITY","ACCOUNTABILITY","IPMDB_AI"],"evidence_status":"SELF_ACKNOWLEDGED_REVIEW_PENDING","subject":"authority:openai","rights":"metadata-and-original-summary-only"}'
WHERE NOT EXISTS (SELECT 1 FROM ledger WHERE asset_id='EDGE-20260905-005' AND event_type='article.locked');

INSERT INTO ledger (asset_id, event_type, event_payload)
SELECT i.asset_id, 'source.registry-linked', '{"registry":"edge/seed/v1/source-authorities.json","version":"1.0"}'
FROM ideas i
WHERE i.asset_id LIKE 'EDGE-20260905-00_'
  AND NOT EXISTS (SELECT 1 FROM ledger l WHERE l.asset_id=i.asset_id AND l.event_type='source.registry-linked');

COMMIT;

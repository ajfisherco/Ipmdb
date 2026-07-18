USE ipmdb;

INSERT INTO ipmdb_assets
  (asset_id, email, title, category, idea, status, version, created_at, updated_at)
VALUES
  ('IPMDB-0001', 'demo@ipmdb.local', 'IPMdb.ai — Ideas 2 Assets', 'Technology', 'The public intellectual property management platform: Ideas → Align → Assets. It assigns stable identities, preserves versions, maps relationships, supports implementation, and publishes verifiable provenance.', 'Active', '1.2', '2026-07-13 09:00:00', '2026-07-18 18:00:00'),
  ('IPMDB-0002', 'demo@ipmdb.local', 'Relationship Explorer', 'Software', 'The graph operating system of IPMdb.ai, connecting assets, evidence, dependencies, decisions, implementation, funding, and outcomes.', 'Active', '1.1', '2026-07-13 10:00:00', '2026-07-18 17:30:00'),
  ('IPMDB-0003', 'demo@ipmdb.local', 'The Mill Integration Layer', 'Software', 'The Mill brings ideas, people, evidence, nodes, resources, and implementation pathways into one connected working flow.', 'Active', '1.0', '2026-07-14 08:00:00', '2026-07-18 17:00:00'),
  ('IPMDB-0004', 'demo@ipmdb.local', 'DAD — Dollar a Day', 'DAD', 'IPMdb.ai Priority 1 implementation: $1/day, one goal, end homelessness. DAD connects contributions, decisions, work, and measurable outcomes to the public asset graph.', 'Pilot', '1.1', '2026-07-14 09:00:00', '2026-07-18 16:50:00'),
  ('IPMDB-0005', 'demo@ipmdb.local', 'DADS Stewardship', 'Governance', 'Dollar A Day Society stewards ideas into measurable public benefit through governance, administration, financial oversight, and public accountability.', 'Active', '1.0', '2026-07-14 09:30:00', '2026-07-18 16:40:00'),
  ('IPMDB-0006', 'demo@ipmdb.local', 'Housing Node', 'Housing', 'Develop housing ideas, systems, implementation pathways, placement, stability, dignity, and measurable community outcomes.', 'Active', '1.0', '2026-07-14 10:00:00', '2026-07-18 16:30:00'),
  ('IPMDB-0007', 'demo@ipmdb.local', 'COPO — Court of Public Opinion', 'COPO', 'A civic accountability node for organizing questions, claims, sources, evidence, replies, corrections, and outcomes without making claims stronger than the evidence.', 'Active', '1.0', '2026-07-14 10:30:00', '2026-07-18 16:20:00'),
  ('IPMDB-0008', 'demo@ipmdb.local', 'Governance Node', 'Governance', 'Develop transparent, consent-based governance systems that preserve attribution, accountability, participation, and continuity.', 'Active', '1.0', '2026-07-14 11:00:00', '2026-07-18 16:10:00'),
  ('IPMDB-0009', 'demo@ipmdb.local', 'Transportation / TDM Node', 'Transportation', 'Develop mobility, access, coordination, and traffic-demand-management systems connected to broader civic outcomes.', 'Active', '1.0', '2026-07-14 11:30:00', '2026-07-18 16:00:00'),
  ('IPMDB-0010', 'demo@ipmdb.local', 'PCWM Node', 'PCWM', 'Post-consumer waste management and circular resource systems connecting materials, recovery, reuse, responsibility, and public benefit.', 'Active', '1.0', '2026-07-14 12:00:00', '2026-07-18 15:50:00'),
  ('IPMDB-0011', 'demo@ipmdb.local', 'Public Service Node', 'Public Service', 'Develop clear, accessible, accountable public systems with measurable delivery and outcomes.', 'Active', '1.0', '2026-07-14 12:30:00', '2026-07-18 15:40:00'),
  ('IPMDB-0012', 'demo@ipmdb.local', 'Economic Security Node', 'Economic Security', 'Develop cooperative value creation, participation, implementation pathways, and durable community economic benefit.', 'Active', '1.0', '2026-07-14 13:00:00', '2026-07-18 15:30:00'),
  ('IPMDB-0013', 'demo@ipmdb.local', 'Sandola Ledger and Archive', 'Sandola', 'A ledger concept—not a speculative currency—that records contribution, evidence, implementation, attribution, and transparent reserves across the ecosystem.', 'Active', '1.0', '2026-07-15 09:00:00', '2026-07-18 15:20:00'),
  ('IPMDB-0014', 'demo@ipmdb.local', 'Seven Ms Operating Loop', 'Workflow', 'Make, Measure, Map, Model, Memorize, Merge, and Mature: the repeatable operating loop for developing assets while preserving what was learned.', 'Active', '1.0', '2026-07-15 10:00:00', '2026-07-18 15:10:00'),
  ('IPMDB-0015', 'demo@ipmdb.local', 'GPT-5.6 Relationship Analyst', 'AI', 'A human-in-the-loop analyst that compares bounded asset candidates, proposes typed relationships with confidence and rationale, and waits for approval before writing an edge.', 'Active', '1.0', '2026-07-16 14:00:00', '2026-07-18 15:00:00'),
  ('IPMDB-0016', 'demo@ipmdb.local', 'Public Provenance Receipts', 'Governance', 'Public SHA-256 receipts fingerprint asset content, archived versions, and graph context for independent verification.', 'Active', '1.0', '2026-07-17 10:00:00', '2026-07-18 14:50:00'),
  ('IPMDB-0017', 'demo@ipmdb.local', 'Portable Judge Environment', 'Software', 'A one-command Docker environment with a reproducible schema and ecosystem sample graph, allowing reviewers to run IPMdb without production credentials or data.', 'Active', '1.0', '2026-07-18 08:00:00', '2026-07-18 14:40:00')
ON DUPLICATE KEY UPDATE
  title = VALUES(title), category = VALUES(category), idea = VALUES(idea),
  status = VALUES(status), version = VALUES(version), updated_at = VALUES(updated_at);

INSERT INTO ipmdb_asset_versions
  (asset_id, version_number, email, title, category, idea, saved_at)
VALUES
  ('IPMDB-0001', 1, 'demo@ipmdb.local', 'IPMdb Idea Ledger', 'Technology', 'A ledger for recording ideas with stable IDs and public history.', '2026-07-13 09:00:00'),
  ('IPMDB-0001', 2, 'demo@ipmdb.local', 'IPMdb.ai — Ideas 2 Assets', 'Technology', 'A connected platform for ideas, assets, relationships, implementation, and public provenance.', '2026-07-16 09:00:00'),
  ('IPMDB-0004', 1, 'demo@ipmdb.local', 'DAD — Dollar a Day', 'DAD', '$1/day. One goal. End homelessness.', '2026-07-14 09:00:00'),
  ('IPMDB-0013', 1, 'demo@ipmdb.local', 'Sandola Ledger and Archive', 'Sandola', 'Record contribution, evidence, implementation, and attribution without presenting Sandola as a speculative currency.', '2026-07-15 09:00:00')
ON DUPLICATE KEY UPDATE
  title = VALUES(title), category = VALUES(category), idea = VALUES(idea), saved_at = VALUES(saved_at);

INSERT INTO ipmdb_relationships
  (source_asset_id, target_asset_id, relationship_type, note, created_at)
VALUES
  ('IPMDB-0002', 'IPMDB-0001', 'implements', 'The Relationship Explorer makes the IPMdb.ai asset domain navigable as a living graph.', '2026-07-14 08:00:00'),
  ('IPMDB-0003', 'IPMDB-0002', 'enhances', 'The Mill integrates the graph with people, evidence, nodes, resources, and implementation pathways.', '2026-07-14 08:30:00'),
  ('IPMDB-0004', 'IPMDB-0001', 'part_of', 'DAD is the Priority 1 flagship implementation within the IPMdb.ai ecosystem.', '2026-07-14 09:00:00'),
  ('IPMDB-0005', 'IPMDB-0004', 'documents', 'DADS provides governance, administration, oversight, and accountability for DAD.', '2026-07-14 09:30:00'),
  ('IPMDB-0006', 'IPMDB-0001', 'part_of', 'Housing is one of the seven action nodes.', '2026-07-14 10:00:00'),
  ('IPMDB-0007', 'IPMDB-0001', 'part_of', 'COPO is one of the seven action nodes.', '2026-07-14 10:30:00'),
  ('IPMDB-0008', 'IPMDB-0001', 'part_of', 'Governance is one of the seven action nodes.', '2026-07-14 11:00:00'),
  ('IPMDB-0009', 'IPMDB-0001', 'part_of', 'Transportation and TDM form one of the seven action nodes.', '2026-07-14 11:30:00'),
  ('IPMDB-0010', 'IPMDB-0001', 'part_of', 'PCWM is one of the seven action nodes.', '2026-07-14 12:00:00'),
  ('IPMDB-0011', 'IPMDB-0001', 'part_of', 'Public Service is one of the seven action nodes.', '2026-07-14 12:30:00'),
  ('IPMDB-0012', 'IPMDB-0001', 'part_of', 'Economic Security is one of the seven action nodes.', '2026-07-14 13:00:00'),
  ('IPMDB-0004', 'IPMDB-0006', 'implements', 'DAD begins with a transparent contribution pathway connected to housing outcomes.', '2026-07-15 08:00:00'),
  ('IPMDB-0004', 'IPMDB-0011', 'enhances', 'DAD connects community funding to accountable public-service delivery.', '2026-07-15 08:10:00'),
  ('IPMDB-0004', 'IPMDB-0012', 'enhances', 'DAD creates a community participation pathway connected to economic security.', '2026-07-15 08:20:00'),
  ('IPMDB-0007', 'IPMDB-0008', 'documents', 'COPO preserves evidence, replies, corrections, and outcomes for public accountability.', '2026-07-15 08:30:00'),
  ('IPMDB-0013', 'IPMDB-0004', 'documents', 'Sandola records DAD contribution, evidence, implementation, attribution, and reserves.', '2026-07-15 09:00:00'),
  ('IPMDB-0013', 'IPMDB-0001', 'part_of', 'Sandola is a ledger and archive concept within IPMdb.ai.', '2026-07-15 09:10:00'),
  ('IPMDB-0014', 'IPMDB-0003', 'implements', 'The Seven Ms provide the repeatable operating loop used by The Mill.', '2026-07-15 10:00:00'),
  ('IPMDB-0015', 'IPMDB-0002', 'enhances', 'GPT-5.6 proposes useful graph relationships while a human remains the final decision-maker.', '2026-07-16 15:00:00'),
  ('IPMDB-0016', 'IPMDB-0001', 'documents', 'Provenance receipts preserve the verifiable content and graph history of the ecosystem.', '2026-07-17 10:00:00'),
  ('IPMDB-0017', 'IPMDB-0001', 'implements', 'The portable environment makes the complete application and ecosystem graph reproducible for reviewers.', '2026-07-18 14:40:00')
ON DUPLICATE KEY UPDATE note = VALUES(note), created_at = VALUES(created_at);

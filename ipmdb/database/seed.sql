USE ipmdb;

INSERT INTO ipmdb_assets
  (asset_id, email, title, category, idea, status, version, created_at, updated_at)
VALUES
  (
    'IPMDB-0001',
    'demo@ipmdb.local',
    'IPMdb Provenance Ledger',
    'Technology',
    'A public, versioned ledger that turns raw ideas into durable assets with stable identifiers, attributable history, typed relationships, and cryptographic provenance receipts.',
    'Active',
    '1.2',
    '2026-07-13 09:00:00',
    '2026-07-18 18:00:00'
  ),
  (
    'IPMDB-0002',
    'demo@ipmdb.local',
    'Asset Domain Map',
    'Software',
    'An interactive relationship graph for exploring dependencies, implementation links, documentation, supersession, and other meaningful connections between assets.',
    'Active',
    '1.1',
    '2026-07-13 10:00:00',
    '2026-07-18 17:30:00'
  ),
  (
    'IPMDB-0003',
    'demo@ipmdb.local',
    'GPT-5.6 Relationship Analyst',
    'AI',
    'A human-in-the-loop assistant that compares an asset with the ledger, proposes typed relationships with confidence and rationale, and waits for an administrator to approve every edge.',
    'Active',
    '1.0',
    '2026-07-16 14:00:00',
    '2026-07-18 17:00:00'
  ),
  (
    'IPMDB-0004',
    'demo@ipmdb.local',
    'DAD Community Outcomes',
    'Housing',
    'A transparent community contribution and outcomes record for Dollar a Day initiatives, linking proposals, decisions, implementation work, and measurable public results.',
    'Pilot',
    '1.0',
    '2026-07-14 11:00:00',
    '2026-07-17 12:00:00'
  ),
  (
    'IPMDB-0005',
    'demo@ipmdb.local',
    'Decision Audit Trail',
    'Governance',
    'A reviewable record of decisions, contributors, evidence, and changes so that project history stays visible without exposing private contact information on public pages.',
    'Draft',
    '1.0',
    '2026-07-15 08:30:00',
    '2026-07-17 08:30:00'
  ),
  (
    'IPMDB-0006',
    'demo@ipmdb.local',
    'Portable Judge Environment',
    'Software',
    'A one-command Docker environment with a reproducible schema and sample ledger, allowing reviewers to run IPMdb without production credentials or production data.',
    'Active',
    '1.0',
    '2026-07-18 08:00:00',
    '2026-07-18 16:00:00'
  )
ON DUPLICATE KEY UPDATE
  title = VALUES(title),
  category = VALUES(category),
  idea = VALUES(idea),
  status = VALUES(status),
  version = VALUES(version),
  updated_at = VALUES(updated_at);

INSERT INTO ipmdb_asset_versions
  (asset_id, version_number, email, title, category, idea, saved_at)
VALUES
  ('IPMDB-0001', 1, 'demo@ipmdb.local', 'IPMdb Idea Ledger', 'Technology', 'A ledger for recording ideas with stable IDs and public history.', '2026-07-13 09:00:00'),
  ('IPMDB-0001', 2, 'demo@ipmdb.local', 'IPMdb Provenance Ledger', 'Technology', 'A versioned ledger for ideas, assets, relationships, and public provenance.', '2026-07-16 09:00:00'),
  ('IPMDB-0002', 1, 'demo@ipmdb.local', 'Asset Domain Map', 'Software', 'A visual map of meaningful relationships between ledger assets.', '2026-07-13 10:00:00')
ON DUPLICATE KEY UPDATE
  title = VALUES(title),
  category = VALUES(category),
  idea = VALUES(idea),
  saved_at = VALUES(saved_at);

INSERT INTO ipmdb_relationships
  (source_asset_id, target_asset_id, relationship_type, note, created_at)
VALUES
  ('IPMDB-0002', 'IPMDB-0001', 'implements', 'The graph makes the provenance ledger navigable as a connected asset domain.', '2026-07-14 09:00:00'),
  ('IPMDB-0003', 'IPMDB-0002', 'enhances', 'GPT-5.6 proposes useful graph edges while a human remains the final decision-maker.', '2026-07-16 15:00:00'),
  ('IPMDB-0003', 'IPMDB-0001', 'depends_on', 'The analyst uses existing ledger records as bounded evidence for every recommendation.', '2026-07-16 15:10:00'),
  ('IPMDB-0004', 'IPMDB-0001', 'part_of', 'DAD outcomes are represented as traceable assets in the wider ledger.', '2026-07-17 10:00:00'),
  ('IPMDB-0005', 'IPMDB-0001', 'documents', 'The audit trail preserves the decisions and changes behind ledger assets.', '2026-07-17 11:00:00'),
  ('IPMDB-0006', 'IPMDB-0001', 'implements', 'The portable environment makes the application and data model reproducible for reviewers.', '2026-07-18 16:00:00')
ON DUPLICATE KEY UPDATE
  note = VALUES(note),
  created_at = VALUES(created_at);

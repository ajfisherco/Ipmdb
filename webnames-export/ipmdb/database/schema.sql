-- IPM.db Database Schema
-- Asset Ledger and Tracking System

-- Ideas table
CREATE TABLE IF NOT EXISTS ideas (
  id VARCHAR(64) PRIMARY KEY,
  title VARCHAR(255) NOT NULL,
  originator_email VARCHAR(255),
  originator_name VARCHAR(255),
  description TEXT,
  status VARCHAR(50) DEFAULT 'Draft',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  modified_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  parent_id VARCHAR(64),
  INDEX idx_status (status),
  INDEX idx_created (created_at),
  FOREIGN KEY (parent_id) REFERENCES ideas(id)
);

-- Assets table
CREATE TABLE IF NOT EXISTS assets (
  id VARCHAR(64) PRIMARY KEY,
  title VARCHAR(255) NOT NULL,
  description TEXT,
  status VARCHAR(50) DEFAULT 'Draft',
  quality_tier VARCHAR(20) DEFAULT 'Alpha',
  version VARCHAR(20) DEFAULT '1.0.0',
  idea_id VARCHAR(64),
  github_issue_number INT,
  public_record BOOLEAN DEFAULT TRUE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  modified_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_status (status),
  INDEX idx_quality (quality_tier),
  INDEX idx_created (created_at),
  FOREIGN KEY (idea_id) REFERENCES ideas(id)
);

-- Contributors table
CREATE TABLE IF NOT EXISTS contributors (
  id INT AUTO_INCREMENT PRIMARY KEY,
  asset_id VARCHAR(64) NOT NULL,
  contributor_email VARCHAR(255),
  contributor_name VARCHAR(255),
  contribution_type VARCHAR(50),
  attributed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_asset (asset_id),
  INDEX idx_email (contributor_email),
  FOREIGN KEY (asset_id) REFERENCES assets(id)
);

-- Contributions (DAD) table
CREATE TABLE IF NOT EXISTS contributions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  contributor_email VARCHAR(255),
  amount DECIMAL(10, 2) NOT NULL,
  currency VARCHAR(3) DEFAULT 'CAD',
  payment_method VARCHAR(50),
  status VARCHAR(50) DEFAULT 'initiated',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_status (status),
  INDEX idx_created (created_at),
  INDEX idx_email (contributor_email)
);

-- Attribution records
CREATE TABLE IF NOT EXISTS attribution (
  id INT AUTO_INCREMENT PRIMARY KEY,
  asset_id VARCHAR(64) NOT NULL,
  source VARCHAR(255),
  source_link VARCHAR(512),
  recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_asset (asset_id),
  FOREIGN KEY (asset_id) REFERENCES assets(id)
);

-- Change log
CREATE TABLE IF NOT EXISTS change_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  asset_id VARCHAR(64) NOT NULL,
  change_type VARCHAR(50),
  previous_value TEXT,
  new_value TEXT,
  actor VARCHAR(255),
  changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_asset (asset_id),
  INDEX idx_changed (changed_at),
  FOREIGN KEY (asset_id) REFERENCES assets(id)
);

-- Public ledger view
CREATE VIEW IF NOT EXISTS public_ledger AS
SELECT 
  a.id,
  a.title,
  a.status,
  a.quality_tier AS quality,
  a.version,
  i.originator_name,
  i.originator_email,
  a.created_at,
  a.modified_at,
  COUNT(c.id) AS contributor_count,
  a.github_issue_number
FROM assets a
LEFT JOIN ideas i ON a.idea_id = i.id
LEFT JOIN contributors c ON a.id = c.asset_id
WHERE a.public_record = TRUE
GROUP BY a.id
ORDER BY a.modified_at DESC;

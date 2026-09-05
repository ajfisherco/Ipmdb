-- Automatic language publication for IPMdb RC1.
-- Additive only. Existing ideas, assets, versions, and ledger events are untouched.

CREATE TABLE IF NOT EXISTS doer_language_preferences (
  doer_email VARCHAR(255) PRIMARY KEY,
  language_tag VARCHAR(35) NOT NULL DEFAULT 'en',
  fallback_language_tag VARCHAR(35) NOT NULL DEFAULT 'en',
  auto_publish TINYINT(1) NOT NULL DEFAULT 1,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS asset_publications (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  asset_id VARCHAR(32) NOT NULL,
  version VARCHAR(20) NOT NULL,
  source_publication_id BIGINT UNSIGNED NULL,
  source_language_tag VARCHAR(35) NOT NULL,
  language_tag VARCHAR(35) NOT NULL,
  title VARCHAR(255) NOT NULL,
  body MEDIUMTEXT NOT NULL,
  translation_method ENUM('original', 'human', 'ai', 'hybrid') NOT NULL,
  translation_provider VARCHAR(120) NULL,
  translation_model VARCHAR(120) NULL,
  confidence DECIMAL(5,4) NULL,
  review_status ENUM('not_required', 'pending', 'reviewed', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
  publication_status ENUM('draft', 'ready', 'published', 'withdrawn', 'superseded') NOT NULL DEFAULT 'draft',
  created_by VARCHAR(255) NOT NULL,
  reviewed_by VARCHAR(255) NULL,
  published_at TIMESTAMP NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_asset_publication_language (asset_id, version, language_tag),
  KEY idx_publication_lookup (asset_id, publication_status, language_tag),
  CONSTRAINT fk_publication_source
    FOREIGN KEY (source_publication_id) REFERENCES asset_publications(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

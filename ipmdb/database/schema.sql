CREATE DATABASE IF NOT EXISTS ipmdb
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE ipmdb;

CREATE TABLE IF NOT EXISTS ipmdb_assets (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  asset_id VARCHAR(80) NOT NULL,
  email VARCHAR(255) NOT NULL,
  title VARCHAR(255) NOT NULL,
  category VARCHAR(120) NOT NULL DEFAULT 'Uncategorized',
  idea MEDIUMTEXT NOT NULL,
  status VARCHAR(40) NOT NULL DEFAULT 'Draft',
  version VARCHAR(40) NOT NULL DEFAULT '1.0',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ipmdb_assets_asset_id (asset_id),
  KEY idx_ipmdb_assets_status (status),
  KEY idx_ipmdb_assets_category (category),
  FULLTEXT KEY ft_ipmdb_assets_content (title, idea)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipmdb_asset_versions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  asset_id VARCHAR(80) NOT NULL,
  version_number INT UNSIGNED NOT NULL,
  email VARCHAR(255) NULL,
  title VARCHAR(255) NULL,
  category VARCHAR(120) NULL,
  idea MEDIUMTEXT NULL,
  saved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ipmdb_asset_version (asset_id, version_number),
  KEY idx_ipmdb_versions_asset (asset_id),
  CONSTRAINT fk_ipmdb_versions_asset
    FOREIGN KEY (asset_id) REFERENCES ipmdb_assets (asset_id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ipmdb_relationships (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  source_asset_id VARCHAR(80) NOT NULL,
  target_asset_id VARCHAR(80) NOT NULL,
  relationship_type VARCHAR(64) NOT NULL DEFAULT 'relates_to',
  note TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ipmdb_relationship (source_asset_id, target_asset_id, relationship_type),
  KEY idx_ipmdb_relationship_source (source_asset_id),
  KEY idx_ipmdb_relationship_target (target_asset_id),
  KEY idx_ipmdb_relationship_type (relationship_type),
  CONSTRAINT fk_ipmdb_relationship_source
    FOREIGN KEY (source_asset_id) REFERENCES ipmdb_assets (asset_id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_ipmdb_relationship_target
    FOREIGN KEY (target_asset_id) REFERENCES ipmdb_assets (asset_id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT chk_ipmdb_relationship_not_self CHECK (source_asset_id <> target_asset_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migration: document versioning table for OSCAL artefact change tracking (F3/DiffService)
CREATE TABLE IF NOT EXISTS document_versions (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id       INT UNSIGNED NOT NULL,
    domain_id       INT UNSIGNED NOT NULL,
    entity_type     VARCHAR(50)  NOT NULL COMMENT 'ssp|profile|ap|ar|poam',
    entity_id       INT UNSIGNED NOT NULL,
    version_number  SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    document_json   LONGTEXT     NOT NULL,
    changes_json    TEXT         DEFAULT NULL,
    created_by      INT UNSIGNED NOT NULL,
    created_at      DATETIME     NOT NULL,
    INDEX idx_dv_entity     (tenant_id, entity_type, entity_id),
    INDEX idx_dv_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

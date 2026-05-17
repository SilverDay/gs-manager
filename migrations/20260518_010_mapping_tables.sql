-- Migration: mapping tables for BSI Grundschutz++ mappings
-- mapping_baustein_zo: maps building blocks (Bausteine) to protection objects (Schutzobjekte)
-- mapping_controls_anf: maps controls to requirements (Anforderungen) in other frameworks
CREATE TABLE IF NOT EXISTS mapping_baustein_zo (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id   INT UNSIGNED NOT NULL,
    catalog_id  INT UNSIGNED NOT NULL,
    baustein_id VARCHAR(50)  NOT NULL COMMENT 'Control-ID of the building block',
    zo_type     VARCHAR(100) NOT NULL COMMENT 'Protection-object type (Schutzobjekttyp)',
    zo_name     VARCHAR(255) NOT NULL COMMENT 'Protection-object name',
    notes       TEXT         DEFAULT NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_mzo_tenant_catalog (tenant_id, catalog_id),
    INDEX idx_mzo_baustein (baustein_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mapping_controls_anf (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id       INT UNSIGNED NOT NULL,
    catalog_id      INT UNSIGNED NOT NULL,
    control_id_str  VARCHAR(50)  NOT NULL COMMENT 'Source control in the GS++ catalog',
    target_framework VARCHAR(100) NOT NULL COMMENT 'Target framework name (e.g. ISO27001, NIS2)',
    target_control   VARCHAR(100) NOT NULL COMMENT 'Control/clause reference in the target framework',
    mapping_type    ENUM('full','partial','none') NOT NULL DEFAULT 'partial',
    notes           TEXT         DEFAULT NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_mca_tenant_catalog (tenant_id, catalog_id),
    INDEX idx_mca_control (control_id_str),
    INDEX idx_mca_target (target_framework, target_control)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

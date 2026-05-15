-- UP
CREATE TABLE IF NOT EXISTS catalogs (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id       INT UNSIGNED NOT NULL,
    name            VARCHAR(255) NOT NULL,
    source_url      VARCHAR(512),
    oscal_json      JSON NOT NULL,
    version_hash    VARCHAR(64),
    imported_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_catalogs_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS information_domains (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id       INT UNSIGNED NOT NULL,
    name            VARCHAR(255) NOT NULL,
    description     TEXT,
    isms_type       ENUM('standard','enhanced') DEFAULT 'standard',
    metadata_json   JSON,
    status          ENUM('draft','active','archived') DEFAULT 'draft',
    created_by      INT UNSIGNED,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_domains_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_domains_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- DOWN
DROP TABLE IF EXISTS information_domains;
DROP TABLE IF EXISTS catalogs;

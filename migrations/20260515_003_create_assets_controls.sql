-- UP
CREATE TABLE IF NOT EXISTS business_processes (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    domain_id       INT UNSIGNED NOT NULL,
    name            VARCHAR(255) NOT NULL,
    description     TEXT,
    criticality     ENUM('low','medium','high','very_high') DEFAULT 'medium',
    owner_user_id   INT UNSIGNED,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_processes_domain FOREIGN KEY (domain_id) REFERENCES information_domains(id) ON DELETE CASCADE,
    CONSTRAINT fk_processes_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS assets (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    domain_id       INT UNSIGNED NOT NULL,
    name            VARCHAR(255) NOT NULL,
    category_uuid   CHAR(36),
    category_name   VARCHAR(255),
    asset_type      VARCHAR(100),
    description     TEXT,
    protection_need_c ENUM('normal','high') DEFAULT 'normal',
    protection_need_i ENUM('normal','high') DEFAULT 'normal',
    protection_need_a ENUM('normal','high') DEFAULT 'normal',
    metadata_json   JSON,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_assets_domain FOREIGN KEY (domain_id) REFERENCES information_domains(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS process_assets (
    process_id      INT UNSIGNED NOT NULL,
    asset_id        INT UNSIGNED NOT NULL,
    PRIMARY KEY (process_id, asset_id),
    CONSTRAINT fk_pa_process FOREIGN KEY (process_id) REFERENCES business_processes(id) ON DELETE CASCADE,
    CONSTRAINT fk_pa_asset FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS profiles (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    domain_id       INT UNSIGNED NOT NULL,
    version         INT UNSIGNED DEFAULT 1,
    oscal_json      JSON NOT NULL,
    created_by      INT UNSIGNED,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_profiles_domain FOREIGN KEY (domain_id) REFERENCES information_domains(id) ON DELETE CASCADE,
    CONSTRAINT fk_profiles_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS scoped_controls (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    domain_id       INT UNSIGNED NOT NULL,
    control_id_str  VARCHAR(50) NOT NULL,
    catalog_id      INT UNSIGNED NOT NULL,
    title           VARCHAR(500),
    description     TEXT,
    parameters_json JSON,
    tailoring_json  JSON,
    is_custom       BOOLEAN DEFAULT FALSE,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_scoped_domain_control (domain_id, control_id_str),
    CONSTRAINT fk_scoped_domain FOREIGN KEY (domain_id) REFERENCES information_domains(id) ON DELETE CASCADE,
    CONSTRAINT fk_scoped_catalog FOREIGN KEY (catalog_id) REFERENCES catalogs(id),
    FULLTEXT INDEX ft_scoped_title_desc (title, description)
) ENGINE=InnoDB;

-- DOWN
DROP TABLE IF EXISTS scoped_controls;
DROP TABLE IF EXISTS profiles;
DROP TABLE IF EXISTS process_assets;
DROP TABLE IF EXISTS assets;
DROP TABLE IF EXISTS business_processes;

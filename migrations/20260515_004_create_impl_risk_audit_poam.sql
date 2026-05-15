-- UP
CREATE TABLE IF NOT EXISTS implementations (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    scoped_control_id   INT UNSIGNED NOT NULL,
    asset_id            INT UNSIGNED,
    status              ENUM('not_started','planned','partial','implemented','not_applicable') DEFAULT 'not_started',
    maturity_level      TINYINT UNSIGNED DEFAULT 0,
    description         TEXT,
    responsible_user_id INT UNSIGNED,
    target_date         DATE,
    completion_date     DATE,
    evidence_json       JSON,
    parameters_json     JSON,
    updated_by          INT UNSIGNED,
    created_at          DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_impl_control FOREIGN KEY (scoped_control_id) REFERENCES scoped_controls(id) ON DELETE CASCADE,
    CONSTRAINT fk_impl_asset FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE SET NULL,
    CONSTRAINT fk_impl_responsible FOREIGN KEY (responsible_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_impl_updater FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS risks (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    domain_id       INT UNSIGNED NOT NULL,
    title           VARCHAR(255) NOT NULL,
    description     TEXT,
    asset_id        INT UNSIGNED,
    likelihood      ENUM('very_low','low','medium','high','very_high') DEFAULT 'medium',
    impact          ENUM('negligible','low','medium','high','critical') DEFAULT 'medium',
    risk_level      ENUM('low','medium','high','critical'),
    treatment       ENUM('mitigate','accept','transfer','avoid') DEFAULT 'mitigate',
    acceptance_justification TEXT,
    owner_user_id   INT UNSIGNED,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_risks_domain FOREIGN KEY (domain_id) REFERENCES information_domains(id) ON DELETE CASCADE,
    CONSTRAINT fk_risks_asset FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE SET NULL,
    CONSTRAINT fk_risks_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS risk_controls (
    risk_id             INT UNSIGNED NOT NULL,
    scoped_control_id   INT UNSIGNED NOT NULL,
    PRIMARY KEY (risk_id, scoped_control_id),
    CONSTRAINT fk_rc_risk FOREIGN KEY (risk_id) REFERENCES risks(id) ON DELETE CASCADE,
    CONSTRAINT fk_rc_control FOREIGN KEY (scoped_control_id) REFERENCES scoped_controls(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS assessment_plans (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    domain_id       INT UNSIGNED NOT NULL,
    title           VARCHAR(255) NOT NULL,
    version         INT UNSIGNED DEFAULT 1,
    assessor_name   VARCHAR(255),
    assessor_org    VARCHAR(255),
    assessor_email  VARCHAR(255),
    period_start    DATE,
    period_end      DATE,
    methodology     TEXT,
    rules_of_engagement TEXT,
    status          ENUM('draft','active','completed') DEFAULT 'draft',
    oscal_json      JSON,
    created_by      INT UNSIGNED,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_ap_domain FOREIGN KEY (domain_id) REFERENCES information_domains(id) ON DELETE CASCADE,
    CONSTRAINT fk_ap_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS assessment_findings (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    plan_id             INT UNSIGNED NOT NULL,
    scoped_control_id   INT UNSIGNED NOT NULL,
    method              SET('examine','interview','test'),
    result              ENUM('satisfied','not_satisfied','partial','not_assessed') DEFAULT 'not_assessed',
    observation         TEXT,
    risk_statement      TEXT,
    assessed_by         INT UNSIGNED,
    assessed_at         DATETIME,
    created_at          DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_af_plan FOREIGN KEY (plan_id) REFERENCES assessment_plans(id) ON DELETE CASCADE,
    CONSTRAINT fk_af_control FOREIGN KEY (scoped_control_id) REFERENCES scoped_controls(id),
    CONSTRAINT fk_af_assessor FOREIGN KEY (assessed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS poam_items (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    domain_id           INT UNSIGNED NOT NULL,
    finding_id          INT UNSIGNED,
    scoped_control_id   INT UNSIGNED,
    title               VARCHAR(255) NOT NULL,
    description         TEXT,
    priority            ENUM('high','medium','low') DEFAULT 'medium',
    status              ENUM('open','in_progress','completed','verified','accepted') DEFAULT 'open',
    responsible_user_id INT UNSIGNED,
    deadline            DATE,
    completion_date     DATE,
    deviation_justification TEXT,
    milestones_json     JSON,
    created_at          DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_poam_domain FOREIGN KEY (domain_id) REFERENCES information_domains(id) ON DELETE CASCADE,
    CONSTRAINT fk_poam_finding FOREIGN KEY (finding_id) REFERENCES assessment_findings(id) ON DELETE SET NULL,
    CONSTRAINT fk_poam_control FOREIGN KEY (scoped_control_id) REFERENCES scoped_controls(id) ON DELETE SET NULL,
    CONSTRAINT fk_poam_responsible FOREIGN KEY (responsible_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS evidence_files (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id       INT UNSIGNED NOT NULL,
    original_name   VARCHAR(255) NOT NULL,
    stored_path     VARCHAR(512) NOT NULL,
    mime_type       VARCHAR(100),
    file_size       INT UNSIGNED,
    sha256_hash     VARCHAR(64),
    uploaded_by     INT UNSIGNED,
    uploaded_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_evidence_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_evidence_uploader FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ai_cache (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id       INT UNSIGNED NOT NULL,
    cache_key       VARCHAR(64) NOT NULL,
    prompt_type     VARCHAR(50),
    response_text   MEDIUMTEXT,
    provider        VARCHAR(20),
    model           VARCHAR(50),
    tokens_used     INT UNSIGNED,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ai_cache (tenant_id, cache_key),
    CONSTRAINT fk_ai_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS audit_log (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id       INT UNSIGNED NOT NULL,
    user_id         INT UNSIGNED,
    action          VARCHAR(50) NOT NULL,
    entity_type     VARCHAR(50) NOT NULL,
    entity_id       INT UNSIGNED,
    changes_json    JSON,
    ip_address      VARCHAR(45),
    user_agent      VARCHAR(500),
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_entity (tenant_id, entity_type, entity_id),
    INDEX idx_audit_user (tenant_id, user_id, created_at),
    CONSTRAINT fk_audit_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS document_versions (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id       INT UNSIGNED NOT NULL,
    document_type   ENUM('profile','ssp','ap','ar','poam') NOT NULL,
    domain_id       INT UNSIGNED NOT NULL,
    version         INT UNSIGNED NOT NULL,
    oscal_json      JSON NOT NULL,
    changelog       TEXT,
    created_by      INT UNSIGNED,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_docver (tenant_id, document_type, domain_id, version),
    CONSTRAINT fk_docver_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_docver_domain FOREIGN KEY (domain_id) REFERENCES information_domains(id) ON DELETE CASCADE,
    CONSTRAINT fk_docver_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- DOWN
DROP TABLE IF EXISTS document_versions;
DROP TABLE IF EXISTS audit_log;
DROP TABLE IF EXISTS ai_cache;
DROP TABLE IF EXISTS evidence_files;
DROP TABLE IF EXISTS poam_items;
DROP TABLE IF EXISTS assessment_findings;
DROP TABLE IF EXISTS assessment_plans;
DROP TABLE IF EXISTS risk_controls;
DROP TABLE IF EXISTS risks;
DROP TABLE IF EXISTS implementations;

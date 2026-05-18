-- UP
CREATE TABLE IF NOT EXISTS implementation_assets (
    implementation_id INT UNSIGNED NOT NULL,
    asset_id          INT UNSIGNED NOT NULL,
    PRIMARY KEY (implementation_id, asset_id),
    CONSTRAINT fk_ia_impl  FOREIGN KEY (implementation_id) REFERENCES implementations(id) ON DELETE CASCADE,
    CONSTRAINT fk_ia_asset FOREIGN KEY (asset_id)          REFERENCES assets(id)          ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migrate existing single-FK data
INSERT IGNORE INTO implementation_assets (implementation_id, asset_id)
SELECT id, asset_id FROM implementations WHERE asset_id IS NOT NULL;

-- Drop the old column
ALTER TABLE implementations DROP FOREIGN KEY fk_impl_asset;
ALTER TABLE implementations DROP COLUMN asset_id;

-- DOWN
ALTER TABLE implementations ADD COLUMN asset_id INT UNSIGNED NULL AFTER scoped_control_id;
ALTER TABLE implementations ADD CONSTRAINT fk_impl_asset FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE SET NULL;
INSERT IGNORE INTO implementations (id, asset_id)
SELECT implementation_id, asset_id FROM implementation_assets;
DROP TABLE IF EXISTS implementation_assets;

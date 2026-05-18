-- UP
ALTER TABLE scoped_controls
    ADD COLUMN param_labels_json TEXT NOT NULL DEFAULT '{}' AFTER parameters_json;

-- DOWN
ALTER TABLE scoped_controls DROP COLUMN param_labels_json;

-- The diary: dated log entries under a batch. Day-counter/timeline display
-- is computed at render time (entry_date - batch.started_at), not stored,
-- to avoid drift if started_at is edited later.
CREATE TABLE IF NOT EXISTS batch_log_entries (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    batch_id INT UNSIGNED NOT NULL,
    entry_date DATE NOT NULL,
    note TEXT NULL,
    specific_gravity DECIMAL(5,3) NULL,
    acidity_ph DECIMAL(4,2) NULL,
    temperature DECIMAL(5,2) NULL,
    temperature_unit VARCHAR(10) NULL,
    stage_vessel VARCHAR(191) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_batch_log_entries_batch_id_entry_date (batch_id, entry_date),
    CONSTRAINT fk_batch_log_entries_batch_id FOREIGN KEY (batch_id) REFERENCES batches (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

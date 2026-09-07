-- `is_public` mirrors recipes.is_public: private by default, same
-- owner/other/anonymous enforcement pattern applied in the service layer.
-- No share_token/is_shared columns — sharing is out of scope for v1.0.1.
CREATE TABLE IF NOT EXISTS batches (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    recipe_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    label VARCHAR(191) NULL,
    status ENUM('planning', 'fermenting', 'conditioning', 'bottled', 'completed', 'archived') NOT NULL DEFAULT 'planning',
    started_at DATE NOT NULL,
    is_public BOOLEAN NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_batches_recipe_id (recipe_id),
    KEY idx_batches_user_id (user_id),
    KEY idx_batches_is_public_updated_at (is_public, updated_at),
    CONSTRAINT fk_batches_recipe_id FOREIGN KEY (recipe_id) REFERENCES recipes (id) ON DELETE CASCADE,
    CONSTRAINT fk_batches_user_id FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

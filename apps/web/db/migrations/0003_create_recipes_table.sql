-- `is_public` is NOT NULL DEFAULT 0 (private by default) per the v1.0.1
-- visibility model: owner always has full access; other authenticated
-- users and anonymous visitors get read-only access iff is_public.
-- No share_token/is_shared columns — sharing is out of scope for v1.0.1.
CREATE TABLE IF NOT EXISTS recipes (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    name VARCHAR(191) NOT NULL,
    category ENUM('beer', 'wine', 'mead', 'cider', 'other') NOT NULL DEFAULT 'other',
    description TEXT NULL,
    batch_size DECIMAL(10,2) NULL,
    batch_size_unit VARCHAR(20) NULL,
    water_quantity DECIMAL(10,2) NULL,
    water_unit VARCHAR(20) NULL,
    sugar_quantity DECIMAL(10,2) NULL,
    sugar_unit VARCHAR(20) NULL,
    sugar_type VARCHAR(60) NULL,
    yeast_type VARCHAR(60) NULL,
    yeast_quantity DECIMAL(10,2) NULL,
    yeast_unit VARCHAR(20) NULL,
    target_og DECIMAL(6,3) NULL,
    target_fg DECIMAL(6,3) NULL,
    notes TEXT NULL,
    is_public BOOLEAN NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_recipes_user_id (user_id),
    KEY idx_recipes_is_public_updated_at (is_public, updated_at),
    CONSTRAINT fk_recipes_user_id FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

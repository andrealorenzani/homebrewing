CREATE TABLE IF NOT EXISTS recipe_ingredients (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    recipe_id INT UNSIGNED NOT NULL,
    ingredient_type ENUM('fruit', 'grain', 'hop', 'other') NOT NULL DEFAULT 'other',
    name VARCHAR(191) NOT NULL,
    quantity DECIMAL(10,2) NULL,
    unit VARCHAR(20) NULL,
    timing_note VARCHAR(191) NULL,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_recipe_ingredients_recipe_id (recipe_id),
    CONSTRAINT fk_recipe_ingredients_recipe_id FOREIGN KEY (recipe_id) REFERENCES recipes (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

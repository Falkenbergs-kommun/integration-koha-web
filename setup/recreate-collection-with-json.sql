-- Recreate kft_koha_enriched collection with correct JSON field types
-- Run this SQL script directly against the Directus database

USE directus;

-- Drop existing table (WARNING: This deletes all data!)
DROP TABLE IF EXISTS kft_koha_enriched;

-- Create table with correct JSON types
CREATE TABLE kft_koha_enriched (
    id INT AUTO_INCREMENT PRIMARY KEY,
    biblio_id INT NOT NULL UNIQUE,
    isbn_clean VARCHAR(20),
    title VARCHAR(500),
    abstract_enriched TEXT,
    subjects JSON,  -- JSON type, not TEXT!
    tags JSON,  -- JSON type, not TEXT!
    target_audience VARCHAR(255),
    grounding_search_queries JSON,  -- JSON type, not TEXT!
    grounding_sources JSON,  -- JSON type, not TEXT!
    date_created TIMESTAMP DEFAULT CURRENT_TIMESTAMP NOT NULL,
    date_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_biblio_id (biblio_id),
    INDEX idx_isbn_clean (isbn_clean),
    INDEX idx_target_audience (target_audience)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add fulltext index for abstract searching
ALTER TABLE kft_koha_enriched
ADD FULLTEXT INDEX idx_abstract_enriched (abstract_enriched);

SELECT 'Table recreated with JSON types!' AS status;
SELECT 'Now run: php docs/import-enriched-data.php' AS next_step;

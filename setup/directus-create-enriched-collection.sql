-- SQL DDL for creating the kft_koha_enriched collection
-- This table stores AI-enriched metadata for library biblios

CREATE TABLE IF NOT EXISTS kft_koha_enriched (
    id INT AUTO_INCREMENT PRIMARY KEY COMMENT 'Primary key',
    biblio_id INT NOT NULL UNIQUE COMMENT 'Foreign key to kft_koha_biblios.biblio_id',
    isbn_clean VARCHAR(20) COMMENT 'Cleaned ISBN without dashes',
    title VARCHAR(500) COMMENT 'Book title (denormalized for quick access)',
    abstract_enriched TEXT COMMENT 'AI-generated enhanced abstract with detailed description',
    subjects JSON COMMENT 'Array of subject categories',
    tags JSON COMMENT 'Array of searchable tags',
    target_audience VARCHAR(255) COMMENT 'Intended audience/reading level',
    grounding_search_queries JSON COMMENT 'Search queries used by AI for grounding',
    grounding_sources JSON COMMENT 'Sources used by AI with URIs and titles',
    date_created TIMESTAMP DEFAULT CURRENT_TIMESTAMP NOT NULL COMMENT 'Creation timestamp',
    date_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last update timestamp',

    INDEX idx_biblio_id (biblio_id),
    INDEX idx_isbn_clean (isbn_clean),
    INDEX idx_target_audience (target_audience),

    CONSTRAINT fk_biblio_id
        FOREIGN KEY (biblio_id)
        REFERENCES kft_koha_biblios(biblio_id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='AI-enriched bibliographic metadata with enhanced abstracts, subjects, tags and grounding sources';

-- Create fulltext index for searching in enriched abstract
ALTER TABLE kft_koha_enriched
ADD FULLTEXT INDEX idx_abstract_enriched (abstract_enriched);

-- Optional: Create virtual columns for easier subject/tag searching
-- (MySQL 5.7+ supports JSON functions)
ALTER TABLE kft_koha_enriched
ADD COLUMN subjects_text TEXT GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(subjects, '$'))) STORED;

ALTER TABLE kft_koha_enriched
ADD COLUMN tags_text TEXT GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(tags, '$'))) STORED;

-- Create fulltext indexes for subject and tag searching
ALTER TABLE kft_koha_enriched
ADD FULLTEXT INDEX idx_subjects (subjects_text);

ALTER TABLE kft_koha_enriched
ADD FULLTEXT INDEX idx_tags (tags_text);

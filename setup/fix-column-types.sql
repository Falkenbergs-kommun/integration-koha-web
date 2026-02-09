-- Fix column types from LONGTEXT to JSON
-- Run this directly against the Directus database

USE directus;

-- First, clear the data (stringified JSON cannot be auto-converted to JSON type)
UPDATE kft_koha_enriched SET
    subjects = NULL,
    tags = NULL,
    grounding_search_queries = NULL,
    grounding_sources = NULL;

-- Change column types to JSON
ALTER TABLE kft_koha_enriched
    MODIFY COLUMN subjects JSON,
    MODIFY COLUMN tags JSON,
    MODIFY COLUMN grounding_search_queries JSON,
    MODIFY COLUMN grounding_sources JSON;

-- Verify
DESCRIBE kft_koha_enriched;

SELECT 'Column types updated to JSON!' AS status;
SELECT 'Now run: php docs/import-enriched-data.php' AS next_step;

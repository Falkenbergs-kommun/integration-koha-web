-- Fix JSON field types in kft_koha_enriched table
-- These fields were created as LONGTEXT but should be JSON

USE directus;

-- Change field types from LONGTEXT to JSON
ALTER TABLE kft_koha_enriched
    MODIFY COLUMN subjects JSON,
    MODIFY COLUMN tags JSON,
    MODIFY COLUMN grounding_search_queries JSON,
    MODIFY COLUMN grounding_sources JSON;

-- Verify the changes
DESCRIBE kft_koha_enriched;

SELECT 'JSON field types updated successfully!' AS status;

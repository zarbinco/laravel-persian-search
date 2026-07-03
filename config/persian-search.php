<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Search Driver
    |--------------------------------------------------------------------------
    |
    | The database driver stores normalized search documents in a portable
    | database table and ranks results in PHP.
    |
    */

    'driver' => env('PERSIAN_SEARCH_DRIVER', 'database'),

    /*
    |--------------------------------------------------------------------------
    | Normalization Boundary
    |--------------------------------------------------------------------------
    |
    | Persian normalization and tokenization are delegated to
    | zarbinco/laravel-persian-core.
    |
    */

    'normalizer' => [
        'driver' => 'persian-core',
    ],

    /*
    |--------------------------------------------------------------------------
    | Indexing
    |--------------------------------------------------------------------------
    |
    | Automatic sync keeps persisted search documents aligned with Eloquent
    | model save, delete, and restore events.
    |
    */

    'index' => [
        'table' => 'persian_search_documents',
        'queue' => false,
        'sync_on_save' => true,
        'delete_on_model_delete' => true,
        'include_soft_deleted' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Database Candidate Loading
    |--------------------------------------------------------------------------
    */

    'database' => [
        'min_token_length' => 2,
        'max_tokens' => 20,
        'max_candidates' => 500,
    ],

    /*
    |--------------------------------------------------------------------------
    | Relevance Scoring
    |--------------------------------------------------------------------------
    */

    'ranking' => [
        'exact_phrase' => 100,
        'all_tokens' => 70,
        'any_token' => 20,
        'title_boost' => 2.0,
        'field_weight_multiplier' => 1.0,
    ],

    /*
    |--------------------------------------------------------------------------
    | Search Limits
    |--------------------------------------------------------------------------
    */

    'search' => [
        'default_limit' => 20,
        'max_limit' => 100,
    ],

    /*
    |--------------------------------------------------------------------------
    | Query Expansion
    |--------------------------------------------------------------------------
    |
    | Query expansion happens only at search time. It never mutates stored
    | index content.
    |
    */

    'query_expansion' => [
        'enabled' => true,
        'max_candidates' => 25,
        'original_boost' => 1.0,
        'keyboard_boost' => 0.95,
        'keyboard_synonym_boost' => 0.80,
    ],

    /*
    |--------------------------------------------------------------------------
    | Synonyms
    |--------------------------------------------------------------------------
    |
    | Synonyms are disabled by default. Define project-specific maps here when
    | query candidate expansion should connect equivalent search terms.
    |
    */

    'synonyms' => [
        'enabled' => false,
        'bidirectional' => true,
        'max_candidates' => 20,
        'boost' => 0.85,
        'map' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Wrong-Keyboard Typing Correction
    |--------------------------------------------------------------------------
    |
    | English-to-Persian keyboard correction is query-time candidate expansion,
    | not core text normalization.
    |
    */

    'keyboard' => [
        'enabled' => true,
        'wrong_layout_correction' => true,
        'layouts' => [
            'en_to_fa' => true,
            'fa_to_en' => false,
        ],
        'min_query_length' => 2,
    ],
];

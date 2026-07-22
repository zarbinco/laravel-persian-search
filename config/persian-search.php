<?php

return [
    'driver' => env('PERSIAN_SEARCH_DRIVER', 'database'),

    'index' => [
        'connection' => null,
        'table' => 'persian_search_documents',
        'default_partition' => 'default',
        'undefined_locale' => 'und',
        'sync_on_save' => true,
        'delete_on_model_delete' => true,
        'include_soft_deleted' => false,
    ],

    'database' => [
        'max_candidates' => 500,
    ],

    'ranking' => [
        'exact_phrase' => 100,
        'all_tokens' => 70,
        'any_token' => 20,
        'title_boost' => 2.0,
    ],

    'search' => [
        'default_limit' => 20,
        'max_limit' => 100,
    ],

    'query' => [
        'minimum_length' => 2,
        'maximum_length' => 200,
        'minimum_token_length' => 1,
        'maximum_tokens' => 20,
        'maximum_length_policy' => 'truncate',
    ],

    'query_expansion' => [
        'enabled' => true,
        'max_candidates' => 25,
        'original_boost' => 1.0,
        'keyboard_boost' => 0.95,
        'keyboard_synonym_boost' => 0.80,
    ],

    'synonyms' => [
        'enabled' => false,
        'bidirectional' => true,
        'max_candidates' => 20,
        'boost' => 0.85,
        'map' => [],
    ],

    'keyboard' => [
        'enabled' => true,
        'wrong_layout_correction' => true,
        'layouts' => [
            'en_to_fa' => true,
        ],
        'min_query_length' => 2,
    ],
];

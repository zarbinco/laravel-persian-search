<?php

return [
    'driver' => env('PERSIAN_SEARCH_DRIVER', 'database'),

    'normalizer' => [
        'driver' => 'persian-core',
    ],

    'index' => [
        'table' => 'persian_search_documents',
        'queue' => false,
        'sync_on_save' => true,
        'delete_on_model_delete' => true,
        'include_soft_deleted' => false,
    ],

    'database' => [
        'min_token_length' => 2,
        'max_tokens' => 20,
        'max_candidates' => 500,
    ],

    'ranking' => [
        'exact_phrase' => 100,
        'all_tokens' => 70,
        'any_token' => 20,
        'title_boost' => 2.0,
        'field_weight_multiplier' => 1.0,
    ],

    'search' => [
        'default_limit' => 20,
        'max_limit' => 100,
    ],

    'synonyms' => [
        'enabled' => false,
        'map' => [],
    ],

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

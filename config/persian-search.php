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

    'variants' => [
        'maximum_variants' => 20,
        'priorities' => [
            'original' => 1000,
            'keyboard' => 800,
            'synonym' => 600,
            'keyboard_synonym' => 400,
        ],
    ],

    'synonyms' => [
        'enabled' => false,
        'locales' => [],
    ],

    'keyboard' => [
        'enabled' => true,
        'minimum_length' => 2,
        'en_to_fa' => [
            'enabled' => true,
            'source_locale' => 'en',
            'target_locale' => 'fa',
        ],
    ],
];

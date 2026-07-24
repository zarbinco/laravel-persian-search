<?php

return [
    'driver' => env('PERSIAN_SEARCH_DRIVER', 'database'),

    'index' => [
        'connection' => null,
        'table' => 'persian_search_documents',
        'default_partition' => 'default',
        'undefined_locale' => 'und',
        'sync_on_save' => true,
        'include_soft_deleted' => false,
        'transaction_attempts' => 3,
    ],

    'lifecycle' => [
        'after_commit' => true,
        'execution' => 'sync',
    ],

    'queue' => [
        'connection' => null,
        'queue' => null,
        'tries' => 3,
        'backoff' => [10, 30, 60],
        'timeout' => 60,
        'unique_for' => 300,
    ],

    'candidates' => [
        'maximum_terms_per_variant' => 10,
        'per_variant_limit' => 100,
        'maximum_candidates' => 500,
    ],

    'providers' => [
        // App\Search\ProductSearchDocumentProvider::class,
    ],

    'dependencies' => [
        'enabled' => true,
        'maximum_sources_per_event' => 1000,
        'resolvers' => [
            // App\Search\ProductCategoryDependencyResolver::class,
        ],
    ],

    'operations' => [
        'enumerators' => [],
        'chunk_size' => 500,
        'maximum_sources_per_run' => 100000,
        'lock_store' => null,
        'lock_key' => 'persian-search:maintenance',
        'lock_seconds' => 3600,
        'doctor_sample_size' => 100,
    ],

    'ranking' => [
        'tier_scores' => [
            'exact_title' => 1400,
            'title_prefix' => 1300,
            'title_phrase' => 1200,
            'title_all_tokens' => 1100,
            'title_any_token' => 1000,
            'keywords_phrase' => 900,
            'keywords_all_tokens' => 850,
            'keywords_any_token' => 800,
            'excerpt_phrase' => 700,
            'excerpt_all_tokens' => 650,
            'excerpt_any_token' => 600,
            'content_phrase' => 500,
            'content_all_tokens' => 450,
            'content_any_token' => 400,
        ],
    ],

    'results' => [
        'default_per_page' => 15,
        'maximum_per_page' => 100,
        'default_preview_limit' => 8,
        'maximum_preview_limit' => 50,
        'default_preview_per_type' => 2,
        'maximum_preview_per_type' => 10,
        'maximum_groups' => 50,
    ],

    'locale_bridge' => [
        'enabled' => true,
        'batch_size' => 200,
    ],

    'suggestions' => [
        'enabled' => true,
        'require_exact_window' => true,
        'minimum_results' => 1,
        'minimum_result_gain' => 2,
        'minimum_ratio_basis_points' => 15000,
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

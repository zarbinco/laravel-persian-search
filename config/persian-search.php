<?php

use Zarbinco\PersianSearch\Correction\EnglishLanguageCorrectionProfile;
use Zarbinco\PersianSearch\Correction\PersianLanguageCorrectionProfile;

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
            'spelling' => 700,
            'keyboard_spelling' => 650,
            'phonetic' => 640,
            'split' => 630,
            'merge' => 620,
            'keyboard_phonetic' => 615,
            'keyboard_split' => 610,
            'keyboard_merge' => 605,
            'synonym' => 600,
            'contextual' => 500,
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

    'spelling' => [
        // Keep disabled until the dictionary migration is run and the first build completes.
        'enabled' => env('PERSIAN_SEARCH_SPELLING_ENABLED', false),
        'connection' => null,
        'terms_table' => 'persian_search_dictionary_terms',
        'deletes_table' => 'persian_search_dictionary_deletes',
        'fail_when_unavailable' => false,
        'maximum_transformation_depth' => 2,
        'maximum_advanced_lookup_terms' => 256,
        'maximum_advanced_candidate_rows' => 512,

        'dictionary' => [
            'minimum_token_length' => 4,
            'maximum_token_length' => 64,
            'minimum_document_frequency' => 1,
            'maximum_terms' => 100000,
            'maximum_deletes_per_term' => 256,
            'chunk_size' => 500,
            'insert_batch_size' => 500,
            'protected_terms' => [
                '*' => [],
                // 'fa' => ['سن‌ایچ'],
                // 'en' => ['sunich'],
            ],
        ],

        'correction' => [
            'maximum_edit_distance' => 2,
            'two_edit_distance_minimum_length' => 8,
            'maximum_candidates_per_token' => 5,
            'maximum_candidate_rows_per_token' => 250,
            'maximum_candidate_rows_per_query' => 500,
            'maximum_query_variants' => 5,
            'maximum_tokens_to_inspect' => 4,
            'maximum_tokens_to_correct' => 2,
            'maximum_delete_keys_per_query_token' => 128,
            'maximum_delete_keys_per_query' => 512,
            'costs' => [
                'insertion' => 1000,
                'deletion' => 1000,
                'substitution' => 1000,
                'transposition' => 700,
                'adjacent_key_substitution' => 450,
            ],
        ],

        'phonetic' => [
            'enabled' => env('PERSIAN_SEARCH_PHONETIC_ENABLED', false),
            'profiles' => [
                PersianLanguageCorrectionProfile::class,
                EnglishLanguageCorrectionProfile::class,
            ],
            'minimum_token_length' => 3,
            'maximum_tokens_to_inspect' => 4,
            'maximum_tokens_to_correct' => 2,
            'maximum_changes_per_token' => 2,
            'maximum_alternatives_per_token' => 32,
            'maximum_candidates_per_token' => 5,
            'maximum_query_variants' => 5,
            'base_cost' => 1200,
        ],

        'segmentation' => [
            'enabled' => env('PERSIAN_SEARCH_SEGMENTATION_ENABLED', false),
            'split_enabled' => true,
            'merge_enabled' => true,
            'minimum_token_length' => 6,
            'minimum_segment_length' => 2,
            'maximum_segments' => 2,
            'maximum_split_positions_per_token' => 24,
            'maximum_adjacent_pairs' => 4,
            'maximum_merges_per_query' => 1,
            'maximum_query_variants' => 5,
            'split_cost' => 1400,
            'merge_cost' => 1500,
        ],

        // Unicode edit distance works for every locale. These optional maps only
        // make substitutions between neighboring keys cheaper for known layouts.
        'adjacent_keys' => [
            'en' => [
                'q' => ['w'], 'w' => ['q', 'e'], 'e' => ['w', 'r'], 'r' => ['e', 't'],
                't' => ['r', 'y'], 'y' => ['t', 'u'], 'u' => ['y', 'i'], 'i' => ['u', 'o'],
                'o' => ['i', 'p'], 'p' => ['o'],
                'a' => ['s'], 's' => ['a', 'd'], 'd' => ['s', 'f'], 'f' => ['d', 'g'],
                'g' => ['f', 'h'], 'h' => ['g', 'j'], 'j' => ['h', 'k'], 'k' => ['j', 'l'],
                'l' => ['k'],
                'z' => ['x'], 'x' => ['z', 'c'], 'c' => ['x', 'v'], 'v' => ['c', 'b'],
                'b' => ['v', 'n'], 'n' => ['b', 'm'], 'm' => ['n'],
            ],
            'fa' => [
                'ض' => ['ص'], 'ص' => ['ض', 'ث'], 'ث' => ['ص', 'ق'], 'ق' => ['ث', 'ف'],
                'ف' => ['ق', 'غ'], 'غ' => ['ف', 'ع'], 'ع' => ['غ', 'ه'], 'ه' => ['ع', 'خ'],
                'خ' => ['ه', 'ح'], 'ح' => ['خ', 'ج'], 'ج' => ['ح', 'چ'], 'چ' => ['ج'],
                'ش' => ['س'], 'س' => ['ش', 'ی'], 'ی' => ['س', 'ب'], 'ب' => ['ی', 'ل'],
                'ل' => ['ب', 'ا'], 'ا' => ['ل', 'ت'], 'ت' => ['ا', 'ن'], 'ن' => ['ت', 'م'],
                'م' => ['ن', 'ک'], 'ک' => ['م', 'گ'], 'گ' => ['ک'],
                'ظ' => ['ط'], 'ط' => ['ظ', 'ز'], 'ز' => ['ط', 'ر'], 'ر' => ['ز', 'ذ'],
                'ذ' => ['ر', 'د'], 'د' => ['ذ', 'پ'], 'پ' => ['د', 'و'], 'و' => ['پ'],
            ],
        ],
    ],

    'contextual' => [
        'enabled' => env('PERSIAN_SEARCH_CONTEXTUAL_CORRECTION_ENABLED', false),
        'ngrams_enabled' => env('PERSIAN_SEARCH_CONTEXTUAL_NGRAMS_ENABLED', true),
        'result_counts_enabled' => env('PERSIAN_SEARCH_CONTEXTUAL_RESULT_COUNTS_ENABLED', true),
        'auto_apply_recommendation_enabled' => env(
            'PERSIAN_SEARCH_CONTEXTUAL_AUTO_APPLY_RECOMMENDATION_ENABLED',
            false,
        ),
        'connection' => null,
        'ngrams_table' => 'persian_search_dictionary_ngrams',
        'ngram_staging_table' => 'persian_search_dictionary_ngram_staging',
        'builds_table' => 'persian_search_contextual_builds',

        'build' => [
            'enabled' => true,
            'maximum_gram_size' => 2,
            'minimum_document_frequency' => 1,
            'maximum_terms_per_document' => 200,
            'maximum_ngrams_per_document' => 400,
            'insert_batch_size' => 500,
        ],

        'trigger' => [
            'maximum_direct_results' => 3,
            'evaluate_when_zero_results' => true,
            'evaluate_when_low_results' => true,
            'evaluate_on_preview' => false,
        ],

        'decision' => [
            'minimum_confidence_basis_points' => 7500,
            'minimum_absolute_result_gain' => 3,
            'minimum_result_gain_ratio_basis_points' => 30000,
            'auto_apply_minimum_confidence_basis_points' => 9000,
            'auto_apply_requires_zero_direct_results' => true,
            'minimum_corpus_gain' => 1,
            'minimum_context_gain' => 0,
        ],

        'limits' => [
            'maximum_tokens_to_inspect' => 5,
            'maximum_tokens_to_correct' => 2,
            'maximum_candidates_per_token' => 3,
            'maximum_candidates_per_query' => 5,
            'maximum_result_count_candidates' => 3,
            'maximum_context_lookups' => 20,
            'maximum_transformation_depth' => 3,
            'maximum_query_length' => 200,
            'maximum_query_tokens' => 20,
            'result_count_cap' => 11,
            'maximum_candidate_rows' => 300,
            'maximum_delete_keys' => 512,
        ],
    ],

];

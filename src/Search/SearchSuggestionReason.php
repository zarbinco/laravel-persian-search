<?php

namespace Zarbinco\PersianSearch\Search;

enum SearchSuggestionReason: string
{
    case OriginalHadNoResults = 'original_had_no_results';
    case BetterSemanticTier = 'better_semantic_tier';
    case MaterialResultGain = 'material_result_gain';
}

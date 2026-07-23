<?php

namespace Zarbinco\PersianSearch\Candidates;

enum SearchDocumentField: string
{
    case Title = 'normalized_title';
    case Keywords = 'normalized_keywords';
    case Excerpt = 'normalized_excerpt';
    case Content = 'normalized_content';
}

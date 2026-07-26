<?php

namespace Zarbinco\PersianSearch\Search;

enum QueryVariantSource: string
{
    case Original = 'original';
    case Keyboard = 'keyboard';
    case Spelling = 'spelling';
    case KeyboardSpelling = 'keyboard_spelling';
    case Phonetic = 'phonetic';
    case KeyboardPhonetic = 'keyboard_phonetic';
    case Split = 'split';
    case KeyboardSplit = 'keyboard_split';
    case Merge = 'merge';
    case KeyboardMerge = 'keyboard_merge';
    case Synonym = 'synonym';
    case KeyboardSynonym = 'keyboard_synonym';

    public function isSuggestionRoot(): bool
    {
        return match ($this) {
            self::Keyboard,
            self::Spelling,
            self::KeyboardSpelling,
            self::Phonetic,
            self::KeyboardPhonetic,
            self::Split,
            self::KeyboardSplit,
            self::Merge,
            self::KeyboardMerge => true,
            default => false,
        };
    }

    public function isSpelling(): bool
    {
        return $this === self::Spelling || $this === self::KeyboardSpelling;
    }

    public function isAdvanced(): bool
    {
        return match ($this) {
            self::Phonetic,
            self::KeyboardPhonetic,
            self::Split,
            self::KeyboardSplit,
            self::Merge,
            self::KeyboardMerge => true,
            default => false,
        };
    }
}

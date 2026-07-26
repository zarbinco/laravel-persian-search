<?php

namespace Zarbinco\PersianSearch\Search;

enum QueryVariantSource: string
{
    case Original = 'original';
    case Keyboard = 'keyboard';
    case Spelling = 'spelling';
    case KeyboardSpelling = 'keyboard_spelling';
    case Synonym = 'synonym';
    case KeyboardSynonym = 'keyboard_synonym';

    public function isSuggestionRoot(): bool
    {
        return match ($this) {
            self::Keyboard, self::Spelling, self::KeyboardSpelling => true,
            default => false,
        };
    }

    public function isSpelling(): bool
    {
        return $this === self::Spelling || $this === self::KeyboardSpelling;
    }
}

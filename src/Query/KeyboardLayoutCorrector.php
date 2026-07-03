<?php

namespace Zarbinco\PersianSearch\Query;

final class KeyboardLayoutCorrector
{
    public function correct(string $query): ?string
    {
        if (! (bool) config('persian-search.keyboard.enabled', true)) {
            return null;
        }

        if (! (bool) config('persian-search.keyboard.wrong_layout_correction', true)) {
            return null;
        }

        if (! (bool) config('persian-search.keyboard.layouts.en_to_fa', true)) {
            return null;
        }

        if ($this->length($query) < max(1, (int) config('persian-search.keyboard.min_query_length', 2))) {
            return null;
        }

        $corrected = '';
        $changed = false;

        foreach ($this->characters($query) as $character) {
            $mapped = $this->enToFaMap()[strtolower($character)] ?? null;

            if ($mapped === null) {
                $corrected .= $character;

                continue;
            }

            $corrected .= $mapped;
            $changed = $changed || $mapped !== $character;
        }

        if (! $changed || trim($corrected) === '' || $corrected === $query) {
            return null;
        }

        return $corrected;
    }

    /**
     * @return array<string, string>
     */
    private function enToFaMap(): array
    {
        return [
            'q' => 'ض',
            'w' => 'ص',
            'e' => 'ث',
            'r' => 'ق',
            't' => 'ف',
            'y' => 'غ',
            'u' => 'ع',
            'i' => 'ه',
            'o' => 'خ',
            'p' => 'ح',
            '[' => 'ج',
            ']' => 'چ',
            'a' => 'ش',
            's' => 'س',
            'd' => 'ی',
            'f' => 'ب',
            'g' => 'ل',
            'h' => 'ا',
            'j' => 'ت',
            'k' => 'ن',
            'l' => 'م',
            ';' => 'ک',
            "'" => 'گ',
            'z' => 'ظ',
            'x' => 'ط',
            'c' => 'ز',
            'v' => 'ر',
            'b' => 'ذ',
            'n' => 'د',
            'm' => 'پ',
            ',' => 'و',
            '.' => '.',
            '/' => '/',
        ];
    }

    private function length(string $query): int
    {
        return count($this->characters(trim($query)));
    }

    /**
     * @return list<string>
     */
    private function characters(string $value): array
    {
        if ($value === '') {
            return [];
        }

        $characters = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);

        if (is_array($characters)) {
            return $characters;
        }

        return str_split($value);
    }
}

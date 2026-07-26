<?php

declare(strict_types=1);

namespace Zarbinco\PersianSearch\Correction;

use Zarbinco\PersianSearch\Contracts\LanguageCorrectionProfile;

final class PersianLanguageCorrectionProfile implements LanguageCorrectionProfile
{
    /**
     * Directed conservative confusion costs. Lower values represent more common
     * replacements toward the prevalent Persian spelling.
     *
     * @var array<string, array<string, int>>
     */
    private array $confusions = [
        'س' => ['ص' => 750, 'ث' => 800],
        'ص' => ['س' => 350, 'ث' => 650],
        'ث' => ['س' => 400, 'ص' => 650],
        'ز' => ['ذ' => 800, 'ض' => 850, 'ظ' => 850],
        'ذ' => ['ز' => 400, 'ض' => 700, 'ظ' => 700],
        'ض' => ['ز' => 450, 'ذ' => 700, 'ظ' => 650],
        'ظ' => ['ز' => 450, 'ذ' => 700, 'ض' => 650],
        'ت' => ['ط' => 750],
        'ط' => ['ت' => 400],
        'ق' => ['غ' => 450],
        'غ' => ['ق' => 650],
        'ه' => ['ح' => 800],
        'ح' => ['ه' => 550],
    ];

    public function locale(): string
    {
        return 'fa';
    }

    public function phoneticAlternatives(string $token): iterable
    {
        $characters = preg_split('//u', $token, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($characters as $index => $character) {
            foreach ($this->confusions[$character] ?? [] as $replacement => $cost) {
                $candidate = $characters;
                $candidate[$index] = $replacement;

                yield new PhoneticAlternative(
                    implode('', $candidate),
                    $cost,
                    $character.'>'.$replacement,
                );
            }
        }
    }

    public function separators(): array
    {
        return [' ', '-', "\u{200C}"];
    }
}

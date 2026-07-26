<?php

declare(strict_types=1);

namespace Zarbinco\PersianSearch\Correction;

use Zarbinco\PersianSearch\Contracts\LanguageCorrectionProfile;

final class EnglishLanguageCorrectionProfile implements LanguageCorrectionProfile
{
    /** @var list<array{from: string, to: string, cost: int}> */
    private array $confusions = [
        ['from' => 'ph', 'to' => 'f', 'cost' => 450],
        ['from' => 'f', 'to' => 'ph', 'cost' => 650],
        ['from' => 'ck', 'to' => 'k', 'cost' => 500],
        ['from' => 'k', 'to' => 'ck', 'cost' => 750],
        ['from' => 'c', 'to' => 'k', 'cost' => 700],
        ['from' => 'k', 'to' => 'c', 'cost' => 850],
    ];

    public function locale(): string
    {
        return 'en';
    }

    public function phoneticAlternatives(string $token): iterable
    {
        foreach ($this->confusions as $confusion) {
            $offset = 0;
            while (($position = strpos($token, $confusion['from'], $offset)) !== false) {
                $candidate = substr($token, 0, $position)
                    .$confusion['to']
                    .substr($token, $position + strlen($confusion['from']));

                yield new PhoneticAlternative(
                    $candidate,
                    $confusion['cost'],
                    $confusion['from'].'>'.$confusion['to'],
                );
                $offset = $position + max(1, strlen($confusion['from']));
            }
        }
    }

    public function separators(): array
    {
        return [' ', '-', '_'];
    }
}

<?php

declare(strict_types=1);

namespace Zarbinco\PersianSearch\Contextual;

enum ContextualConfidence: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';

    public static function fromBasisPoints(int $basisPoints): self
    {
        return match (true) {
            $basisPoints >= 9000 => self::High,
            $basisPoints >= 7500 => self::Medium,
            default => self::Low,
        };
    }
}

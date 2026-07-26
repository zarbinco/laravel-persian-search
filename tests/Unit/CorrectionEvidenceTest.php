<?php

declare(strict_types=1);

namespace Zarbinco\PersianSearch\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zarbinco\PersianSearch\Contextual\CorrectionEvidence;

final class CorrectionEvidenceTest extends TestCase
{
    #[DataProvider('invalidAnalytics')]
    public function test_invalid_optional_analytics_cannot_enter_confidence(
        float $popularity,
        float $clicks,
    ): void {
        $this->expectException(InvalidArgumentException::class);

        new CorrectionEvidence(
            candidateFingerprint: 'candidate',
            originalUnigramScore: 1,
            candidateUnigramScore: 2,
            originalContextScore: 0,
            candidateContextScore: 1,
            originalPhraseFrequency: 0,
            candidatePhraseFrequency: 1,
            contextApplicable: true,
            ngramsReady: true,
            popularitySignal: $popularity,
            clickSignal: $clicks,
        );
    }

    /** @return iterable<string, array{float, float}> */
    public static function invalidAnalytics(): iterable
    {
        yield 'negative popularity' => [-0.01, 0.0];
        yield 'popularity above one' => [1.01, 0.0];
        yield 'NaN popularity' => [NAN, 0.0];
        yield 'infinite click confidence' => [0.0, INF];
    }
}

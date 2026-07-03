<?php

namespace Zarbinco\PersianSearch\Tests\Feature;

use Zarbinco\PersianCore\Facades\Persian;
use Zarbinco\PersianSearch\Contracts\QueryExpander;
use Zarbinco\PersianSearch\Facades\PersianSearch;
use Zarbinco\PersianSearch\Query\DefaultQueryExpander;
use Zarbinco\PersianSearch\Query\KeyboardLayoutCorrector;
use Zarbinco\PersianSearch\Search\QueryCandidate;
use Zarbinco\PersianSearch\Tests\TestCase;

final class QueryExpansionTest extends TestCase
{
    public function test_query_expander_contract_resolves_to_default_expander(): void
    {
        $this->assertInstanceOf(DefaultQueryExpander::class, app(QueryExpander::class));
    }

    public function test_original_candidate_is_first(): void
    {
        $candidates = PersianSearch::expand('کیک شکلاتی');

        $this->assertNotSame([], $candidates);
        $this->assertSame('original', $candidates[0]->source);
        $this->assertSame('کیک شکلاتی', $candidates[0]->original);
        $this->assertSame(Persian::search('کیک شکلاتی')->normalize(), $candidates[0]->normalized);
        $this->assertSame(Persian::search('کیک شکلاتی')->tokens(), $candidates[0]->tokens);
        $this->assertSame(1.0, $candidates[0]->boost);
    }

    public function test_keyboard_layout_corrector_maps_english_keyboard_input_to_persian(): void
    {
        $corrector = app(KeyboardLayoutCorrector::class);

        $this->assertSame('کیف', $corrector->correct(';dt'));
        $this->assertSame('سامسونگ', $corrector->correct("shls,k'"));
    }

    public function test_wrong_keyboard_candidates_keep_original_candidate(): void
    {
        $bagCandidates = PersianSearch::expand(';dt');
        $samsungCandidates = PersianSearch::expand("shls,k'");

        $this->assertSame('original', $bagCandidates[0]->source);
        $this->assertCandidate($bagCandidates, 'keyboard', Persian::search('کیف')->normalize());
        $this->assertCandidate($samsungCandidates, 'keyboard', Persian::search('سامسونگ')->normalize());
    }

    public function test_wrong_keyboard_candidates_are_not_generated_when_disabled(): void
    {
        config()->set('persian-search.keyboard.enabled', false);

        $this->assertNoCandidateSource(PersianSearch::expand(';dt'), 'keyboard');

        config()->set('persian-search.keyboard.enabled', true);
        config()->set('persian-search.keyboard.wrong_layout_correction', false);

        $this->assertNoCandidateSource(PersianSearch::expand(';dt'), 'keyboard');
    }

    public function test_synonym_expansion_is_disabled_by_default(): void
    {
        config()->set('persian-search.synonyms.map', [
            'گوشی' => ['موبایل', 'تلفن همراه'],
        ]);

        $this->assertNoCandidateSource(PersianSearch::expand('موبایل سامسونگ'), 'synonym');
    }

    public function test_synonym_expansion_is_configurable_bidirectional_and_deduplicated(): void
    {
        config()->set('persian-search.synonyms.enabled', true);
        config()->set('persian-search.synonyms.map', [
            'گوشی' => ['موبایل', 'موبایل', 'تلفن همراه'],
        ]);

        $fromValue = PersianSearch::expand('موبایل سامسونگ');
        $fromKey = PersianSearch::expand('گوشی سامسونگ');

        $this->assertCandidate($fromValue, 'synonym', Persian::search('گوشی سامسونگ')->normalize());
        $this->assertCandidate($fromKey, 'synonym', Persian::search('موبایل سامسونگ')->normalize());
        $this->assertCandidate($fromKey, 'synonym', Persian::search('تلفن همراه سامسونگ')->normalize());
        $this->assertSame(
            count($this->normalizedCandidates($fromValue)),
            count(array_unique($this->normalizedCandidates($fromValue))),
        );
    }

    public function test_synonym_candidates_respect_configured_limit(): void
    {
        config()->set('persian-search.synonyms.enabled', true);
        config()->set('persian-search.query_expansion.max_candidates', 25);
        config()->set('persian-search.synonyms.max_candidates', 2);
        config()->set('persian-search.synonyms.map', [
            'کالا' => ['محصول', 'جنس', 'وسیله', 'مورد'],
        ]);

        $synonyms = array_filter(
            PersianSearch::expand('کالا سامسونگ'),
            static fn (QueryCandidate $candidate): bool => $candidate->source === 'synonym',
        );

        $this->assertCount(2, $synonyms);
    }

    public function test_global_query_expansion_candidate_limit_is_respected(): void
    {
        config()->set('persian-search.synonyms.enabled', true);
        config()->set('persian-search.query_expansion.max_candidates', 2);
        config()->set('persian-search.synonyms.max_candidates', 20);
        config()->set('persian-search.synonyms.map', [
            'کالا' => ['محصول', 'جنس', 'وسیله', 'مورد'],
        ]);

        $this->assertCount(2, PersianSearch::expand('کالا سامسونگ'));
    }

    /**
     * @param  array<int, QueryCandidate>  $candidates
     */
    private function assertCandidate(array $candidates, string $source, string $normalized): void
    {
        foreach ($candidates as $candidate) {
            if ($candidate->source === $source && $candidate->normalized === $normalized) {
                $this->assertSame(Persian::search($candidate->original)->normalize(), $candidate->normalized);
                $this->assertSame(Persian::search($candidate->original)->tokens(), $candidate->tokens);

                return;
            }
        }

        $this->fail("Expected [{$source}] candidate [{$normalized}] was not found.");
    }

    /**
     * @param  array<int, QueryCandidate>  $candidates
     */
    private function assertNoCandidateSource(array $candidates, string $source): void
    {
        foreach ($candidates as $candidate) {
            $this->assertNotSame($source, $candidate->source);
        }
    }

    /**
     * @param  array<int, QueryCandidate>  $candidates
     * @return list<string>
     */
    private function normalizedCandidates(array $candidates): array
    {
        $normalized = [];

        foreach ($candidates as $candidate) {
            $normalized[] = $candidate->normalized;
        }

        return $normalized;
    }
}

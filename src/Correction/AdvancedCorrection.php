<?php

declare(strict_types=1);

namespace Zarbinco\PersianSearch\Correction;

use InvalidArgumentException;
use JsonSerializable;

final readonly class AdvancedCorrection implements JsonSerializable
{
    /** @var list<string> */
    public array $tokens;

    /** @var list<QueryTransformation> */
    public array $transformations;

    /**
     * @param  array<int, mixed>  $tokens
     * @param  array<int, mixed>  $transformations
     */
    public function __construct(
        public string $originalQuery,
        public string $normalizedQuery,
        public string $correctedQuery,
        public string $locale,
        array $tokens,
        array $transformations,
        public int $weightedCost,
        public int $transformationDepth,
        public string $fingerprint,
    ) {
        if ($this->originalQuery === '' || $this->normalizedQuery === '' || $this->correctedQuery === ''
            || $this->correctedQuery === $this->normalizedQuery || trim($this->locale) === ''
            || $this->weightedCost < 1 || $this->transformationDepth < 1 || $this->fingerprint === '') {
            throw new InvalidArgumentException('Advanced correction metadata is invalid.');
        }

        $this->tokens = $this->validatedTokens($tokens);
        $this->transformations = $this->validatedTransformations($transformations);

        if (count($this->transformations) !== $this->transformationDepth) {
            throw new InvalidArgumentException('Advanced correction depth must equal its transformation count.');
        }
    }

    public function type(): CorrectionTransformationType
    {
        return $this->transformations[array_key_last($this->transformations)]->type;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'original_query' => $this->originalQuery,
            'normalized_query' => $this->normalizedQuery,
            'corrected_query' => $this->correctedQuery,
            'locale' => $this->locale,
            'tokens' => $this->tokens,
            'transformations' => array_map(
                static fn (QueryTransformation $transformation): array => $transformation->toArray(),
                $this->transformations,
            ),
            'weighted_cost' => $this->weightedCost,
            'transformation_depth' => $this->transformationDepth,
            'fingerprint' => $this->fingerprint,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * @param  array<int, mixed>  $tokens
     * @return list<string>
     */
    private function validatedTokens(array $tokens): array
    {
        $validated = [];
        foreach ($tokens as $token) {
            if (! is_string($token) || $token === '') {
                throw new InvalidArgumentException('Advanced correction tokens must be non-empty strings.');
            }
            $validated[] = $token;
        }

        if ($validated === []) {
            throw new InvalidArgumentException('Advanced correction tokens must not be empty.');
        }

        return $validated;
    }

    /**
     * @param  array<int, mixed>  $transformations
     * @return list<QueryTransformation>
     */
    private function validatedTransformations(array $transformations): array
    {
        $validated = [];
        foreach ($transformations as $transformation) {
            if (! $transformation instanceof QueryTransformation) {
                throw new InvalidArgumentException('Advanced corrections must contain query transformations.');
            }
            $validated[] = $transformation;
        }

        if ($validated === []) {
            throw new InvalidArgumentException('An advanced correction must contain a transformation.');
        }

        return $validated;
    }
}

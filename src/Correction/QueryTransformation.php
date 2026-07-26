<?php

declare(strict_types=1);

namespace Zarbinco\PersianSearch\Correction;

use InvalidArgumentException;
use JsonSerializable;

final readonly class QueryTransformation implements JsonSerializable
{
    /** @var list<string> */
    public array $originalTokens;

    /** @var list<string> */
    public array $replacementTokens;

    /**
     * @param  array<int, mixed>  $originalTokens
     * @param  array<int, mixed>  $replacementTokens
     */
    public function __construct(
        public CorrectionTransformationType $type,
        public int $tokenIndex,
        array $originalTokens,
        array $replacementTokens,
        public int $weightedCost,
        public string $profile,
        public string $rule,
    ) {
        if ($this->tokenIndex < 0 || $this->weightedCost < 1
            || trim($this->profile) === '' || trim($this->rule) === '') {
            throw new InvalidArgumentException('Query transformation metadata is invalid.');
        }

        $this->originalTokens = $this->tokens($originalTokens);
        $this->replacementTokens = $this->tokens($replacementTokens);

        if ($this->originalTokens === $this->replacementTokens) {
            throw new InvalidArgumentException('A query transformation must change its tokens.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'token_index' => $this->tokenIndex,
            'original_tokens' => $this->originalTokens,
            'replacement_tokens' => $this->replacementTokens,
            'weighted_cost' => $this->weightedCost,
            'profile' => $this->profile,
            'rule' => $this->rule,
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
    private function tokens(array $tokens): array
    {
        if ($tokens === []) {
            throw new InvalidArgumentException('Query transformation tokens must not be empty.');
        }

        $validated = [];
        foreach ($tokens as $token) {
            if (! is_string($token) || $token === '') {
                throw new InvalidArgumentException('Query transformation tokens must be non-empty strings.');
            }
            $validated[] = $token;
        }

        return $validated;
    }
}

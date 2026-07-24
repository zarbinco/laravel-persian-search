<?php

namespace Zarbinco\PersianSearch\Operations;

use JsonSerializable;

final readonly class SearchDoctorReport implements JsonSerializable
{
    /** @param list<SearchDoctorCheckResult> $results */
    public function __construct(public array $results) {}

    public function passed(): int
    {
        return $this->count(SearchDoctorCheckStatus::Passed);
    }

    public function warnings(): int
    {
        return $this->count(SearchDoctorCheckStatus::Warning);
    }

    public function failures(): int
    {
        return $this->count(SearchDoctorCheckStatus::Failed);
    }

    public function skipped(): int
    {
        return $this->count(SearchDoctorCheckStatus::Skipped);
    }

    public function exitCode(bool $strict): SearchOperationExitCode
    {
        if ($this->failures() > 0) {
            return SearchOperationExitCode::Failed;
        }
        if ($strict && $this->warnings() > 0) {
            return SearchOperationExitCode::Warning;
        }

        return SearchOperationExitCode::Success;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'status' => $this->failures() > 0 ? 'failed' : ($this->warnings() > 0 ? 'warning' : 'passed'),
            'passed' => $this->passed(),
            'warnings' => $this->warnings(),
            'failures' => $this->failures(),
            'skipped' => $this->skipped(),
            'checks' => array_map(static fn ($result): array => $result->toArray(), $this->results),
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    private function count(SearchDoctorCheckStatus $status): int
    {
        return count(array_filter($this->results, static fn ($result): bool => $result->status === $status));
    }
}

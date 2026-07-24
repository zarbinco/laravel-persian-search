<?php

namespace Zarbinco\PersianSearch\Dependencies;

use Countable;
use IteratorAggregate;
use Traversable;
use UnexpectedValueException;
use Zarbinco\PersianSearch\Exceptions\InvalidSearchDependencyConfigurationException;
use Zarbinco\PersianSearch\Exceptions\SearchDependencyFanoutExceededException;
use Zarbinco\PersianSearch\Exceptions\SearchDependencyTargetConflictException;
use Zarbinco\PersianSearch\Lifecycle\SearchSourceLocator;

/** @implements IteratorAggregate<int, SearchSourceLocator> */
final readonly class SearchDependencyTargetCollection implements Countable, IteratorAggregate
{
    /** @var list<SearchSourceLocator> */
    private array $targets;

    /** @param iterable<mixed> $targets */
    public function __construct(iterable $targets, int $maximum)
    {
        if ($maximum < 1 || $maximum > SearchDependencyPolicy::HARD_MAXIMUM_SOURCES_PER_EVENT) {
            throw InvalidSearchDependencyConfigurationException::forKey(
                'maximum_sources_per_event',
                'an integer from 1 through '.SearchDependencyPolicy::HARD_MAXIMUM_SOURCES_PER_EVENT,
                $maximum,
            );
        }

        $distinct = [];
        $completeFingerprints = [];

        foreach ($targets as $target) {
            if (! $target instanceof SearchSourceLocator) {
                throw new UnexpectedValueException('Search dependency resolvers must yield SearchSourceLocator instances.');
            }

            $routingFingerprint = $target->fingerprint();
            $completeFingerprint = $target->synchronization()->fingerprint();

            if (isset($distinct[$routingFingerprint])) {
                if (! hash_equals($completeFingerprints[$routingFingerprint], $completeFingerprint)) {
                    throw SearchDependencyTargetConflictException::forLocators(
                        $distinct[$routingFingerprint],
                        $target,
                    );
                }

                continue;
            }

            $distinct[$routingFingerprint] = $target;
            $completeFingerprints[$routingFingerprint] = $completeFingerprint;

            if (count($distinct) > $maximum) {
                throw SearchDependencyFanoutExceededException::forLimit($maximum);
            }
        }

        ksort($distinct, SORT_STRING);
        $this->targets = array_values($distinct);
    }

    public static function merge(self $before, self $after, int $maximum): self
    {
        return new self((static function () use ($before, $after): iterable {
            yield from $before;
            yield from $after;
        })(), $maximum);
    }

    public function count(): int
    {
        return count($this->targets);
    }

    public function getIterator(): Traversable
    {
        yield from $this->targets;
    }

    /** @return list<string> */
    public function toArray(): array
    {
        return array_map(
            static fn (SearchSourceLocator $target): string => $target->fingerprint(),
            $this->targets,
        );
    }
}

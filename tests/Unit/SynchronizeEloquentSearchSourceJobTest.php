<?php

namespace Zarbinco\PersianSearch\Tests\Unit;

use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\TestCase;
use Zarbinco\PersianSearch\Jobs\SynchronizeEloquentSearchSourceJob;
use Zarbinco\PersianSearch\Lifecycle\EloquentSearchSourceLocator;
use Zarbinco\PersianSearch\Lifecycle\SearchLifecycleSynchronization;
use Zarbinco\PersianSearch\Lifecycle\SearchQueuePolicy;
use Zarbinco\PersianSearch\Providers\SearchSourceReference;

final class SynchronizeEloquentSearchSourceJobTest extends TestCase
{
    public function test_job_uses_deterministic_locator_uniqueness_and_policy_values(): void
    {
        $synchronization = new SearchLifecycleSynchronization(
            new EloquentSearchSourceLocator(JobLocatorModel::class, 'source', 'id', '00123'),
            new SearchSourceReference('job:00123', 'job-source', '00123'),
        );
        $policy = new SearchQueuePolicy(null, null, 4, [2, 4], 70, 500);
        $job = new SynchronizeEloquentSearchSourceJob($synchronization, $policy);

        $this->assertInstanceOf(ShouldBeUniqueUntilProcessing::class, $job);
        $this->assertSame($job->uniqueId(), (new SynchronizeEloquentSearchSourceJob($synchronization, $policy))->uniqueId());
        $this->assertSame([2, 4], $job->backoff());
        $this->assertSame(4, $job->tries);
        $this->assertSame(70, $job->timeout);
        $this->assertSame(500, $job->uniqueFor);
    }
}

final class JobLocatorModel extends Model {}

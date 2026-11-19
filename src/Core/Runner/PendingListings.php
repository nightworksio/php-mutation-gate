<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use function array_values;

use Closure;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Test\TestListing;

/**
 * What a runner lists over several sets of suites, in their order, listing
 * while the gate goes on, taken once the gate needs it; stopped where the
 * gate needs it no more.
 *
 * @internal
 */
final readonly class PendingListings
{
    /**
     * @param Closure(): list<TestListing|CannotJudge> $taking   what each set of suites listed, waited for
     * @param Closure(): void                          $stopping every listing still running stopped
     */
    private function __construct(private Closure $taking, private Closure $stopping)
    {
    }

    /**
     * @param Closure(): list<TestListing|CannotJudge> $taking   what each set of suites listed, waited for
     * @param Closure(): void                          $stopping every listing still running stopped
     */
    public static function taking(Closure $taking, Closure $stopping): self
    {
        return new self($taking, $stopping);
    }

    /** What each set of suites listed already. */
    public static function listed(TestListing|CannotJudge ...$listed): self
    {
        $all = array_values($listed);

        return new self(static fn(): array => $all, static function (): void {
        });
    }

    /**
     * What each set of suites listed, in their order, or why the runner lists
     * nothing over one, once every listing has ended.
     *
     * @return list<TestListing|CannotJudge>
     */
    public function taken(): array
    {
        return ($this->taking)();
    }

    /** Every listing still running stopped, with every process it started. */
    public function stop(): void
    {
        ($this->stopping)();
    }
}

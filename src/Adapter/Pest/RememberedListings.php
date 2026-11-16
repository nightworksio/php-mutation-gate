<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_key_exists;
use function array_values;

use Closure;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\PendingListings;
use NightWorksIO\MutationGate\Core\Test\Suites;
use NightWorksIO\MutationGate\Core\Test\TestListing;

/**
 * The tests and groups each set of a Pest runner's suites lists, each listed
 * once for its key, what is withheld and the digest of the test files where
 * `watch` sets one: alone, or started side by side with others and
 * taken by whoever asks for it first. Listings stopped before they are taken
 * are forgotten, and listed anew where asked for after.
 *
 * @internal
 */
final class RememberedListings
{
    /** @var array<string, array<string, TestListing|CannotJudge>> by the suites listed, then what it withheld */
    private array $listings = [];

    /** @var array<string, array<string, int>> each untaken listing's start, by its suites, then what it withheld */
    private array $pending = [];

    /**
     * @var array<int, array{PendingListings, list<string>, string}> each start not yet taken, by its number: its
     *                                                               listings, the suites of each, and what they
     *                                                               withheld
     */
    private array $starts = [];

    private int $started = 0;

    /** @param Closure(): (TestListing|CannotJudge) $listing */
    public function listing(string $key, Suites $suites, Closure $listing): TestListing|CannotJudge
    {
        $among = $suites->joined();
        $this->settle($among, $key);

        if (! $this->has($this->listings, $among, $key)) {
            $this->listings[$among][$key] = $listing();
        }

        return $this->listings[$among][$key];
    }

    /**
     * What each set of suites lists: those neither listed nor started yet
     * started together, and each taken once asked for. Stopped, the listings
     * it started are stopped and forgotten.
     *
     * @param Closure(Suites ...): PendingListings $starting
     */
    public function listings(string $key, Closure $starting, Suites ...$suites): PendingListings
    {
        $missing = $this->missing($key, ...$suites);
        $start = $missing === [] ? NotGiven::value() : $this->start($key, $starting, ...array_values($missing));

        return PendingListings::taking(
            fn(): array => $this->listed($key, $starting, ...$suites),
            function () use ($start): void {
                $this->forget($start);
            },
        );
    }

    /**
     * Every listing taken forgotten, so each set of suites asked after lists
     * anew; those started and not yet taken are taken by the next ask.
     */
    public function forgetListed(): void
    {
        $this->listings = [];
    }

    /**
     * Listings started over these sets of suites, by the number of their start.
     *
     * @param Closure(Suites ...): PendingListings $starting
     */
    private function start(string $key, Closure $starting, Suites ...$suites): int
    {
        $start = $this->started++;
        $joined = [];

        foreach ($suites as $among) {
            $joined[] = $among->joined();
            $this->pending[$among->joined()][$key] = $start;
        }

        $this->starts[$start] = [$starting(...$suites), $joined, $key];

        return $start;
    }

    /**
     * Each of these sets of suites neither listed nor started yet with what
     * was withheld, by the suites.
     *
     * @return array<string, Suites>
     */
    private function missing(string $key, Suites ...$suites): array
    {
        $missing = [];

        foreach ($suites as $among) {
            $joined = $among->joined();
            $known = $this->has($this->listings, $joined, $key) || $this->has($this->pending, $joined, $key);
            $missing += $known ? [] : [$joined => $among];
        }

        return $missing;
    }

    /**
     * What each set of suites listed with what was withheld, in their order:
     * each started one taken, and one whose listing was stopped since listed
     * now.
     *
     * @param  Closure(Suites ...): PendingListings $starting
     * @return list<TestListing|CannotJudge>
     */
    private function listed(string $key, Closure $starting, Suites ...$suites): array
    {
        $listed = [];

        foreach ($suites as $among) {
            $joined = $among->joined();
            $this->settle($joined, $key);

            if (! $this->has($this->listings, $joined, $key)) {
                $this->listings[$joined][$key] = $starting($among)->taken()[0];
            }

            $listed[] = $this->listings[$joined][$key];
        }

        return $listed;
    }

    /** The listings of a start stopped, and each set of suites they list forgotten as started; none where none was. */
    private function forget(int|NotGiven $start): void
    {
        if (! $start instanceof NotGiven && array_key_exists($start, $this->starts)) {
            [$listings, $joined, $key] = $this->starts[$start];
            $listings->stop();
            $this->unstart($start, $joined, $key);
        }
    }

    /** What the start of a set of suites' listing listed, each of its listings kept as listed, where one is pending. */
    private function settle(string $among, string $key): void
    {
        if (! $this->has($this->pending, $among, $key)) {
            return;
        }

        $start = $this->pending[$among][$key];
        [$listings, $joined] = $this->starts[$start];

        foreach ($listings->taken() as $at => $listed) {
            $this->listings[$joined[$at]][$key] = $listed;
        }

        $this->unstart($start, $joined, $key);
    }

    /**
     * A start, and each set of suites it lists, forgotten as pending.
     *
     * @param list<string> $joined
     */
    private function unstart(int $start, array $joined, string $key): void
    {
        foreach ($joined as $among) {
            unset($this->pending[$among][$key]);
        }

        unset($this->starts[$start]);
    }

    /**
     * Whether a map by the suites, then what was withheld, holds an entry for both.
     *
     * @param array<string, array<string, TestListing|CannotJudge|int>> $map
     */
    private function has(array $map, string $among, string $key): bool
    {
        return array_key_exists($among, $map) && array_key_exists($key, $map[$among]);
    }
}

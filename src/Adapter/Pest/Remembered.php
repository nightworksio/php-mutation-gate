<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_filter;
use function array_key_exists;
use function array_map;
use function array_unique;
use function array_values;

use Closure;

use function in_array;

use NightWorksIO\MutationGate\Adapter\Pest\Unexecutable\Selector;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Control\ControlEnd;
use NightWorksIO\MutationGate\Core\Control\ControlRun;
use NightWorksIO\MutationGate\Core\Control\ControlRuns;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Runner\Platform;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Test\Suites;
use NightWorksIO\MutationGate\Core\Test\TestListing;

/**
 * What one Pest runner learns once for every run it starts in a process: the
 * tests and groups each set of its suites lists, the PHP it starts, whether
 * the vendor is patched, each map another job handed over, the maps it has
 * written again for Pest, whether each control of a kill passes on the
 * unmutated code, served as its mutant was (see Control), and what each
 * replay of a kill's own run said (see PrefixReplays). Each is slow to
 * learn, from a process started for it or a map that can reach hundreds of
 * megabytes, and none changes while the gate runs.
 *
 * @internal
 */
final class Remembered
{
    /** @var array<string, array<string, TestListing|CannotJudge>> by the suites listed, then what it withheld */
    private array $listings = [];

    /** @var array<string, Platform|CannotJudge> by what the PHP described withheld */
    private array $platforms = [];

    /** @var list<bool> whether pest-plugin-mutate is patched, once asked */
    private array $patched = [];

    /** @var array<string, CoverageMap|CannotJudge> by the directory it was handed over in */
    private array $maps = [];

    /** @var array<string, true> the files a map has been written to */
    private array $written = [];

    /** @var array<string, ControlRun> how each set of test files ended on the unmutated code, passed or failed */
    private array $baselines = [];

    /** @var array<string, ReplayVerdict> what each replay of a kill's own run, unmutated, said, by its key */
    private array $replays = [];

    /** @var array<string, Selector> each scan of the codebase for the tests that read a value, by what it read */
    private array $selectors = [];

    /** @param Closure(): (TestListing|CannotJudge) $listing */
    public function listing(Withheld $withheld, Suites $suites, Closure $listing): TestListing|CannotJudge
    {
        $among = $suites->joined();
        $key = $withheld->pattern();

        if (! array_key_exists($among, $this->listings) || ! array_key_exists($key, $this->listings[$among])) {
            $this->listings[$among][$key] = $listing();
        }

        return $this->listings[$among][$key];
    }

    /** @param Closure(): (Platform|CannotJudge) $describing */
    public function platform(Withheld $withheld, Closure $describing): Platform|CannotJudge
    {
        $key = $withheld->pattern();

        if (! array_key_exists($key, $this->platforms)) {
            $this->platforms[$key] = $describing();
        }

        return $this->platforms[$key];
    }

    /** @param Closure(): bool $checking */
    public function patched(Closure $checking): bool
    {
        if ($this->patched === []) {
            $this->patched = [$checking()];
        }

        return $this->patched[0];
    }

    /** @param Closure(): (CoverageMap|CannotJudge) $reading */
    public function map(Path $directory, Closure $reading): CoverageMap|CannotJudge
    {
        $key = $directory->value();

        if (! array_key_exists($key, $this->maps)) {
            $this->maps[$key] = $reading();
        }

        return $this->maps[$key];
    }

    /**
     * The scan of the codebase that reads a whole map for the files one run
     * mutates, made once: the run's values are read before it runs, and its
     * trials read the same scan after it.
     *
     * @param Closure(): Selector $scanning
     */
    public function selector(string $key, Closure $scanning): Selector
    {
        if (! array_key_exists($key, $this->selectors)) {
            $this->selectors[$key] = $scanning();
        }

        return $this->selectors[$key];
    }

    /**
     * Writes a file once: a map written again for Pest is the same map every
     * time, so later runs find it where the first left it.
     *
     * @param Closure(): void $writing
     */
    public function writeOnce(string $file, Closure $writing): void
    {
        if (! array_key_exists($file, $this->written)) {
            $writing();
            $this->written[$file] = true;
        }
    }

    /**
     * How each baseline run ended, by its key, in the order given: the keys
     * not yet kept run once each, all in one call. A run that passed or
     * failed is kept; one stopped at its deadline, or out of memory, says
     * nothing of the tests and is not kept, and one never started never ran.
     *
     * @param  list<string>                                            $keys
     * @param  Closure(non-empty-list<string>): array<int, ControlRun> $running how the runs of these keys ended,
     *                                                                   by their position; none for one never started
     * @return list<ControlRun>
     */
    public function baselines(array $keys, Closure $running): array
    {
        $unknown = array_values(array_unique(array_filter(
            $keys,
            fn(string $key): bool => ! array_key_exists($key, $this->baselines),
        )));
        $ended = $unknown === [] ? [] : $running($unknown);
        $found = [];

        foreach ($ended as $at => $run) {
            $found[$unknown[$at]] = $run;
            $this->baselines += in_array($run->end(), [ControlEnd::Passed, ControlEnd::Failed], strict: true)
                ? [$unknown[$at] => $run]
                : [];
        }

        return array_map(
            fn(string $key): ControlRun => match (true) {
                array_key_exists($key, $this->baselines) => $this->baselines[$key],
                array_key_exists($key, $found) => $found[$key],
                default => ControlRun::unrun(ControlRuns::NOT_RUN),
            },
            $keys,
        );
    }

    /**
     * What each replay of a kill's own run said, by its key, in the order
     * given: the keys not yet kept run once each, all in one call; a replay
     * that had no time says nothing of the kill, and is not kept.
     *
     * @param  list<string>                                         $keys
     * @param  Closure(non-empty-list<string>): list<ReplayVerdict> $running what these keys' replays said, in order
     * @return list<ReplayVerdict>
     */
    public function replays(array $keys, Closure $running): array
    {
        $unknown = array_values(array_unique(array_filter(
            $keys,
            fn(string $key): bool => ! array_key_exists($key, $this->replays),
        )));
        $said = $unknown === [] ? [] : $running($unknown);

        foreach ($said as $at => $verdict) {
            $this->replays += $verdict === ReplayVerdict::NoTime ? [] : [$unknown[$at] => $verdict];
        }

        return array_map(
            fn(string $key): ReplayVerdict => array_key_exists($key, $this->replays)
                ? $this->replays[$key]
                : ReplayVerdict::NoTime,
            $keys,
        );
    }
}

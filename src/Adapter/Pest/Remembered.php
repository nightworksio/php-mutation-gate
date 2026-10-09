<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_filter;
use function array_key_exists;
use function array_map;
use function array_unique;
use function array_values;

use Closure;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Runner\Platform;
use NightWorksIO\MutationGate\Core\Runner\ProcessEnds;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Test\Groups;

/**
 * What one Pest runner learns once for every run it starts in a process: the
 * groups its suite lists, the PHP it starts, whether the vendor is patched,
 * each map another job handed over, the maps it has written again for Pest,
 * whether each control of a kill passes on the unmutated code, served as
 * its mutant was (see Control), and what each replay of a kill's own run
 * said (see PrefixReplays). Each is slow to learn, from a process started
 * for it or a map that can reach hundreds of megabytes, and none changes
 * while the gate runs.
 */
final class Remembered
{
    /** @var array<string, Groups|CannotJudge> by what the listing withheld */
    private array $groups = [];

    /** @var array<string, Platform|CannotJudge> by what the PHP described withheld */
    private array $platforms = [];

    /** @var list<bool> whether pest-plugin-mutate is patched, once asked */
    private array $patched = [];

    /** @var array<string, CoverageMap|CannotJudge> by the directory it was handed over in */
    private array $maps = [];

    /** @var array<string, true> the files a map has been written to */
    private array $written = [];

    /** @var array<string, bool> whether each set of test files passes on the unmutated code, loaded alone */
    private array $baselines = [];

    /** @var array<string, ReplayVerdict> what each replay of a kill's own run, unmutated, said, by its key */
    private array $replays = [];

    /** @param Closure(): (Groups|CannotJudge) $listing */
    public function groups(Withheld $withheld, Closure $listing): Groups|CannotJudge
    {
        $key = $withheld->pattern();

        if (! array_key_exists($key, $this->groups)) {
            $this->groups[$key] = $listing();
        }

        return $this->groups[$key];
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
     * Whether each baseline run passed, by its key, in the order given: the
     * keys not yet kept run once each, all in one call; a run stopped at its
     * deadline, or never started, says nothing of the tests, is not kept, and
     * does not pass.
     *
     * @param  list<string>                              $keys
     * @param  Closure(non-empty-list<string>): ProcessEnds $running the ends of the runs of these keys, in their order
     * @return list<bool>
     */
    public function baselines(array $keys, Closure $running): array
    {
        $unknown = array_values(array_unique(array_filter(
            $keys,
            fn(string $key): bool => ! array_key_exists($key, $this->baselines),
        )));
        $ends = $unknown === [] ? [] : [...$running($unknown)];

        foreach ($ends as $at => $ran) {
            $this->baselines += $ran->wasStopped() ? [] : [$unknown[$at] => $ran->succeeded()];
        }

        return array_map(
            fn(string $key): bool => array_key_exists($key, $this->baselines) && $this->baselines[$key],
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

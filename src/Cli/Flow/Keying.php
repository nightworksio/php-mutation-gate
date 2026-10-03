<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\Analysis\AnalyserIdentity;
use NightWorksIO\MutationGate\Core\Analysis\NoAnalyser;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Key;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Proof\Digests;
use NightWorksIO\MutationGate\Core\Proof\Key\CiDefinition;
use NightWorksIO\MutationGate\Core\Proof\Key\CiDefinitions;
use NightWorksIO\MutationGate\Core\Proof\Key\ContentKeys;
use NightWorksIO\MutationGate\Core\Proof\Key\Exceptions;
use NightWorksIO\MutationGate\Core\Proof\Key\Ignored;
use NightWorksIO\MutationGate\Core\Proof\Key\Judging as UnitJudging;
use NightWorksIO\MutationGate\Core\Proof\Key\Source;
use NightWorksIO\MutationGate\Core\Proof\Key\Tests;
use NightWorksIO\MutationGate\Core\Proof\Keys;
use NightWorksIO\MutationGate\Core\Runner\Identity;
use NightWorksIO\MutationGate\Core\Unit\Units;

/**
 * The content key of every unit a run considers: what the gate, its config,
 * the runner, what is installed and every file outside the tests say, and of
 * the tests, what can judge the unit.
 */
final readonly class Keying
{
    private function __construct(private ContentKeys $keys, private Adapters $adapters, private CoverageMap $map)
    {
    }

    public static function of(
        Adapters $adapters,
        Settings $settings,
        Setup $setup,
        Suite $suite,
        CoverageMap $map,
    ): self|CannotJudge {
        $identity = $adapters->runner->identity($adapters->withheld);
        $definitions = self::definitions($adapters);

        foreach ([$identity, $definitions] as $read) {
            if ($read instanceof CannotJudge) {
                return $read;
            }
        }

        return $identity instanceof Identity && $definitions instanceof CiDefinitions
            ? new self(
                ContentKeys::of(
                    $setup->gate,
                    $settings->canonical(),
                    $identity,
                    self::analyser($adapters),
                    $setup->installed,
                    Source::of($suite->outside(), $definitions, self::exceptions($adapters, $settings, $setup)),
                    Tests::of(
                        $suite->files(),
                        self::known($adapters, $map),
                        Paths::of(...self::canaries($adapters, $suite), ...self::declaring($adapters, $suite)),
                    ),
                ),
                $adapters,
                $map,
            )
            : CannotJudge::because('The content key could not be built.');
    }

    /** The base every key of the run is built on. */
    public function base(): Digest
    {
        return $this->keys->base();
    }

    /** The digests of the run's inputs a proof of one of these units records its share of. */
    public function digestsOf(Units $units): Digests
    {
        return $this->keys->digestsOf($units);
    }

    /** The key of each unit, from the test files the runner says can judge it. */
    public function keysOf(Units $units): Keys
    {
        $judging = [];

        foreach ($units as $unit) {
            $judges = $unit->isHeld() ? Paths::none() : $this->adapters->runner->judges($unit->path(), $this->map);
            $judging[] = UnitJudging::of($unit, $judges);
        }

        return $this->keys->keysOf($this->map, ...$judging);
    }

    /** What no key holds: the config file, the baseline, `proofs.ignore`, and every file the gate writes. */
    public static function exceptions(Adapters $adapters, Settings $settings, Setup $setup): Exceptions
    {
        $exceptions = Exceptions::of(
            $setup->configFile,
            $settings->floors()->baseline(),
            Ignored::of(...$settings->proofs()->ignore()),
            $adapters->runner->definitions(),
        );
        $store = self::storePath($settings);
        $exceptions = $store instanceof Path ? $exceptions->andWritten($store) : $exceptions;

        foreach ($settings->reports() as $report) {
            $written = $report->path();
            $exceptions = $written instanceof Path ? $exceptions->andWritten($written) : $exceptions;
        }

        return $exceptions;
    }

    /**
     * The analyser that checks the run's mutants, which joins key item 4
     * (ADR-0020, decision 14); none where none is chosen, or where the one
     * chosen cannot say what it is, since it then checks nothing.
     */
    private static function analyser(Adapters $adapters): AnalyserIdentity|NoAnalyser
    {
        return $adapters->analyser instanceof CannotJudge ? NoAnalyser::configured() : $adapters->analyser;
    }

    /** Each CI definition that runs the gate, as it runs. */
    private static function definitions(Adapters $adapters): CiDefinitions|CannotJudge
    {
        $definitions = CiDefinitions::none();

        foreach ($adapters->ci->definitions() as $path) {
            $contents = $adapters->project->read($path);

            if ($contents instanceof CannotJudge) {
                return $contents;
            }

            $definitions = $contents instanceof Contents
                ? $definitions->with(CiDefinition::at($path, $contents))
                : $definitions;
        }

        return $definitions;
    }

    /** The directory store's `path`, where the config sets one. */
    private static function storePath(Settings $settings): Path|Absent
    {
        $path = $settings->proofs()->store()->options()->path(Key::of('path'));

        return $path instanceof Path ? $path : Absent::setting();
    }

    /** The test files of the groups every key reads, as the runner says, since each shard opens on them. */
    private static function canaries(Adapters $adapters, Suite $suite): Paths
    {
        $files = Paths::none();

        foreach ($adapters->runner->behaviour()->readByEveryKey() as $group) {
            $files = Paths::of(...$files, ...$suite->naming($group));
        }

        return $files;
    }

    /**
     * The test files that declare a registered mutator the config turns on,
     * which every key reads, as it does the files that define the runner
     * (ADR-0021, decision 7).
     */
    private static function declaring(Adapters $adapters, Suite $suite): Paths
    {
        return $suite->files()->declaring(...$adapters->mutators->classes());
    }

    /** Every test file the coverage map knows, as the runner names the files that judge each covered file. */
    private static function known(Adapters $adapters, CoverageMap $map): Paths
    {
        $known = Paths::none();

        foreach ($map->files() as $file) {
            $judges = $adapters->runner->judges($file, $map);

            foreach ($judges instanceof Paths ? $judges : Paths::none() as $test) {
                $known = $known->with($test);
            }
        }

        return $known;
    }
}

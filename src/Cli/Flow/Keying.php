<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Adapter\Pest\Pest;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
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
        $identity = $adapters->runner->identity();
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
                    $setup->installed,
                    Source::of($suite->outside(), $definitions, self::exceptions($adapters, $settings, $setup)),
                    Tests::of(
                        $suite->files(),
                        self::known($adapters, $map),
                        self::canaries($settings, $identity, $suite),
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
            Ignored::globs(...[...$settings->proofs()->ignore()]),
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
        $path = Node::decode($settings->proofs()->store()->options()->line())->field('path');

        try {
            return $path->isPresent() ? Path::of($path->text()) : Absent::setting();
        } catch (NotInShape) {
            return Absent::setting();
        }
    }

    /**
     * The canary group's test files, which every key reads where Pest runs
     * with the gate's patch, since each shard opens on them; none otherwise.
     */
    private static function canaries(Settings $settings, Identity $runner, Suite $suite): Paths
    {
        $pest = $settings->pest();

        return $runner->runner() === Pest::RUNNER && $pest->patch() ? $suite->naming($pest->canary()) : Paths::none();
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

<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function array_map;
use function file_get_contents;
use function is_file;

use NightWorksIO\MutationGate\Cli\Flow\DecidingConfig;
use NightWorksIO\MutationGate\Cli\Flow\Setup;
use NightWorksIO\MutationGate\Config\Equivalence;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Order\Ordering;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\ShardResult;
use NightWorksIO\MutationGate\Core\Plan\ShardResultFile;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Runner\Version;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Verdict\Doomed;
use NightWorksIO\MutationGate\Tests\Fakes\TreeSourceFake;

use function sprintf;

/** Clocks, plans and readings of shard results the tests of Running set up. */
final readonly class RunningCases
{
    /** A setup whose clock moves on three seconds each time the run reads it. */
    public static function ticking(): Setup
    {
        return new Setup(
            Absent::setting(),
            Version::of('nightworksio/mutation-gate', '1.0.0', 'gate'),
            Digest::sha256Of('installed'),
            new TickingClock('2026-09-30T12:00:00+00:00', 3),
            new PeakMemoryFake(NotGiven::value()),
            DecidingConfig::unread(),
        );
    }

    /** A setup whose clock moves on this many seconds each time the run reads it. */
    public static function tickingBy(int $step): Setup
    {
        return new Setup(
            Absent::setting(),
            Version::of('nightworksio/mutation-gate', '1.0.0', 'gate'),
            Digest::sha256Of('installed'),
            new TickingClock('2026-09-30T12:00:00+00:00', $step),
            new PeakMemoryFake(NotGiven::value()),
            DecidingConfig::unread(),
        );
    }

    /** @return list<string> the paths of the units a result says its budget ran out before */
    public static function unjudged(ShardResult|CannotJudge $result): array
    {
        return $result instanceof ShardResult
        ? array_map(static fn(Unit $unit): string => $unit->path()->value(), [...$result->unjudged()])
        : [];
    }

    /** The result a shard left in a project, as the verdict reads it. */
    public static function resultIn(string $project, int $shard): ShardResult|CannotJudge
    {
        $file = sprintf('%s/.mutation-gate/results/%d.json', $project, $shard);

        return ShardResultFile::decode(is_file($file) ? (string) file_get_contents($file) : '');
    }

    /** @return list<string> each mutant's native id and status */
    public static function statuses(ShardResult|CannotJudge $result): array
    {
        return $result instanceof ShardResult
        && $result->outcome() instanceof MutationResult
        ? array_map(
            static fn(Mutant $mutant): string => sprintf('%s %s', $mutant->nativeId(), $mutant->status()->value),
            [...$result->outcome()->mutants()],
        )
        : [];
    }

    /** @return list<string> the ids flaky in a result */
    public static function flaky(ShardResult|CannotJudge $result): array
    {
        return $result instanceof ShardResult
        ? array_map(static fn(MutantId $id): string => $id->value(), [...$result->flaky()])
        : [];
    }

    /** @return list<Ordering> the order each invocation asked its tests in */
    public static function orderings(ScriptedRunner $runner): array
    {
        return array_map(
            static fn(MutationRequest $request): Ordering => $request->search()->ordering(),
            $runner->requests(),
        );
    }

    /** Settings under which no static analysis can clear a survivor after its tests, so a pull request's shard can stop. */
    public static function doomable(): Settings
    {
        return Flows::settings(Equivalence::notProvenStatically());
    }

    public static function onPullRequest(Plan $plan): Plan
    {
        return $plan->on(RunOn::at(Scope::pullRequest(7), Scope::branch('main')));
    }

    /** The project's one tree, `src`, held to this floor. */
    public static function floored(Floor $floor): TreeSourceFake
    {
        return new TreeSourceFake(
            Trees::of(Tree::at(Path::of('src'), $floor, Package::at(Path::root()))),
        );
    }

    /** @return list<list<string>> the files each invocation was asked to mutate */
    public static function asked(ScriptedRunner $runner): array
    {
        return array_map(
            static fn(MutationRequest $request): array => array_map(static fn(Path $file): string => $file->value(), [...$request->files()]),
            $runner->requests(),
        );
    }

    /** @return array{string, string, string, int, string}|string what a result's doom names, or that there is none */
    public static function doomOf(ShardResult|CannotJudge $result): array|string
    {
        return $result instanceof ShardResult && $result->doomed() instanceof Doomed
        ? [
            $result->doomed()->unit()->value(),
            $result->doomed()->mutant()->value(),
            $result->doomed()->tree()->value(),
            $result->doomed()->floor()->hundredths(),
            $result->doomed()->by()->value,
        ]
        : 'undoomed';
    }

    /** The fixture's survivor of src/Money.php, by the gate's id. */
    public static function moneySurvivor(): string
    {
        foreach (Flows::mutantsOf('src/Money.php') as $mutant) {
            if ($mutant->status() === MutantStatus::Survived) {
                return $mutant->id()->value();
            }
        }

        return 'none';
    }
}

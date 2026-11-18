<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function array_filter;
use function array_map;
use function array_values;

use Closure;

use function file_get_contents;
use function file_put_contents;
use function in_array;

use NightWorksIO\MutationGate\Cli\Flow\Running;
use NightWorksIO\MutationGate\Cli\Flow\Workspace;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\MutantIds;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\OutOfTime;
use NightWorksIO\MutationGate\Core\Plan\LeftMutant;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\ShardResult;
use NightWorksIO\MutationGate\Core\Plan\ShardResultFile;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use RuntimeException;

use function sprintf;

/** A fixture's run of a plan, the money shard's result rewritten as one stopped at its deadline (ADR-0190). */
final readonly class LeftAtTheDeadline
{
    /** The money shard's mutants it leaves, by their native ids. */
    public const array LEFT = ['GreaterThan-16', 'Minus-21'];

    /**
     * A project whose two shards ran, the money shard's result rewritten as
     * one stopped before the mutants LEFT names; with the plan, and the
     * money shard's result as it judged every mutant.
     *
     * @return array{string, Plan, ShardResult}
     */
    public static function run(): array
    {
        return self::runOf(Planned::twoShards());
    }

    /**
     * The same, of a plan whose first shard holds the money file.
     *
     * @return array{string, Plan, ShardResult}
     */
    public static function runOf(Plan $shards): array
    {
        $project = Flows::project();
        $plan = Planned::handedIn($project, $shards);
        new Running(Flows::adapters($project, [], ScriptedRunner::fixture()), Flows::settings(), Flows::setup())
            ->runAll($plan, Workspace::results());
        $file = sprintf('%s/%s/1.json', $project, Workspace::results()->value());
        $whole = ShardResultFile::decode((string) file_get_contents($file));
        $whole = $whole instanceof ShardResult ? $whole : throw new RuntimeException('the fixture left no result');
        $stopped = static fn(Mutant $mutant): Mutant => in_array($mutant->nativeId(), self::LEFT, strict: true)
            ? $mutant->unjudged(OutOfTime::BeforeRunning)
            : $mutant;
        file_put_contents($file, ShardResultFile::encode(self::resultOf($whole, $stopped)));

        return [$project, $plan, $whole];
    }

    /**
     * A result of the money shard, each of its whole result's mutants as this gives it, those it gives none dropped.
     *
     * @param Closure(Mutant): ?Mutant $each
     */
    public static function resultOf(ShardResult $whole, Closure $each): ShardResult
    {
        return ShardResult::of(
            $whole->plan(),
            $whole->shard(),
            $whole->units(),
            MutationResult::skippingNone(Mutants::of(...array_values(array_filter(
                array_map($each, self::mutantsOf($whole)),
                static fn(?Mutant $mutant): bool => $mutant instanceof Mutant,
            )))),
            $whole->measured(),
        );
    }

    /** The ids of the whole result's mutants with these native ids. */
    public static function ids(ShardResult $whole, string ...$natives): MutantIds
    {
        return MutantIds::of(...array_map(
            static fn(Mutant $mutant): MutantId => $mutant->id(),
            array_values(array_filter(
                self::mutantsOf($whole),
                static fn(Mutant $mutant): bool => in_array($mutant->nativeId(), $natives, strict: true),
            )),
        ));
    }

    /**
     * The whole result's mutants with these native ids, as a share lists them.
     *
     * @return list<LeftMutant>
     */
    public static function listed(ShardResult $whole, string ...$natives): array
    {
        return array_map(
            static fn(Mutant $mutant): LeftMutant => LeftMutant::of(
                $mutant->id(),
                $mutant->nativeId(),
                $mutant->location()->file(),
            ),
            array_values(array_filter(
                self::mutantsOf($whole),
                static fn(Mutant $mutant): bool => in_array($mutant->nativeId(), $natives, strict: true),
            )),
        );
    }

    /** @return list<Mutant> */
    public static function mutantsOf(ShardResult $whole): array
    {
        $outcome = $whole->outcome();

        return $outcome instanceof MutationResult ? [...$outcome->mutants()] : [];
    }
}

<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function array_filter;
use function array_map;
use function is_array;

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Core\Analysis\SurvivorChecks;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Cost\JobTimes;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\MutantIds;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\OutOfTime;
use NightWorksIO\MutationGate\Core\Plan\LeftoverShare;
use NightWorksIO\MutationGate\Core\Plan\LeftoverShares;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Plan\ShardResult;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Verdict\Warning;
use NightWorksIO\MutationGate\Core\Verdict\Warnings;

use function sprintf;

/**
 * Each shard's result with the mutants it left at its deadline judged by the
 * jobs of the second wave (ADR-0190): each share's result in place of those
 * it lists, where no share's result holds a mutant its share does not list,
 * no mutant is listed twice and the shares list exactly the mutants the
 * shard left; what each share's job warned of and its static checks came
 * to join the shard's. A share whose job left no result, or a listed mutant
 * its result lacks, stays as the shard left it: unjudged. A share whose
 * runner crashed leaves each mutant it lists unjudged for the crash, and
 * warns of it; the other shares' results stand (ADR-0330). Without shares,
 * or with shares cut from another plan, each result stands as its shard
 * left it.
 *
 * @internal
 */
final readonly class LeftoverParts
{
    private const string OTHER_MUTANTS = <<<'SAID'
        The result of share %d of the mutants shard %d left holds other mutants than the share lists,
        so it cannot stand for them.
        Judge each share's mutants alone, with the shares the run cut.
        SAID;

    private const string NOT_THE_LEFT = <<<'SAID'
        The shares of the mutants shard %d left list other mutants than it left, so they cannot stand for them.
        Cut the shares from the results the shards of this run left.
        SAID;

    private const string NO_SHARES = <<<'SAID'
        No shares of the mutants the shards left are at %s, so no share of them can be judged.
        Cut them with `mutation-gate leftovers` after the shards, and hand them to each job of the second wave.
        SAID;

    private const string NO_SHARE = <<<'SAID'
        The shares cut from this plan list no share %d of the mutants shard %d left, so it cannot be judged.
        Run each job of the second wave for a share the matrix `mutation-gate leftovers` printed names.
        SAID;

    /**
     * @param  list<array{Shard, ShardResult, MutationResult}>            $read each shard that left a result, with it
     * @return list<array{Shard, ShardResult, MutationResult}>|CannotJudge
     */
    public static function over(Plan $plan, array $read, Path $directory, Directory $project): array|CannotJudge
    {
        $file = $project->read(self::file($directory));
        $shares = $file instanceof Contents
            ? LeftoverShares::decode($file->text())
            : LeftoverShares::cut($plan->digest());

        return match (true) {
            $shares instanceof CannotJudge => $shares,
            ! $shares->cutFrom($plan->digest()) => $read,
            default => self::judgedAll($plan, $read, $shares, $directory, $project),
        };
    }

    /**
     * The share of the mutants this shard left with this number, as the
     * shares in this directory of results list it; or why none can be read.
     */
    public static function share(
        Plan $plan,
        ShardId $shard,
        int $number,
        Path $directory,
        Directory $project,
    ): LeftoverShare|CannotJudge {
        $file = $project->read(self::file($directory));
        $shares = match (true) {
            $file instanceof Contents => LeftoverShares::decode($file->text()),
            $file instanceof CannotJudge => $file,
            default => CannotJudge::because(sprintf(self::NO_SHARES, self::file($directory)->value())),
        };

        if ($shares instanceof CannotJudge) {
            return $shares;
        }

        foreach ($shares->cutFrom($plan->digest()) ? $shares->ofShard($shard) : [] as $share) {
            if ($share->number() === $number) {
                return $share;
            }
        }

        return CannotJudge::because(sprintf(self::NO_SHARE, $number, $shard->number()));
    }

    /** Where the shares of the mutants the shards left at their deadline stand, in a directory of results. */
    public static function file(Path $results): Path
    {
        return Path::of(sprintf('%s/leftovers.json', $results->value()));
    }

    /** Where the job of the second wave that judged a share of a shard's left mutants leaves its result. */
    public static function resultOf(Path $results, LeftoverShare $share): Path
    {
        return Path::of(sprintf(
            '%s/%d.leftover-%d.json',
            $results->value(),
            $share->shard()->number(),
            $share->number(),
        ));
    }

    /**
     * @param  list<array{Shard, ShardResult, MutationResult}>            $read
     * @return list<array{Shard, ShardResult, MutationResult}>|CannotJudge
     */
    private static function judgedAll(
        Plan $plan,
        array $read,
        LeftoverShares $shares,
        Path $directory,
        Directory $project,
    ): array|CannotJudge {
        $over = [];

        foreach ($read as $one) {
            $judged = self::judged($plan, $one, $shares->ofShard($one[0]->id()), $directory, $project);

            if ($judged instanceof CannotJudge) {
                return $judged;
            }

            $over[] = $judged;
        }

        return $over;
    }

    /**
     * A shard's result with its shares' results in place of the mutants they list.
     *
     * @param  array{Shard, ShardResult, MutationResult}             $read
     * @param  list<LeftoverShare>                                   $shares
     * @return array{Shard, ShardResult, MutationResult}|CannotJudge
     */
    private static function judged(
        Plan $plan,
        array $read,
        array $shares,
        Path $directory,
        Directory $project,
    ): array|CannotJudge {
        [$shard, $result, $mutated] = $read;

        if ($shares !== [] && ! self::listsTheLeft($shares, $mutated)) {
            return CannotJudge::because(sprintf(self::NOT_THE_LEFT, $shard->id()->number()));
        }

        $judged = self::sharesOf($plan, [$shard, $mutated], $shares, $directory, $project);

        return $judged instanceof CannotJudge ? $judged : [
            $shard,
            $result->withFlaky($result->flaky()->and($judged[1]))
                ->withWarnings($result->warnings()->and($judged[2]))
                ->withChecks($result->checks()->plus($judged[3]))
                ->withShares(...$judged[4]),
            $mutated->withMutants($mutated->mutants()->replacing($judged[0])),
        ];
    }

    /**
     * Whether these shares list exactly the mutants this result left, each once.
     *
     * @param list<LeftoverShare> $shares
     */
    private static function listsTheLeft(array $shares, MutationResult $mutated): bool
    {
        $listed = MutantIds::none();
        $count = 0;

        foreach ($shares as $share) {
            $listed = $listed->and($share->mutants());
            $count += $share->mutants()->count();
        }

        return $listed->count() === $count && self::same($listed, self::leftIn($mutated));
    }

    /**
     * The mutants the shares' results judged, those they found flaky, what
     * they warned of, what their static checks came to and what their jobs
     * measured of their time; none of a share whose job left no result; each
     * a crashed share lists unjudged for the crash, with a warning of it.
     *
     * @param  array{Shard, MutationResult}                                                $left a shard, and its left
     * @param  list<LeftoverShare>                                                         $shares
     * @return array{Mutants, MutantIds, Warnings, SurvivorChecks, list<JobTimes>}|CannotJudge
     */
    private static function sharesOf(
        Plan $plan,
        array $left,
        array $shares,
        Path $directory,
        Directory $project,
    ): array|CannotJudge {
        [$shard, $mutated] = $left;
        $judged = [Mutants::none(), MutantIds::none(), Warnings::none(), SurvivorChecks::none(), []];

        foreach ($shares as $share) {
            $read = LeftResults::at($plan, $shard, self::resultOf($directory, $share), $project);
            $one = self::shareResult($shard, $share, $read);

            if ($one instanceof CannotJudge) {
                return $one;
            }

            $judged = match (true) {
                $one instanceof Path => $judged,
                $one instanceof Crashed => self::crashedInto($judged, $one->ofShare($shard, $share, $mutated)),
                default => [
                    Mutants::of(...$judged[0], ...$one[1]->mutants()),
                    $judged[1]->and($one[0]->flaky()),
                    $judged[2]->and($one[0]->warnings()),
                    $judged[3]->plus($one[0]->checks()),
                    [...$judged[4], $one[0]->measured()->job()],
                ],
            };
        }

        return $judged;
    }

    /**
     * What the shares judged so far, with a crashed share's mutants unjudged and its warning.
     *
     * @param  array{Mutants, MutantIds, Warnings, SurvivorChecks, list<JobTimes>} $judged
     * @param  array{Mutants, Warning}                                             $crashed
     * @return array{Mutants, MutantIds, Warnings, SurvivorChecks, list<JobTimes>}
     */
    private static function crashedInto(array $judged, array $crashed): array
    {
        return [
            Mutants::of(...$judged[0], ...$crashed[0]),
            $judged[1],
            $judged[2]->with($crashed[1]),
            $judged[3],
            $judged[4],
        ];
    }

    /**
     * One share's result, or the file its job left none at, or that its runner crashed; or why it cannot stand.
     *
     * @param  array{ShardResult, MutationResult}|CannotJudge|Crashed|Path $left
     * @return array{ShardResult, MutationResult}|CannotJudge|Crashed|Path
     */
    private static function shareResult(
        Shard $shard,
        LeftoverShare $share,
        array|CannotJudge|Crashed|Path $left,
    ): array|CannotJudge|Crashed|Path {
        return is_array($left) && self::idsOf($left[1]->mutants())->without($share->mutants())->count() > 0
            ? CannotJudge::because(sprintf(self::OTHER_MUTANTS, $share->number(), $shard->id()->number()))
            : $left;
    }

    /** The mutants a result left at its deadline. */
    private static function leftIn(MutationResult $mutated): MutantIds
    {
        return self::idsOf(Mutants::of(...array_filter([...$mutated->mutants()], OutOfTime::left(...))));
    }

    private static function idsOf(Mutants $mutants): MutantIds
    {
        return MutantIds::of(...array_map(static fn(Mutant $mutant): MutantId => $mutant->id(), [...$mutants]));
    }

    private static function same(MutantIds $some, MutantIds $others): bool
    {
        return $some->without($others)->count() === 0 && $others->without($some)->count() === 0;
    }
}

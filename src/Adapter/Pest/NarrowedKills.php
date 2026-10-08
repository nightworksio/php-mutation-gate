<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_key_exists;
use function array_keys;
use function array_map;
use function array_slice;
use function array_values;

use Closure;

use function count;
use function explode;
use function implode;
use function is_array;
use function is_int;
use function is_string;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Prefix;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;

/**
 * The kills of a run in which each mutant's own run loaded only the test
 * files its covering tests need, as far as its records can vouch for them. A
 * kill is doubtful where no test is named as the killer, where only tests
 * that errored are, or where the records cannot be read. Any other kill of a
 * narrowed own run stands only where its control passes: the tests of the
 * files it loaded, loaded alone, with the file it changes served unmutated
 * through Pest's override as the mutant's copy was (see Control). A file left
 * out can hold what a test needs without a name, such as a hook
 * `pest()->in()` registers or state its loading sets, and a test can fail
 * only while the override serves a file; either would pass as a killer.
 * Mutants that share Pest's id share their own run, and so its doubt. A
 * kill of a mutant Pest left uncovered is the trial's (ADR-0004, decision 8),
 * which ran no own process of Pest's and first ran its tests alone on the
 * unmutated code, served as its mutant is, so it is never doubted.
 */
final readonly class NarrowedKills
{
    /** Where the file a narrowed kill changes and the files its run loaded are joined into the key it is kept by. */
    private const string BETWEEN = "\n";

    /**
     * @param list<Mutant>                $doubtful
     * @param array<string, list<Mutant>> $narrowed the kills of narrowed own runs whose order is not known, by the
     *                                              file each changes and the files its run loaded, joined
     * @param array<string, PrefixReplay> $replays  the replay of each narrowed own run whose order is known, by the
     *                                              file its mutant changes and the key of its order, joined
     * @param array<string, list<Mutant>> $replayed the kills each replay vouches for, by the same key
     */
    private function __construct(
        private array $doubtful,
        private array $narrowed,
        private array $replays = [],
        private array $replayed = [],
    ) {
    }

    public static function in(MutationResult $result, string $results, Project $project): self
    {
        $records = Records::in($results);
        $runs = self::runs($records);
        $trial = self::uncovered($records);
        $doubtful = [];
        $narrowed = [];
        $replays = [];
        $replayed = [];

        foreach ($result->mutants() as $mutant) {
            if ($mutant->status() !== MutantStatus::Killed || array_key_exists($mutant->nativeId(), $trial)) {
                continue;
            }

            $recorded = array_key_exists($mutant->nativeId(), $runs);
            [$run, $copy, $limit] = $recorded
                ? $runs[$mutant->nativeId()]
                : [
                    OwnRun::of([], [], [], NotGiven::value(), preloaded: false, tests: NotGiven::value()),
                    '',
                    Unmeasured::duration(),
                ];

            if (! $recorded || count($mutant->killers()) === 0 || $run->killedByErrorsOnly()) {
                $doubtful[] = $mutant;

                continue;
            }

            if ($run->narrowedTo() === []) {
                continue;
            }

            $replay = self::replayOf($mutant, $run, $copy, $limit, $project);

            if (! $replay instanceof PrefixReplay) {
                $set = implode(self::BETWEEN, [$mutant->location()->file()->value(), ...$run->narrowedTo()]);
                $narrowed[$set][] = $mutant;

                continue;
            }

            $key = implode(self::BETWEEN, [$mutant->location()->file()->value(), $replay->key()]);
            $replays[$key] = array_key_exists($key, $replays) ? $replays[$key]->alsoFor($limit) : $replay;
            $replayed[$key][] = $mutant;
        }

        return new self($doubtful, $narrowed, $replays, $replayed);
    }

    /**
     * How many controls the narrowed kills ask for: one for each file changed
     * and set of files loaded, and one replay for each file changed and order.
     */
    public function sets(): int
    {
        return count($this->narrowed) + count($this->replays);
    }

    /**
     * Each narrowed kill whose replay does not let it stand, unjudged with
     * why (see ReplayVerdict), all the replays asked at once, so they can run
     * side by side.
     *
     * @param Closure(non-empty-list<PrefixReplay>): list<ReplayVerdict> $replay what each replay says, in order
     */
    public function unvouched(Closure $replay): Mutants
    {
        $keys = array_keys($this->replays);
        $replays = array_values($this->replays);
        $said = $replays === [] ? [] : $replay($replays);
        $unvouched = [];

        foreach ($keys as $at => $key) {
            $verdict = array_key_exists($at, $said) ? $said[$at] : ReplayVerdict::NoTime;
            $reason = Reason::that($verdict->reason());
            $unvouched = $verdict === ReplayVerdict::Stands ? $unvouched : [...$unvouched, ...array_map(
                static fn(Mutant $mutant): Mutant => Interpretation::unjudged($mutant, $reason),
                $this->replayed[$key],
            )];
        }

        return Mutants::of(...$unvouched);
    }

    /**
     * The doubtful kills, and each narrowed kill whose control fails: the
     * tests of the files its run loaded, judged as the run was, with the file
     * it changes served unmutated, all asked at once, so the runs that answer
     * can run side by side.
     *
     * @param Closure(non-empty-list<Control>): list<bool> $pass whether each control passes, in the order given
     */
    public function doubted(WholeSuite|Group|Filter $judgedBy, Closure $pass): Mutants
    {
        $doubted = $this->doubtful;
        $keys = array_keys($this->narrowed);
        $passed = $keys === [] ? [] : $pass(array_map(
            static function (string $key) use ($judgedBy): Control {
                $parts = explode(self::BETWEEN, $key);

                return Control::of(
                    Path::of($parts[0]),
                    Paths::of(...array_map(Path::of(...), array_slice($parts, 1))),
                    $judgedBy,
                );
            },
            $keys,
        ));

        foreach ($keys as $at => $key) {
            $stands = array_key_exists($at, $passed) && $passed[$at];
            $doubted = $stands ? $doubted : [...$doubted, ...$this->narrowed[$key]];
        }

        return Mutants::of(...$doubted);
    }

    /** @return array<string, true> each native id Pest left uncovered, so that only the trial judges it */
    private static function uncovered(Records|CannotJudge $records): array
    {
        if (! $records instanceof Records) {
            return [];
        }

        $uncovered = [];

        foreach ($records->planned() as $planned) {
            if ($records->statusOf($planned) === PestStatus::Uncovered) {
                $uncovered[$planned->id()] = true;
            }
        }

        return $uncovered;
    }

    /**
     * The replay of a kill's narrowed own run, where its order is known: the
     * key of that order, how far it went, and the arguments it started with.
     */
    private static function replayOf(
        Mutant $mutant,
        OwnRun $run,
        string $copy,
        Seconds|Unmeasured $limit,
        Project $project,
    ): PrefixReplay|NotGiven {
        $files = Paths::of(...array_map($project->relative(...), $run->narrowedTo()));
        $prefix = $run->prefix($files);
        $key = $prefix instanceof Prefix ? $prefix->key() : NotGiven::value();
        $reach = $run->reach();
        $arguments = $run->arguments();

        return is_string($key) && is_int($reach) && $reach > 0 && is_array($arguments) && $arguments !== []
            ? PrefixReplay::of($mutant->location()->file(), $copy, $arguments, $files, $reach, $key, $limit)
            : NotGiven::value();
    }

    /**
     * @return array<string, array{OwnRun, string, Seconds|Unmeasured}> what each mutant's own run recorded, its
     *                                                                   copy, and the seconds Pest allowed it, by
     *                                                                   native id
     */
    private static function runs(Records|CannotJudge $records): array
    {
        if (! $records instanceof Records) {
            return [];
        }

        $runs = [];

        foreach ($records->planned() as $planned) {
            $runs[$planned->id()] = [
                $records->runOf($planned),
                $planned->mutated()->value(),
                $records->limitOf($planned),
            ];
        }

        return $runs;
    }
}

<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_key_exists;
use function array_keys;
use function array_map;
use function array_slice;

use Closure;

use function count;
use function explode;
use function implode;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;

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
     * @param array<string, list<Mutant>> $narrowed the kills of narrowed own runs, by the file each changes and the
     *                                              files its run loaded, joined
     */
    private function __construct(private array $doubtful, private array $narrowed)
    {
    }

    public static function in(MutationResult $result, string $results): self
    {
        $records = Records::in($results);
        $runs = self::runs($records);
        $trial = self::uncovered($records);
        $doubtful = [];
        $narrowed = [];

        foreach ($result->mutants() as $mutant) {
            if ($mutant->status() !== MutantStatus::Killed || array_key_exists($mutant->nativeId(), $trial)) {
                continue;
            }

            $recorded = array_key_exists($mutant->nativeId(), $runs);
            $run = $recorded
                ? $runs[$mutant->nativeId()]
                : OwnRun::of([], [], [], NotGiven::value(), preloaded: false, tests: NotGiven::value());

            if (! $recorded || count($mutant->killers()) === 0 || $run->killedByErrorsOnly()) {
                $doubtful[] = $mutant;

                continue;
            }

            if ($run->narrowedTo() !== []) {
                $key = implode(self::BETWEEN, [$mutant->location()->file()->value(), ...$run->narrowedTo()]);
                $narrowed[$key][] = $mutant;
            }
        }

        return new self($doubtful, $narrowed);
    }

    /** How many controls the narrowed kills ask for: one for each file changed and set of files loaded. */
    public function sets(): int
    {
        return count($this->narrowed);
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

    /** @return array<string, OwnRun> what each mutant's own process recorded, by its native id */
    private static function runs(Records|CannotJudge $records): array
    {
        if (! $records instanceof Records) {
            return [];
        }

        $runs = [];

        foreach ($records->planned() as $planned) {
            $runs[$planned->id()] = $records->runOf($planned);
        }

        return $runs;
    }
}

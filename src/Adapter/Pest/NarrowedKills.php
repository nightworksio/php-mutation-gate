<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_key_exists;

use Closure;

use function count;
use function explode;
use function implode;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;

/**
 * The kills of a run in which each mutant's own run loaded only the test
 * files its covering tests need, as far as its records can vouch for them. A
 * kill is doubtful where no test is named as the killer, where only tests
 * that errored are, or where the records cannot be read. Any other kill of a
 * narrowed own run stands only where the tests of the files it loaded pass
 * on the unmutated code, loaded alone: a file left out can hold what a test
 * needs without a name, such as a hook `pest()->in()` registers or state its
 * loading sets, and a test that fails for want of it would pass as a killer.
 * Mutants that share Pest's id share their own run, and so its doubt. A
 * kill of a mutant Pest left uncovered is the trial's (ADR-0004, decision 8),
 * which ran no own process of Pest's and first ran its tests alone on the
 * unmutated code, so it is never doubted.
 */
final readonly class NarrowedKills
{
    /** Where a narrowed own run's files are joined into the key its kills are kept by. */
    private const string BETWEEN = "\n";

    /**
     * @param list<Mutant>                $doubtful
     * @param array<string, list<Mutant>> $narrowed the kills of narrowed own runs, by the files they loaded, joined
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
            $run = $recorded ? $runs[$mutant->nativeId()] : OwnRun::of([], [], [], NotGiven::value());

            if (! $recorded || count($mutant->killers()) === 0 || $run->killedByErrorsOnly()) {
                $doubtful[] = $mutant;

                continue;
            }

            if ($run->narrowedTo() !== []) {
                $narrowed[implode(self::BETWEEN, $run->narrowedTo())][] = $mutant;
            }
        }

        return new self($doubtful, $narrowed);
    }

    /**
     * The doubtful kills, and each narrowed kill whose files' tests do not
     * pass on the unmutated code, as asked once for each set of files.
     *
     * @param Closure(list<string>): bool $pass whether the tests of these files, by their paths on disk, pass
     */
    public function doubted(Closure $pass): Mutants
    {
        $doubted = $this->doubtful;

        foreach ($this->narrowed as $files => $mutants) {
            $doubted = $pass(explode(self::BETWEEN, $files)) ? $doubted : [...$doubted, ...$mutants];
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

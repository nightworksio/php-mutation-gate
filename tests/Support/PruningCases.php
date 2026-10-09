<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Mutant\ProvedKills;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Proof\Digests;
use NightWorksIO\MutationGate\Core\Proof\Inputs;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Pruning\MutatorNames;
use NightWorksIO\MutationGate\Core\Pruning\MutatorWindow;
use NightWorksIO\MutationGate\Core\Pruning\Outcome;
use NightWorksIO\MutationGate\Core\Pruning\Pruned;
use NightWorksIO\MutationGate\Core\Pruning\PruningAccount;
use NightWorksIO\MutationGate\Core\Pruning\Survival;
use NightWorksIO\MutationGate\Core\Pruning\Window;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Verdict\Origin;
use NightWorksIO\MutationGate\Core\Verdict\UnitResult;
use NightWorksIO\MutationGate\Core\Verdict\UnitResults;

use function sprintf;
use function str_repeat;

/** Mutator windows, mutants and results as the pruning tests write them. */
final class PruningCases
{
    /** A mutator's window as a ledger wrote it: a `0` for each kill and a `1` for each mutant let through. */
    public static function window(string $mutator, string $outcomes, string|NotGiven $last = new NotGiven()): MutatorWindow
    {
        $window = MutatorWindow::written($mutator, $outcomes, $last);

        return $window instanceof MutatorWindow ? $window : MutatorWindow::of($mutator);
    }

    /** What a ledger learned where `pest` ran two killed mutants of each mutator named. */
    public static function clean(string ...$mutators): Survival
    {
        $outcomes = [];

        foreach ($mutators as $mutator) {
            $outcomes = [...$outcomes, Outcome::killed($mutator, sprintf('%s-1', $mutator)), Outcome::killed($mutator, sprintf('%s-2', $mutator))];
        }

        return Survival::none()->after(Name::of('pest'), Window::of(2), ...$outcomes);
    }

    /** A mutant of a mutator on a line of a file, with this status, and how long it ran where that was measured. */
    public static function mutant(
        string $file,
        string $mutator,
        int $line,
        MutantStatus $status = MutantStatus::Killed,
        float|NotGiven $took = new NotGiven(),
    ): Mutant {
        $path = Path::of($file);

        return Mutant::of(
            MutantId::hash($path, $mutator, sprintf('-%d', $line), 0),
            sprintf('%s-%d', $mutator, $line),
            Location::of($path, Line::of($line), Line::of($line)),
            Mutation::of($mutator, MutatorFamily::Arithmetic, sprintf('-%d', $line)),
            $status,
            $took instanceof NotGiven ? Unmeasured::duration() : Seconds::of($took),
        );
    }

    /**
     * What `pest` pruned in a run over a window of two: `Minus` and `Plus`,
     * `Plus` having once let `Plus-9` through, in `src/A.php`, carrying
     * `Plus-1` (30s) and `Minus-2` (90s) beside the `Equal-3` it ran, and an
     * unmeasured `Plus-4`; and nothing in `src/B.php`.
     */
    public static function account(): PruningAccount
    {
        $a = UnitResult::of(Unit::file(Path::of('src/A.php')), Origin::Run, Mutants::of(self::mutant('src/A.php', 'Equal', 3, took: 600.0)))
            ->carryingPruned(Mutants::of(
                self::mutant('src/A.php', 'Plus', 1, took: 30.0),
                self::mutant('src/A.php', 'Minus', 2, took: 90.0),
                self::mutant('src/A.php', 'Plus', 4),
            ), ProvedKills::none());
        $b = UnitResult::of(Unit::file(Path::of('src/B.php')), Origin::Run, Mutants::of(self::mutant('src/B.php', 'Equal', 3, took: 60.0)));
        $survival = self::clean('Minus')->after(
            Name::of('pest'),
            Window::of(2),
            Outcome::through('Plus', 'Plus-9'),
            Outcome::killed('Plus', 'Plus-10'),
            Outcome::killed('Plus', 'Plus-11'),
        );

        return PruningAccount::of(
            Pruned::of(MutatorNames::of('Plus', 'Minus'), Paths::of(Path::of('src/A.php'))),
            $survival,
            Name::of('pest'),
            Window::of(2),
            Seconds::days(7),
            UnitResults::of($a, $b),
        );
    }

    /** A full result of a unit, made at this instant, of the code these digests name. */
    public static function proof(string $unit, string $at, string $source, Mutants $mutants, string $mutation = 'mutation'): Proof
    {
        return Proof::of(
            Digest::of(sprintf('%s-%s', $unit, $at)),
            Path::of($unit),
            $mutants,
            Run::of('main', Moment::at($at), Digest::of(str_repeat('b', 64))),
        )->withInputs(Inputs::of(Digest::sha256Of($source), Digest::sha256Of($mutation)));
    }

    /** The digests of the code on disk: each unit's source as named, and the mutant set's. */
    public static function now(string ...$sources): Digests
    {
        $digests = Digests::of(Digest::sha256Of('mutation'));

        foreach ($sources as $unit => $source) {
            $digests = $digests->withSource(Path::of((string) $unit), Digest::sha256Of($source));
        }

        return $digests;
    }
}

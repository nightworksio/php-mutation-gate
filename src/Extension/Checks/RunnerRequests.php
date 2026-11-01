<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Extension\Checks;

use function array_map;
use function array_unique;
use function array_values;
use function dirname;
use function implode;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Runner\Narrowing;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Extension\LibraryChange;
use NightWorksIO\MutationGate\Extension\RunnerLibrary;
use NightWorksIO\MutationGate\Port\Runner;

use function sort;
use function sprintf;

/**
 * What a runner reports for the files and tests a request names: only the
 * files asked for, less those left out; each file alike alone and beside
 * another; and a held file judged by its group alone.
 *
 * @internal the runner contract's own
 */
final readonly class RunnerRequests
{
    private function __construct(private Runner $runner, private RunnerLibrary $library)
    {
    }

    /** @return list<string> */
    public static function failures(Runner $runner, RunnerLibrary $library): array
    {
        $requests = new self($runner, $library);

        return [...$requests->asked(), ...$requests->chunked(), ...$requests->held()];
    }

    /** @return list<string> */
    private function asked(): array
    {
        $money = LibraryChange::Adds->file();
        $held = LibraryChange::Held->file();
        $request = $this->request(Path::of(dirname($money->value())));
        $everything = $this->files($this->runner->mutate($request));
        $leftOut = $this->files($this->runner->mutate($request->leavingOut(Paths::of($held))));
        $both = [$held->value(), $money->value()];

        return [
            ...Records::unless(
                $everything === $both,
                sprintf(
                    'It reports mutants of %s for a request of src/, where the library holds %s.',
                    implode(', ', $everything),
                    implode(', ', $both),
                ),
            ),
            ...Records::unless(
                $leftOut === [$money->value()],
                sprintf(
                    'It reports mutants of %s for a request of src/ less %s.',
                    implode(', ', $leftOut),
                    $held->value(),
                ),
            ),
        ];
    }

    /** @return list<string> */
    private function chunked(): array
    {
        $money = LibraryChange::Adds->file();
        $held = LibraryChange::Held->file();
        $together = Records::of($this->runner->mutate($this->request($money, $held)));
        $moneyAlone = Records::of($this->runner->mutate($this->request($money)));
        $heldAlone = Records::of($this->runner->mutate($this->request($held)));
        $apart = [...$moneyAlone, ...$heldAlone];
        sort($apart);

        return [
            ...Records::unless(
                $moneyAlone !== [] && $heldAlone !== [],
                sprintf(
                    'It reports no mutant of %s or %s, alone, which each hold one.',
                    $money->value(),
                    $held->value(),
                ),
            ),
            ...Records::unless(
                $together === $apart,
                sprintf(
                    'It reports %s for %s and %s together, and %s for each alone.',
                    implode('; ', $together),
                    $money->value(),
                    $held->value(),
                    implode('; ', $apart),
                ),
            ),
        ];
    }

    /** @return list<string> */
    private function held(): array
    {
        $file = LibraryChange::Held->file();
        $mutators = $this->library->mutators(LibraryChange::Held);
        $request = MutationRequest::of(Paths::of($file), Group::holding($file->value()))
            ->narrowedTo(Paths::of($file), Narrowing::none()->toMutators($mutators));
        $reported = Records::of($this->runner->mutate($request));
        $expected = Records::expected($this->library, LibraryChange::Held);

        return Records::unless(
            $reported === $expected,
            sprintf(
                'It reports %s for %s judged by its group alone, where its group lets it survive: %s.',
                implode('; ', $reported),
                $file->value(),
                implode('; ', $expected),
            ),
        );
    }

    /** A request of these files, judged by the whole suite, with the mutator of `LibraryChange::Adds` alone. */
    private function request(Path $file, Path ...$more): MutationRequest
    {
        $files = Paths::of($file, ...$more);

        return MutationRequest::of($files, WholeSuite::tests())
            ->narrowedTo($files, Narrowing::none()->toMutators($this->library->mutators(LibraryChange::Adds)));
    }

    /**
     * The files a run reported mutants of, in order; or why it could not be judged.
     *
     * @return list<string>
     */
    private function files(MutationResult|CannotJudge $result): array
    {
        if ($result instanceof CannotJudge) {
            return Records::of($result);
        }

        $files = array_values(array_unique(array_map(
            static fn(Mutant $mutant): string => $mutant->location()->file()->value(),
            [...$result->mutants()],
        )));
        sort($files);

        return $files;
    }
}

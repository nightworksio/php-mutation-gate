<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Extension\Checks;

use function array_map;
use function array_values;
use function implode;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoveredLine;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Runner\Identity;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Runner\Version;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Extension\LibraryChange;
use NightWorksIO\MutationGate\Extension\RunnerLibrary;

use function sort;
use function sprintf;

/**
 * What the runner contract reads of a runner's answers, each said as text, so
 * two answers alike are said alike and a failure can say both.
 *
 * @internal the runner contract's own
 */
final readonly class Records
{
    /** A mutant's record: its id, status, file, the lines it starts and ends on, its mutator and family. */
    private const string RECORD = '%s %s %s:%d-%d %s %s';

    /** What is said of an answer that cannot be judged. */
    private const string UNJUDGED = 'nothing it can judge (%s)';

    /** The line on which a mutant whose end is unreported ends, as a record says it. */
    private const int UNREPORTED = 0;

    /**
     * The records of a run's mutants, sorted; or why it could not be judged.
     *
     * @return list<string>
     */
    public static function of(MutationResult|CannotJudge|Mutants $result): array
    {
        return match (true) {
            $result instanceof CannotJudge => [sprintf(self::UNJUDGED, $result->why())],
            $result instanceof MutationResult => self::of($result->mutants()),
            default => self::sorted(array_map(self::record(...), [...$result])),
        };
    }

    /**
     * The records the runner must report for these changes, sorted.
     *
     * @return list<string>
     */
    public static function expected(RunnerLibrary $library, LibraryChange ...$changes): array
    {
        return self::sorted(array_map(
            static fn(LibraryChange $change): string => sprintf(
                self::RECORD,
                MutantId::firstOf($change->file(), $library->mutatorOf($change), $change->diff())->value(),
                $change->status()->value,
                $change->file()->value(),
                $change->line(),
                $library->measures() ? $change->line() : self::UNREPORTED,
                $library->mutatorOf($change),
                $change->family()->value,
            ),
            array_values($changes),
        ));
    }

    /** The mutants of a run that have this status. */
    public static function having(MutationResult|CannotJudge $result, MutantStatus $status): Mutants
    {
        $found = Mutants::none();

        foreach ($result instanceof MutationResult ? $result->mutants() : Mutants::none() as $mutant) {
            $found = $mutant->status() === $status ? $found->with($mutant) : $found;
        }

        return $found;
    }

    /**
     * Each line a map covers, by its file and number, with its tests sorted, in order; or why it could not
     * be judged.
     *
     * @return list<string>
     */
    public static function lines(CoverageMap|CannotJudge $map): array
    {
        if ($map instanceof CannotJudge) {
            return [sprintf(self::UNJUDGED, $map->why())];
        }

        return self::sorted(array_map(
            static fn(CoveredLine $line): string => sprintf(
                '%s:%d %s',
                $line->file()->value(),
                $line->line(),
                implode(',', self::sorted([...$line])),
            ),
            [...$map->lines()],
        ));
    }

    /** Paths said in order, or why they could not be judged. */
    public static function paths(Paths|CannotJudge $paths): string
    {
        return $paths instanceof CannotJudge
            ? sprintf(self::UNJUDGED, $paths->why())
            : implode(', ', self::sorted(array_map(static fn(Path $path): string => $path->value(), [...$paths])));
    }

    /** Tests said in order, or why they could not be judged. */
    public static function tests(TestIds|CannotJudge $tests): string
    {
        return $tests instanceof CannotJudge
            ? sprintf(self::UNJUDGED, $tests->why())
            : implode(', ', self::sorted(array_map(static fn(TestId $test): string => $test->value(), [...$tests])));
    }

    /** An identity said whole: the runner's name, every version it drives, and the PHP it runs on. */
    public static function identity(Identity|CannotJudge $identity): string
    {
        if ($identity instanceof CannotJudge) {
            return sprintf(self::UNJUDGED, $identity->why());
        }

        $versions = array_map(static fn(Version $version): string => $version->spelt(), [...$identity->versions()]);

        return sprintf('%s %s %s', $identity->runner(), implode(',', $versions), $identity->platform()->value());
    }

    /**
     * A failure where something does not hold, said as this sentence; none where it does.
     *
     * @return list<string>
     */
    public static function unless(bool $holds, string $failure): array
    {
        return $holds ? [] : [$failure];
    }

    /**
     * Texts in order.
     *
     * @param  list<string> $texts
     * @return list<string>
     */
    public static function sorted(array $texts): array
    {
        sort($texts);

        return $texts;
    }

    private static function record(Mutant $mutant): string
    {
        $end = $mutant->location()->end();

        return sprintf(
            self::RECORD,
            $mutant->id()->value(),
            $mutant->status()->value,
            $mutant->location()->file()->value(),
            $mutant->location()->start()->number(),
            $end instanceof Line ? $end->number() : self::UNREPORTED,
            $mutant->mutation()->mutator(),
            $mutant->mutation()->family()->value,
        );
    }
}

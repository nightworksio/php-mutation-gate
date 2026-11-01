<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Extension\Checks;

use function count;
use function implode;

use NightWorksIO\MutationGate\Core\Analysis\Checkable;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\Reproducible;
use NightWorksIO\MutationGate\Core\Runner\Reproduction;
use NightWorksIO\MutationGate\Core\Runner\Unmade;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Extension\LibraryChange;
use NightWorksIO\MutationGate\Port\Runner;

use function sprintf;
use function str_contains;
use function str_replace;

/**
 * What a runner answers of the survivor of `src/Money.php`: run again alone,
 * by the tests of its unit or by a group that holds another file; reproduced
 * the same ways, and named unmade where the run no longer makes it; and as a
 * static analyser checks it, from its file or from none.
 *
 * @internal the runner contract's own
 */
final readonly class RunnerSurvivors
{
    /** How long a survivor's run again is allowed, where the runner lays a limit. */
    private const float MOST = 60.0;

    /** The mutator a run of the library never names. */
    private const string GONE = 'gone';

    private const string GONE_FILE = 'src/Gone.php';

    private function __construct(private Runner $runner, private Mutant $survivor)
    {
    }

    /** @return list<string> */
    public static function failures(Runner $runner, Mutants $survivors): array
    {
        $only = [...$survivors];

        if (count($only) !== 1) {
            return [sprintf('It lets %d mutants of src/Money.php survive, where its tests let one.', count($only))];
        }

        $checks = new self($runner, $only[0]);

        return [...$checks->retried(), ...$checks->reproduced(), ...$checks->checkable()];
    }

    /** @return list<string> */
    private function retried(): array
    {
        $survivor = Mutants::of($this->survivor);
        $alone = $this->runner->retry($this->request(WholeSuite::tests()), $survivor, $this->most());
        $byGroup = $this->runner->retry($this->request($this->otherGroup()), $survivor, $this->most());
        $statuses = [];

        foreach ($byGroup instanceof Mutants ? $byGroup : Mutants::none() as $mutant) {
            $statuses[] = $mutant->status()->value;
        }

        return [
            ...Records::unless(
                Records::of($alone) === Records::of(Mutants::of($this->survivor)),
                sprintf('It runs its survivor again as %s.', implode('; ', Records::of($alone))),
            ),
            ...Records::unless(
                $statuses === [MutantStatus::Uncovered->value],
                sprintf(
                    'It runs its survivor again by a group that reaches none of its lines as %s.',
                    implode(', ', $statuses),
                ),
            ),
        ];
    }

    /** @return list<string> */
    private function reproduced(): array
    {
        $reproducible = Reproducible::of($this->survivor);
        $alone = $this->runner->reproduce(
            $reproducible,
            MutationRequest::of(Paths::none(), WholeSuite::tests())->withholding(Withheld::standard()),
            $this->most(),
        );
        $byGroup = $this->runner->reproduce(
            $reproducible,
            MutationRequest::of(Paths::none(), $this->otherGroup())->withholding(Withheld::standard()),
            $this->most(),
        );
        $unmade = Mutant::of(
            MutantId::hash($this->survivor->location()->file(), self::GONE, $this->survivor->mutation()->diff(), 0),
            '',
            $this->survivor->location(),
            $this->survivor->mutation(),
            MutantStatus::Survived,
            $this->survivor->duration(),
        );
        $gone = $this->runner->reproduce(
            Reproducible::of($unmade),
            MutationRequest::of(Paths::none(), WholeSuite::tests()),
            $this->most(),
        );

        return [
            ...Records::unless(
                $this->records($alone) === Records::of(Mutants::of($this->survivor)),
                sprintf('It reproduces its survivor as %s.', implode('; ', $this->records($alone))),
            ),
            ...Records::unless(
                $alone instanceof Reproduction && $alone->printed() !== '',
                'It reproduces its survivor printing nothing of the run.',
            ),
            ...Records::unless(
                $this->status($byGroup) === MutantStatus::Uncovered->value,
                sprintf(
                    'It reproduces its survivor by a group that holds another file as %s.',
                    $this->status($byGroup),
                ),
            ),
            ...Records::unless(
                $gone instanceof Reproduction && $gone->mutant() instanceof Unmade,
                'It reproduces a mutant its run no longer makes.',
            ),
        ];
    }

    /** @return list<string> */
    private function checkable(): array
    {
        $change = LibraryChange::Large;
        $checkable = $this->runner->checkable($this->survivor);
        $location = $this->survivor->location();
        $gone = Mutant::of(
            $this->survivor->id(),
            $this->survivor->nativeId(),
            Location::of(Path::of(self::GONE_FILE), $location->start(), $location->end()),
            $this->survivor->mutation(),
            $this->survivor->status(),
            $this->survivor->duration(),
        );

        if (! $checkable instanceof Checkable) {
            return [sprintf('It gives its survivor to no static analyser: %s', $checkable->why())];
        }

        $mutant = $checkable->mutant()->text();
        $original = $checkable->original();

        return [
            ...Records::unless(
                str_contains($mutant, $change->added()) && ! str_contains($mutant, $change->removed()),
                sprintf('It gives its survivor to a static analyser without its change made: %s', $mutant),
            ),
            ...Records::unless(
                ! $original instanceof Contents
                    || str_replace($change->added(), $change->removed(), $mutant) === $original->text(),
                'It gives its survivor to a static analyser with more changed than its change.',
            ),
            ...Records::unless(
                $this->runner->checkable($gone) instanceof CannotJudge,
                sprintf('It gives a static analyser a mutant of %s, which is not there.', self::GONE_FILE),
            ),
        ];
    }

    /** A request of the survivor's file, judged by these tests, as a run again is asked. */
    private function request(WholeSuite|Group $tests): MutationRequest
    {
        return MutationRequest::of(Paths::of($this->survivor->location()->file()), $tests);
    }

    /** The group that holds `src/Held.php`, whose tests reach no line of `src/Money.php`. */
    private function otherGroup(): Group
    {
        return Group::holding(LibraryChange::Held->file()->value());
    }

    private function most(): Seconds
    {
        return Seconds::of(self::MOST);
    }

    /** @return list<string> */
    private function records(Reproduction|CannotJudge $reproduced): array
    {
        return match (true) {
            $reproduced instanceof CannotJudge => Records::of($reproduced),
            $reproduced->mutant() instanceof Mutant => Records::of(Mutants::of($reproduced->mutant())),
            default => [sprintf('unmade (%s)', $reproduced->mutant()->why()->text())],
        };
    }

    private function status(Reproduction|CannotJudge $reproduced): string
    {
        return $reproduced instanceof Reproduction && $reproduced->mutant() instanceof Mutant
            ? $reproduced->mutant()->status()->value
            : implode('; ', $this->records($reproduced));
    }
}

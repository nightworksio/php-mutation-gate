<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Fakes;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
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
use NightWorksIO\MutationGate\Core\Runner\CoverageRequest;
use NightWorksIO\MutationGate\Core\Runner\Identity;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Runner\Version;
use NightWorksIO\MutationGate\Core\Runner\Versions;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\Groups;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Port\Runner;

use function sprintf;
use function str_starts_with;

/**
 * A runner that knows a library's mutants by heart and answers every request
 * from them, as a real runner answers from the code it mutates.
 */
final readonly class RunnerFake implements Runner
{
    public function __construct(
        private Identity|CannotJudge $identity,
        private Groups|CannotJudge $groups,
        private CoverageMap $map,
        private Mutants $library,
        private Paths $judges,
    ) {}

    /** The runner over the contract suite's fixture library. */
    public static function ofTheFixture(): self
    {
        $money = Path::of('src/Money.php');
        $held = Path::of('src/Held.php');

        return new self(
            Identity::of('fake', Versions::of(Version::of('fake/runner', '1.0.0', 'abc123')), Digest::of('php')),
            Groups::of(Group::named('holds:src/Held.php'), Group::named('slow')),
            CoverageMap::empty()
                ->covered($money, Line::of(10), TestId::of('MoneyTest::adds'))
                ->covered($held, Line::of(5), TestId::of('HeldTest::holds'))
                ->timed(TestId::of('MoneyTest::adds'), Seconds::of(0.2)),
            Mutants::of(
                self::mutant($money, 10, 'Plus', MutantStatus::Killed),
                self::mutant($money, 11, 'LessThan', MutantStatus::Survived),
                self::mutant($money, 20, 'Minus', MutantStatus::Uncovered),
                self::mutant($money, 30, 'While', MutantStatus::TimedOut),
                self::mutant($held, 5, 'Plus', MutantStatus::Killed),
            ),
            Paths::of(Path::of('tests/MoneyTest.php')),
        );
    }

    public function identity(): Identity|CannotJudge
    {
        return $this->identity;
    }

    public function groups(): Groups|CannotJudge
    {
        return $this->groups;
    }

    public function coverage(CoverageRequest $request): CoverageMap
    {
        return $this->map;
    }

    public function judges(Path $file, CoverageMap $map): Paths
    {
        return $map->testsCoveringFile($file)->count() > 0 ? $this->judges : Paths::none();
    }

    public function mutate(MutationRequest $request): MutationResult
    {
        $found = Mutants::none();

        foreach ($this->library as $mutant) {
            if ($this->within($mutant->location()->file(), $request->files()) && ! $this->within($mutant->location()->file(), $request->leftOut())) {
                $found = $found->with($mutant);
            }
        }

        return MutationResult::of($found, 0);
    }

    public function retry(Mutants $mutants, Seconds $limit): Mutants
    {
        $found = Mutants::none();

        foreach ($this->library as $known) {
            foreach ($mutants as $asked) {
                if ($known->id()->value() === $asked->id()->value()) {
                    $found = $found->with($known);
                }
            }
        }

        return $found;
    }

    private static function mutant(Path $file, int $line, string $mutator, MutantStatus $status): Mutant
    {
        $diff = sprintf("@@ @@\n-line %d\n+%s %d", $line, $mutator, $line);

        return Mutant::of(
            MutantId::hash($file, $mutator, $diff, 0),
            sprintf('%s-%d', $mutator, $line),
            Location::of($file, Line::of($line), Line::of($line)),
            Mutation::of($mutator, MutatorFamily::None, $diff),
            $status,
            Unmeasured::duration(),
        );
    }

    /** Whether a file is one of these paths, or inside one of them. */
    private function within(Path $file, Paths $paths): bool
    {
        foreach ($paths as $path) {
            if ($file->equals($path) || str_starts_with($file->value(), sprintf('%s/', $path->value()))) {
                return true;
            }
        }

        return false;
    }
}

<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Fakes;

use function in_array;
use function iterator_to_array;

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
use NightWorksIO\MutationGate\Core\Mutant\Mutators;
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
use NightWorksIO\MutationGate\Tests\Contract\Runner\Library;

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
    ) {
    }

    /** The runner over the contract suite's fixture library, which knows each change's mutant. */
    public static function ofTheFixture(): self
    {
        $mutants = Mutants::none();

        foreach (Library::CHANGES as $name => $change) {
            [$mutator, $family] = Library::FAKE[$name];
            $mutants = $mutants->with(self::mutant($change, $mutator, $family));
        }

        return new self(
            Identity::of('fake', Versions::of(Version::of('fake/runner', '1.0.0', 'abc123')), Digest::of('php')),
            Groups::of(Group::named('holds:src/Held.php'), Group::named(Library::CANARY)),
            CoverageMap::empty()
                ->covered(Path::of('src/Money.php'), Line::of(11), TestId::of('MoneyTest::adds'))
                ->covered(Path::of('src/Held.php'), Line::of(11), TestId::of('HeldTest::doubles'))
                ->timed(TestId::of('MoneyTest::adds'), Seconds::of(0.2)),
            $mutants,
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
            $file = $mutant->location()->file();

            $asked = $this->within($file, $request->files()) && ! $this->within($file, $request->leftOut());

            if ($asked && $this->applies($request->mutators(), $mutant)) {
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

    /** @param array{file: string, line: int, removed: string, added: string, status: MutantStatus} $change */
    private static function mutant(array $change, string $mutator, MutatorFamily $family): Mutant
    {
        $file = Path::of($change['file']);
        $line = Line::of($change['line']);
        $diff = sprintf("@@ @@\n-%s\n+%s", $change['removed'], $change['added']);

        return Mutant::of(
            MutantId::hash($file, $mutator, $diff, 0),
            sprintf('%s-%d', $mutator, $change['line']),
            Location::of($file, $line, $line),
            Mutation::of($mutator, $family, $diff),
            $change['status'],
            Unmeasured::duration(),
        );
    }

    /** Whether a request's mutators make this mutant. */
    private function applies(Mutators $mutators, Mutant $mutant): bool
    {
        $named = iterator_to_array($mutators, preserve_keys: false);

        return $mutators->isAll() || in_array($mutant->mutation()->mutator(), $named, strict: true);
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

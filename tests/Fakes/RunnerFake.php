<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Fakes;

use function in_array;
use function iterator_to_array;
use function mb_substr;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Marker;
use NightWorksIO\MutationGate\Core\Mutant\Markers;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Mutant\Mutators;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Runner\CoverageRead;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Runner\Identity;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Runner\Reproducible;
use NightWorksIO\MutationGate\Core\Runner\Reproduction;
use NightWorksIO\MutationGate\Core\Runner\RunnerBehaviour;
use NightWorksIO\MutationGate\Core\Runner\Version;
use NightWorksIO\MutationGate\Core\Runner\Versions;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\Groups;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\TestName;
use NightWorksIO\MutationGate\Core\Test\TestNames;
use NightWorksIO\MutationGate\Core\Test\TestRow;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
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
    private RunnerBehaviour $behaviour;

    public function __construct(
        private Identity|CannotJudge $identity,
        private Groups|CannotJudge $groups,
        private CoverageMap $map,
        private Mutants $library,
        private Paths $judges,
        private Paths $definitions,
        private TestNames $names,
        private Paths $packages,
    ) {
        $this->behaviour = RunnerBehaviour::standard();
    }

    /** This runner, behaving so. */
    public function behaving(RunnerBehaviour $behaviour): self
    {
        return clone($this, ['behaviour' => $behaviour]);
    }

    public function behaviour(): RunnerBehaviour
    {
        return $this->behaviour;
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
                ->covered(Path::of('src/Money.php'), Line::of(27), TestId::of('MoneyTest::adds'))
                ->covered(Path::of('src/Held.php'), Line::of(11), TestId::of('HeldTest::doubles'))
                ->timed(TestId::of('MoneyTest::adds'), Seconds::of(0.2)),
            $mutants,
            Paths::of(Path::of('tests/DrainSpec.php'), Path::of('tests/MoneySpec.php')),
            Paths::of(Path::of('tests/Pest.php'), Path::of('phpunit.xml')),
            TestNames::none()
                ->with(TestId::of('MoneyTest::adds'), TestName::in(Path::of('tests/MoneyTest.php'), 'it adds'))
                ->with(TestId::of('HeldTest::doubles'), TestName::in(Path::of('tests/HeldTest.php'), 'it doubles'))
                ->with(TestId::of('MoneyTest::adds#one'), TestRow::of(
                    TestName::in(Path::of('tests/MoneyTest.php'), 'it adds'),
                    '"one"',
                )),
            Paths::of(Path::of('fixture')),
        );
    }

    public function identity(Withheld $withheld): Identity|CannotJudge
    {
        return $this->identity;
    }

    public function groups(Withheld $withheld): Groups|CannotJudge
    {
        return $this->groups;
    }

    public function coverage(CoverageRun|CoverageRead $request): CoverageMap
    {
        return $this->map;
    }

    public function judges(Path $file, CoverageMap $map): Paths
    {
        return $map->testsCoveringFile($file)->count() > 0 ? $this->judges : Paths::none();
    }

    /** A run of no test takes a second and a half. */
    public function startUp(Path $file, Withheld $withheld): Seconds
    {
        return Seconds::of(1.5);
    }

    public function mutate(MutationRequest $request): MutationResult
    {
        $found = Mutants::none();

        foreach ($this->library as $mutant) {
            $file = $mutant->location()->file();

            $asked = $this->within($file, $request->files()) && ! $this->within($file, $request->leftOut());

            if ($asked && $this->applies($request->mutators(), $mutant)) {
                $found = $found->with($this->judged($mutant, $request->judgedBy()));
            }
        }

        return MutationResult::of($found, 0);
    }

    public function retry(MutationRequest $request, Mutants $mutants, Seconds $limit): Mutants
    {
        $judgedBy = $request->judgedBy();
        $found = Mutants::none();

        foreach ($this->library as $known) {
            foreach ($mutants as $asked) {
                if ($known->id()->value() === $asked->id()->value()) {
                    $found = $found->with($this->judged($known, $judgedBy));
                }
            }
        }

        return $found;
    }

    /** The library's mutant with the id asked for, judged by the tests given, or none where the library has none. */
    public function reproduce(Reproducible $mutant, WholeSuite|Group|Filter $judgedBy, Seconds $limit, Withheld $withheld): Reproduction
    {
        $ran = Mutants::none();

        foreach ($this->library as $known) {
            $ran = $known->id()->value() === $mutant->id()->value() ? $ran->with($this->judged($known, $judgedBy)) : $ran;
        }

        return Reproduction::among(
            $mutant->id(),
            $ran,
            Reason::that('Run again, the fake made no mutant with this id.'),
            sprintf('fake: %s with only %s', $mutant->file()->value(), $mutant->mutator()),
        );
    }

    /** The library's one marker, in `marked/Marked.php`, where the paths asked for name that file. */
    public function markers(Paths $files): Markers
    {
        return $this->within(Path::of(Library::MARKED), $files)
            ? Markers::of(Marker::of(Library::MARKER, 'fake-ignore', '{"mutant": "<id>", "reason": "<why>"}'))
            : Markers::none();
    }

    public function definitions(): Paths
    {
        return $this->definitions;
    }

    /** The names it knows of these tests. */
    public function names(TestIds $tests, Withheld $withheld): TestNames
    {
        $named = TestNames::none();

        foreach ($tests as $test) {
            $name = $this->names->nameOf($test);
            $named = $name instanceof TestId ? $named : $named->with($test, $name);
        }

        return $named;
    }

    /** The same runner in a package it knows, which answers as it does. */
    public function rootedAt(Path $package): self|CannotJudge
    {
        return $this->packages->has($package)
            ? $this
            : CannotJudge::because(sprintf('%s holds no project the fake runner can run.', $package->value()));
    }

    /** @param array{file: string, line: int, removed: string, added: string, status: MutantStatus} $change */
    private static function mutant(array $change, string $mutator, MutatorFamily $family): Mutant
    {
        $file = Path::of($change['file']);
        $line = Line::of($change['line']);
        $diff = sprintf("@@ @@\n-%s\n+%s", $change['removed'], $change['added']);

        $mutant = Mutant::of(
            MutantId::hash($file, $mutator, $diff, 0),
            sprintf('%s-%d', $mutator, $change['line']),
            Location::of($file, $line, $line),
            Mutation::of($mutator, $family, $diff),
            $change['status'],
            $change['status'] === MutantStatus::Uncovered ? Unmeasured::duration() : Seconds::of(0.1),
        );

        return $change['status'] === MutantStatus::TimedOut ? $mutant->withLimit(Seconds::of(5.0)) : $mutant;
    }

    /** A mutant as its judging tests see it: a group that does not hold its file runs no test on it. */
    private function judged(Mutant $mutant, WholeSuite|Group|Filter $judgedBy): Mutant
    {
        $held = ! $judgedBy instanceof Group
            || $this->within($mutant->location()->file(), Paths::of(Path::of(mb_substr($judgedBy->name(), 6))));

        return $held ? $mutant : Mutant::of(
            $mutant->id(),
            $mutant->nativeId(),
            $mutant->location(),
            $mutant->mutation(),
            MutantStatus::Uncovered,
            $mutant->duration(),
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

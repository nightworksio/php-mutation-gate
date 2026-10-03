<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\PhpUnit\Command;
use NightWorksIO\MutationGate\Adapter\PhpUnit\HeldCoverage;
use NightWorksIO\MutationGate\Adapter\PhpUnit\Mutating;
use NightWorksIO\MutationGate\Adapter\PhpUnit\Outcome;
use NightWorksIO\MutationGate\Adapter\PhpUnit\Project;
use NightWorksIO\MutationGate\Adapter\PhpUnit\TestFiles;
use NightWorksIO\MutationGate\Adapter\PhpUnit\Transcribing;
use NightWorksIO\MutationGate\Adapter\PhpUnit\Variable;
use NightWorksIO\MutationGate\Adapter\Runtime\CapDirectory;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMapFile;
use NightWorksIO\MutationGate\Core\File\DiskPath;
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
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\MemoryUnit;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Runner\Reproducible;
use NightWorksIO\MutationGate\Core\Runner\Reproduction;
use NightWorksIO\MutationGate\Core\Runner\Uncapped;
use NightWorksIO\MutationGate\Core\Runner\Unmade;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Mutator\Engine\Engine;
use NightWorksIO\MutationGate\Tests\Support\CoverageMaps;
use NightWorksIO\MutationGate\Tests\Support\FirstMutant;
use NightWorksIO\MutationGate\Tests\Support\Mutators\PlusToMinus;
use NightWorksIO\MutationGate\Tests\Support\PhpUnitShellFake;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** A project of two files, each with one `+`: Money, whose sum a test covers, and Tax, which nothing covers. */
function mutatingProject(): Project
{
    $root = Scratch::directory();
    Scratch::write($root, 'src/Money.php', "<?php\n\nfunction add(\$a, \$b)\n{\n    return \$a + \$b;\n}\n");
    Scratch::write($root, 'src/Tax.php', "<?php\n\nfunction tax(\$a)\n{\n    return \$a + 1;\n}\n");

    return Project::at($root, Paths::of(Path::of('tests')), Path::of('vendor'), Path::of('.mutation-gate'));
}

/**
 * A PHPUnit whose coverage run writes a map in which one test covers Money's sum, and fails where told, and whose
 * mutant runs fail that test, each noting the cap's ini its PHP scans, where it scans one.
 *
 * @param ArrayObject<int, string> $caps
 */
function mutatingPhpUnit(Project $project, ArrayObject $caps = new ArrayObject(), bool $coverageFails = false): PhpUnitShellFake
{
    return new PhpUnitShellFake(static function (Command $command) use ($project, $caps, $coverageFails): Ran {
        $mapped = array_values(array_filter($command->arguments(), static fn(string $a): bool => str_starts_with($a, '--coverage-php=')));

        if ($mapped !== []) {
            $file = substr($mapped[0], strlen('--coverage-php='));
            if ($file === '') {
                return Ran::finished(succeeded: false, output: 'No map named');
            }

            if (! is_dir(dirname($file))) {
                mkdir(dirname($file), recursive: true);
            }

            CoverageMaps::write($file, sprintf('%s/', $project->root()), ['src/Money.php' => [5 => [0]]], ['Tests\MoneyTest::testAdds'], ['Tests\MoneyTest::testAdds' => 0.5]);

            return Ran::finished(succeeded: ! $coverageFails, output: 'PHPUnit ran the suite under coverage');
        }

        $scanned = $command->scanned();
        $caps[] = $scanned instanceof DiskPath ? (string) file_get_contents($scanned->child(MemoryCap::FILE)->value()) : '';
        file_put_contents($command->environment()[Variable::Results->value], Outcome::Failed->line('Tests\MoneyTest::testAdds'));
        file_put_contents($command->environment()[Variable::Guard->value], "served\n");

        return Ran::finished(succeeded: false, output: 'PHPUnit failed a test')->took(Seconds::of(0.2));
    });
}

/** The gate's own mutants of a project, run through a shell. */
function mutating(Project $project, PhpUnitShellFake|Transcribing $shell, HeldCoverage $held = new HeldCoverage()): Mutating
{
    return new Mutating($project, $shell, new TestFiles($project), new CapDirectory(), $held, Engine::with(new PlusToMinus()));
}

/** @return list<array{string, string}> each mutant's file and status */
function statusesOf(MutationResult|Mutants|CannotJudge $result): array
{
    $mutants = $result instanceof MutationResult ? $result->mutants() : $result;

    return $mutants instanceof Mutants
        ? array_map(static fn(Mutant $mutant): array => [$mutant->location()->file()->value(), $mutant->status()->value], [...$mutants])
        : [[$result instanceof CannotJudge ? $result->why() : '', '']];
}

$whole = MutationRequest::of(Paths::of(Path::of('src')), WholeSuite::tests());

it('runs the tests that judge the request under coverage, then each covered mutant under the request\'s memory cap, removed once done', function () use ($whole): void {
    $project = mutatingProject();
    $caps = new ArrayObject();
    $shell = mutatingPhpUnit($project, $caps);
    $withheld = Withheld::of('SECRET');
    $request = $whole->withholding($withheld)->cappedAt(MemoryCap::of(64, MemoryUnit::Megabytes));
    $result = mutating($project, $shell)->result($request, Seconds::of(5.0), NotGiven::value());
    $coverage = $shell->commands()[0];

    expect(statusesOf($result))->toBe([['src/Money.php', 'killed'], ['src/Tax.php', 'uncovered']])
        ->and($shell->commands())->toHaveCount(2)
        ->and($coverage->arguments()[2] ?? '')->toBe(sprintf('--coverage-php=%s/.mutation-gate/phpunit/coverage/coverage.php', $project->root()))
        ->and($coverage->withheld())->toEqual(Withheld::standard()->and($withheld))
        ->and($coverage->scanned())->toBeInstanceOf(Uncapped::class)
        ->and($caps->getArrayCopy())->toBe(["memory_limit=64M\ndisplay_errors=stdout\n"])
        ->and(is_dir($project->own(sprintf('php/%d', getmypid()))))->toBeFalse();
});

it('runs a held unit\'s coverage by its group, where the request reuses a map of the whole suite', function (): void {
    $project = mutatingProject();
    $shell = mutatingPhpUnit($project);
    $request = MutationRequest::of(Paths::of(Path::of('src/Money.php')), Group::named('holds:src/Money.php'))
        ->reusingCoverage(Path::of('.mutation-gate/handed'));
    mutating($project, $shell)->result($request, Seconds::of(5.0), NotGiven::value());

    expect(array_slice($shell->commands()[0]->arguments(), -2))->toBe(['--group', 'holds:src/Money.php']);
});

it('reads the map another job handed on, for a request judged by the whole suite, and runs no suite under coverage', function () use ($whole): void {
    $project = mutatingProject();
    $adds = TestId::of('Tests\MoneyTest::testAdds');
    $map = CoverageMap::empty()->covered(Path::of('src/Money.php'), Line::of(5), $adds)->timed($adds, Seconds::of(0.5));
    Scratch::write($project->root(), CoverageMapFile::in(Path::of('.mutation-gate/handed'))->value(), CoverageMapFile::encode($map));
    $shell = mutatingPhpUnit($project);
    $result = mutating($project, $shell)->result($whole->reusingCoverage(Path::of('.mutation-gate/handed')), Seconds::of(5.0), NotGiven::value());

    expect(statusesOf($result))->toBe([['src/Money.php', 'killed'], ['src/Tax.php', 'uncovered']])
        ->and($shell->commands())->toHaveCount(1);
});

it('reads one map for every run of the same, and runs the suite under coverage once', function () use ($whole): void {
    $project = mutatingProject();
    $shell = mutatingPhpUnit($project);
    $held = new HeldCoverage();
    mutating($project, $shell, $held)->result($whole, Seconds::of(5.0), NotGiven::value());
    mutating($project, $shell, $held)->result($whole, Seconds::of(5.0), NotGiven::value());

    $coverageRuns = array_filter(
        $shell->commands(),
        static fn(Command $command): bool => str_starts_with($command->arguments()[2] ?? '', '--coverage-php='),
    );

    expect($coverageRuns)->toHaveCount(1);
});

it('cannot judge a request without a mutator, an override, a map or a cap to run it with', function (string $broken) use ($whole): void {
    $project = mutatingProject();
    $shell = mutatingPhpUnit($project, coverageFails: $broken === 'coverage');
    $engine = $broken === 'mutators' ? CannotJudge::because('No mutators.') : Engine::with(new PlusToMinus());
    $request = $broken === 'cap' ? $whole->cappedAt(MemoryCap::standard()) : $whole;

    if ($broken === 'cap') {
        mkdir(sprintf('%s/.mutation-gate/phpunit', $project->root()), recursive: true);
        symlink(Scratch::directory(), sprintf('%s/.mutation-gate/phpunit/php', $project->root()));
    }

    if ($broken === 'override') {
        Scratch::write($project->root(), '.mutation-gate/phpunit/override.php/inside', 'a directory where the script goes');
    }

    $mutating = new Mutating($project, $shell, new TestFiles($project), new CapDirectory(), new HeldCoverage(), $engine);
    set_error_handler(static fn(): bool => true);
    $result = $mutating->result($request, Seconds::of(5.0), NotGiven::value());
    restore_error_handler();

    expect($result)->toBeInstanceOf(CannotJudge::class)
        ->and(count($shell->commands()))->toBe($broken === 'coverage' || $broken === 'cap' ? 1 : 0);
})->with(['mutators', 'override', 'coverage', 'cap']);

it('runs mutants again over their files and mutators, making only them, and leaves one it no longer makes unjudged', function () use ($whole): void {
    $project = mutatingProject();
    $shell = mutatingPhpUnit($project);
    $held = new HeldCoverage();
    $first = mutating($project, $shell, $held)->result($whole, Seconds::of(5.0), NotGiven::value());
    $money = FirstMutant::of($first);
    $gone = Mutant::of(
        MutantId::hash(Path::of('src/Money.php'), 'acme/PlusToMinus', '-gone', 0),
        '',
        Location::of(Path::of('src/Money.php'), Line::of(5), Line::of(5)),
        $money->mutation(),
        MutantStatus::Survived,
        Unmeasured::duration(),
    );
    $again = mutating($project, $shell, $held)->again($whole, Mutants::of($money, $gone), Seconds::of(6.0));
    $last = $shell->commands()[count($shell->commands()) - 1];
    $unjudged = $again instanceof Mutants ? [...$again][1] : null;

    expect(statusesOf($again))->toBe([['src/Money.php', 'killed'], ['src/Money.php', 'unjudged']])
        ->and($unjudged?->reason())->toEqual(Reason::that(Mutating::NOT_FOUND_AGAIN))
        ->and($unjudged?->id())->toEqual($gone->id())
        ->and($last->deadline())->toEqual(Seconds::of(6.0))
        ->and($shell->commands())->toHaveCount(3);
});

it('runs nothing again where it is asked for no mutant, and cannot judge a run again that cannot run', function () use ($whole): void {
    $project = mutatingProject();
    $shell = mutatingPhpUnit($project, coverageFails: true);
    $mutant = Mutant::of(
        MutantId::hash(Path::of('src/Money.php'), 'acme/PlusToMinus', '-x', 0),
        '',
        Location::of(Path::of('src/Money.php'), Line::of(5), Line::of(5)),
        Mutation::of('acme/PlusToMinus', MutatorFamily::Arithmetic, '-x'),
        MutantStatus::Survived,
        Unmeasured::duration(),
    );

    expect(mutating($project, $shell)->again($whole, Mutants::none(), Seconds::of(5.0)))->toEqual(Mutants::none())
        ->and($shell->commands())->toBe([])
        ->and(mutating($project, $shell)->again($whole, Mutants::of($mutant), Seconds::of(5.0)))->toBeInstanceOf(CannotJudge::class);
});

it('reproduces one mutant on its own, with what was printed, and says the run made none where it no longer makes it', function () use ($whole): void {
    $project = mutatingProject();
    $shell = mutatingPhpUnit($project);
    $first = mutating($project, $shell)->result($whole, Seconds::of(5.0), NotGiven::value());
    $money = FirstMutant::of($first);
    $printing = Transcribing::over($shell);
    $reproduced = mutating($project, $printing)->reproduced(Reproducible::of($money), $whole, Seconds::of(5.0), $printing);
    $gone = Mutant::of(MutantId::hash(Path::of('src/Money.php'), 'gone', '-a', 0), '', $money->location(), $money->mutation(), MutantStatus::Survived, Unmeasured::duration());
    $unmade = mutating($project, $printing)->reproduced(Reproducible::of($gone), $whole, Seconds::of(5.0), $printing);
    $failing = mutating($project, mutatingPhpUnit($project, coverageFails: true))
        ->reproduced(Reproducible::of($money), $whole, Seconds::of(5.0), $printing);

    expect($reproduced instanceof Reproduction ? $reproduced->mutant() : $reproduced)->toBeInstanceOf(Mutant::class)
        ->and($reproduced instanceof Reproduction ? $reproduced->printed() : '')->toBe("PHPUnit ran the suite under coverage\nPHPUnit failed a test")
        ->and($unmade instanceof Reproduction ? $unmade->mutant() : $unmade)->toEqual(Unmade::because(Reason::that(Mutating::NOT_FOUND_AGAIN)))
        ->and($failing)->toBeInstanceOf(CannotJudge::class);
});

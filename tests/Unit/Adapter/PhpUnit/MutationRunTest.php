<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\PhpUnit\Command;
use NightWorksIO\MutationGate\Adapter\PhpUnit\Invocation;
use NightWorksIO\MutationGate\Adapter\PhpUnit\MutantRun;
use NightWorksIO\MutationGate\Adapter\PhpUnit\MutationRun;
use NightWorksIO\MutationGate\Adapter\PhpUnit\Project;
use NightWorksIO\MutationGate\Adapter\PhpUnit\TestFiles;
use NightWorksIO\MutationGate\Adapter\PhpUnit\Variable;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\MutantIds;
use NightWorksIO\MutationGate\Core\Mutant\Mutators;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Mutator\Engine\Engine;
use NightWorksIO\MutationGate\Tests\Support\Mutators\PlusToMinus;
use NightWorksIO\MutationGate\Tests\Support\Mutators\RemoveEcho;
use NightWorksIO\MutationGate\Tests\Support\PhpUnitScan;
use NightWorksIO\MutationGate\Tests\Support\PhpUnitShellFake;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** A library of two files: Money, whose sum a test covers, and Tax, which nothing covers. */
function library(): string
{
    $root = Scratch::directory();
    Scratch::write($root, 'src/Money.php', "<?php\n\nfunction add(\$a, \$b)\n{\n    echo 'adding';\n\n    return \$a + \$b;\n}\n");
    Scratch::write($root, 'src/Tax.php', "<?php\n\nfunction tax(\$a)\n{\n    return \$a + 1;\n}\n");
    Scratch::write($root, 'src/notes.txt', 'not PHP');

    return $root;
}

/**
 * The mutation run over a library, whose PHPUnit serves each mutant and fails every test it runs, and the shell it
 * runs PHPUnit in.
 *
 * @return array{MutationRun, PhpUnitShellFake}
 */
function killingRun(string $root): array
{
    $project = Project::at($root, Paths::of(Path::of('tests')), Path::of('vendor'), Path::of('.mutation-gate'));
    $shell = new PhpUnitShellFake(static function (Command $command): Ran {
        file_put_contents($command->environment()[Variable::Results->value], "failed Tests%5CMoneySpec%3A%3Aadds\n");
        file_put_contents($command->environment()[Variable::Guard->value], "served\n");

        return Ran::finished(succeeded: false, output: '')->took(Seconds::of(0.2));
    });
    $run = new MutationRun(
        $project,
        Engine::with(new PlusToMinus(), new RemoveEcho()),
        new MutantRun($project, $shell, new Invocation($project, '/gate/override.php'), new TestFiles($project), PhpUnitScan::uncapped($project), NotGiven::value()),
    );

    return [$run, $shell];
}

/** @return list<array{string, string, string}> each mutant's file, mutator and status */
function judgedMutants(MutationResult|CannotJudge $result): array
{
    return $result instanceof MutationResult ? array_map(
        static fn(Mutant $mutant): array => [$mutant->location()->file()->value(), $mutant->mutator(), $mutant->status()->value],
        [...$result->mutants()],
    ) : [];
}

$covered = CoverageMap::empty()
    ->covered(Path::of('src/Money.php'), Line::of(5), TestId::of('Tests\MoneySpec::echoes'))
    ->covered(Path::of('src/Money.php'), Line::of(7), TestId::of('Tests\MoneySpec::adds'));

it('makes every mutant of every PHP file a directory holds, judging each by its covering tests, and none uncovered by a run', function () use ($covered): void {
    [$run, $shell] = killingRun(library());
    $result = $run->of(MutationRequest::of(Paths::of(Path::of('src')), WholeSuite::tests()), $covered, Seconds::of(5.0));

    expect(judgedMutants($result))->toBe([
        ['src/Money.php', 'acme/RemoveEcho', 'killed'],
        ['src/Money.php', 'acme/PlusToMinus', 'killed'],
        ['src/Tax.php', 'acme/PlusToMinus', 'uncovered'],
    ])
        ->and($result instanceof MutationResult ? $result->skipped() : -1)->toBe(0)
        ->and($shell->commands())->toHaveCount(2);
});

it('allows each mutant 5 s plus five times its covering tests\' own time under the cap, and the cap where one is untimed', function () use ($covered): void {
    [$run, $shell] = killingRun(library());
    $timed = $covered->timed(TestId::of('Tests\MoneySpec::adds'), Seconds::of(1.0));
    $result = $run->of(MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests()), $timed, Seconds::of(30.0));

    expect(judgedMutants($result))->toBe([
        ['src/Money.php', 'acme/RemoveEcho', 'killed'],
        ['src/Money.php', 'acme/PlusToMinus', 'killed'],
    ])
        ->and(array_map(static fn(Command $command): mixed => $command->deadline(), $shell->commands()))
        ->toEqual([Seconds::of(30.0), Seconds::of(10.0)]);
});

it('makes only the mutators asked for, of the files not left out', function () use ($covered): void {
    [$run] = killingRun(library());
    $request = MutationRequest::of(Paths::of(Path::of('src')), WholeSuite::tests())
        ->narrowedTo(Paths::of(Path::of('src')), Mutators::named('acme/PlusToMinus'))
        ->leavingOut(Paths::of(Path::of('src/Tax.php')));

    expect(judgedMutants($run->of($request, $covered, Seconds::of(5.0))))->toBe([['src/Money.php', 'acme/PlusToMinus', 'killed']]);
});

it('skips with no record every mutant past the request\'s deadline', function () use ($covered): void {
    [$run, $shell] = killingRun(library());
    $request = MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests())->within(Seconds::of(0.0));
    $result = $run->of($request, $covered, Seconds::of(5.0));

    expect(judgedMutants($result))->toBe([])
        ->and($result instanceof MutationResult ? $result->skipped() : -1)->toBe(2)
        ->and($shell->commands())->toBe([]);
});

it('cannot judge a request with a file that does not parse, or a mutant whose files it cannot write', function () use ($covered): void {
    $root = library();
    Scratch::write($root, 'src/Broken.php', "<?php\n\nfunction (\n");
    [$run] = killingRun($root);
    $broken = $run->of(MutationRequest::of(Paths::of(Path::of('src/Broken.php')), WholeSuite::tests()), $covered, Seconds::of(5.0));

    $locked = library();
    Scratch::write($locked, '.mutation-gate/phpunit', 'a file where the directory goes');
    [$lockedRun] = killingRun($locked);
    set_error_handler(static fn(): bool => true);
    $unwritten = $lockedRun->of(MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests()), $covered, Seconds::of(5.0));
    restore_error_handler();

    expect($broken)->toBeInstanceOf(CannotJudge::class)
        ->and($broken instanceof CannotJudge ? $broken->why() : '')->toStartWith('src/Broken.php does not parse')
        ->and($unwritten)->toBeInstanceOf(CannotJudge::class);
});

it('makes only the mutants a run again names, by their ids', function () use ($covered): void {
    [$run, $shell] = killingRun(library());
    $request = MutationRequest::of(Paths::of(Path::of('src')), WholeSuite::tests());
    $all = $run->of($request, $covered, Seconds::of(5.0));
    $ids = $all instanceof MutationResult ? array_map(static fn(Mutant $mutant): MutantId => $mutant->id(), [...$all->mutants()]) : [];
    $again = $run->makingOnly(MutantIds::of(...array_slice($ids, 2)))->of($request, $covered, Seconds::of(5.0));

    expect(judgedMutants($again))->toBe([['src/Tax.php', 'acme/PlusToMinus', 'uncovered']])
        ->and($again instanceof MutationResult ? $again->skipped() : -1)->toBe(0)
        ->and($shell->commands())->toHaveCount(2);
});

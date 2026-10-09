<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\PhpUnit\Command;
use NightWorksIO\MutationGate\Adapter\PhpUnit\Invocation;
use NightWorksIO\MutationGate\Adapter\PhpUnit\MutantRun;
use NightWorksIO\MutationGate\Adapter\PhpUnit\MutationRun;
use NightWorksIO\MutationGate\Adapter\PhpUnit\Project;
use NightWorksIO\MutationGate\Adapter\PhpUnit\TestFiles;
use NightWorksIO\MutationGate\Adapter\PhpUnit\Variable;
use NightWorksIO\MutationGate\Adapter\PhpUnit\WallClock;
use NightWorksIO\MutationGate\Adapter\PhpUnit\Warm\Workforce;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Cost\Laps;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\MutantIds;
use NightWorksIO\MutationGate\Core\Mutant\Mutators;
use NightWorksIO\MutationGate\Core\Mutant\OrderDigest;
use NightWorksIO\MutationGate\Core\Mutant\Prefix;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Pruning\MutatorNames;
use NightWorksIO\MutationGate\Core\Pruning\Pruned;
use NightWorksIO\MutationGate\Core\Runner\LimitBounds;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Runner\Narrowing;
use NightWorksIO\MutationGate\Core\Runner\Pool;
use NightWorksIO\MutationGate\Core\Runner\ProcessCount;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Runner\SilenceLimit;
use NightWorksIO\MutationGate\Core\Runner\TighterSilence;
use NightWorksIO\MutationGate\Core\Runner\Workers;
use NightWorksIO\MutationGate\Core\Runner\WorkerSlot;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Mutator\Engine\Engine;
use NightWorksIO\MutationGate\Mutator\Mutator;
use NightWorksIO\MutationGate\Tests\Support\Mutators\PlusToMinus;
use NightWorksIO\MutationGate\Tests\Support\Mutators\PlusToMinusAlso;
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
 * The mutation run over a library with these mutators, or two of its own, whose PHPUnit serves each mutant and fails
 * every test it runs, and the shell it runs PHPUnit in.
 *
 * @return array{MutationRun, PhpUnitShellFake}
 */
function killingRun(string $root, Mutator ...$mutators): array
{
    $project = Project::at($root, Paths::of(Path::of('tests')), Path::of('vendor'), Path::of('.mutation-gate'));
    $shell = new PhpUnitShellFake(static function (Command $command): Ran {
        file_put_contents($command->environment()[Variable::Results->value], "failed Tests%5CMoneySpec%3A%3Aadds\n");
        file_put_contents($command->environment()[Variable::Guard->value], "served\n");

        return Ran::finished(succeeded: false, output: '')->took(Seconds::of(0.2));
    });
    $invocation = new Invocation($project, '/gate/override.php');
    $judging = new MutantRun($project, $shell, $invocation, new TestFiles($project), PhpUnitScan::uncapped($project), NotGiven::value());
    $run = new MutationRun(
        $project,
        $mutators === [] ? Engine::with(new PlusToMinus(), new RemoveEcho()) : Engine::with(...$mutators),
        $judging,
        new Workforce($project, $shell, $invocation, PhpUnitScan::uncapped($project), $judging),
        Laps::from(new WallClock()->seconds(...)),
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
    $result = $run->of(MutationRequest::of(Paths::of(Path::of('src')), WholeSuite::tests()), $covered, LimitBounds::between(Seconds::of(5.0), Seconds::of(5.0)));

    expect(judgedMutants($result))->toBe([
        ['src/Money.php', 'acme/RemoveEcho', 'killed'],
        ['src/Money.php', 'acme/PlusToMinus', 'killed'],
        ['src/Tax.php', 'acme/PlusToMinus', 'uncovered'],
    ])
        ->and($result instanceof MutationResult ? $result->skipped() : -1)->toBe(0)
        ->and($shell->commands())->toHaveCount(2);
});

it('runs one of the mutants that leave a file alike, and judges the rest as it, in no time', function () use ($covered): void {
    [$run, $shell] = killingRun(library(), new PlusToMinus(), new PlusToMinusAlso(), new RemoveEcho());
    $result = $run->of(MutationRequest::of(Paths::of(Path::of('src')), WholeSuite::tests()), $covered, LimitBounds::between(Seconds::of(5.0), Seconds::of(5.0)));
    $twins = $result instanceof MutationResult ? array_values(array_map(
        static fn(Mutant $mutant): Seconds|Unmeasured => $mutant->duration(),
        array_filter([...$result->mutants()], static fn(Mutant $mutant): bool => $mutant->mutator() === 'acme/PlusToMinusAlso'),
    )) : [];

    expect(judgedMutants($result))->toBe([
        ['src/Money.php', 'acme/RemoveEcho', 'killed'],
        ['src/Money.php', 'acme/PlusToMinus', 'killed'],
        ['src/Money.php', 'acme/PlusToMinusAlso', 'killed'],
        ['src/Tax.php', 'acme/PlusToMinus', 'uncovered'],
        ['src/Tax.php', 'acme/PlusToMinusAlso', 'uncovered'],
    ])
        ->and($result instanceof MutationResult ? $result->skipped() : -1)->toBe(0)
        ->and($twins)->toEqual([Seconds::of(0.0), Seconds::of(0.0)])
        ->and($shell->commands())->toHaveCount(2);
});

it('allows each mutant 5 s plus three times its covering tests\' own time within the bounds, and the floor where one is untimed', function () use ($covered): void {
    [$run, $shell] = killingRun(library());
    $timed = $covered->timed(TestId::of('Tests\MoneySpec::adds'), Seconds::of(1.0));
    $result = $run->of(MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests()), $timed, LimitBounds::between(Seconds::of(6.0), Seconds::of(30.0)));

    expect(judgedMutants($result))->toBe([
        ['src/Money.php', 'acme/RemoveEcho', 'killed'],
        ['src/Money.php', 'acme/PlusToMinus', 'killed'],
    ])
        ->and(array_map(static fn(Command $command): mixed => $command->deadline(), $shell->commands()))
        ->toEqual([Seconds::of(6.0), Seconds::of(8.0)]);
});

it('gives each mutant\'s run the silence limit of its slowest covering test, on its results file, and none where one is untimed', function (): void {
    $root = library();
    Scratch::write($root, 'src/Span.php', "<?php\n\nfunction add(\$a, \$b)\n{\n    return \$a\n        + \$b;\n}\n");
    [$run, $shell] = killingRun($root);
    $spanned = CoverageMap::empty()
        ->covered(Path::of('src/Span.php'), Line::of(5), TestId::of('Tests\MoneySpec::adds'))
        ->covered(Path::of('src/Span.php'), Line::of(6), TestId::of('Tests\MoneySpec::sums'))
        ->covered(Path::of('src/Money.php'), Line::of(7), TestId::of('Tests\MoneySpec::untimed'))
        ->timed(TestId::of('Tests\MoneySpec::adds'), Seconds::of(1.0))
        ->timed(TestId::of('Tests\MoneySpec::sums'), Seconds::of(2.0));
    $run->of(MutationRequest::of(Paths::of(Path::of('src/Span.php'), Path::of('src/Money.php')), WholeSuite::tests()), $spanned, LimitBounds::between(Seconds::of(6.0), Seconds::of(30.0)));

    expect(array_map(static fn(Command $command): mixed => $command->silence(), $shell->commands()))->toEqual([
        NotGiven::value(),
        SilenceLimit::of(Seconds::of(11.0), $shell->commands()[1]->environment()[Variable::Results->value]),
    ]);
});

it('keeps the silence limit of a mutant of a mutator timeouts.tighter lists above its lower floor', function (): void {
    $root = library();
    Scratch::write($root, 'src/Span.php', "<?php\n\nfunction add(\$a, \$b)\n{\n    return \$a\n        + \$b;\n}\n");
    [$run, $shell] = killingRun($root);
    $quick = CoverageMap::empty()
        ->covered(Path::of('src/Span.php'), Line::of(5), TestId::of('Tests\MoneySpec::adds'))
        ->timed(TestId::of('Tests\MoneySpec::adds'), Seconds::of(0.1));
    $bounds = static fn(string ...$listed): LimitBounds => LimitBounds::between(Seconds::of(10.0), Seconds::of(30.0))
        ->tighterFor(TighterSilence::of(Seconds::of(7.0), ...$listed));
    $request = MutationRequest::of(Paths::of(Path::of('src/Span.php')), WholeSuite::tests());
    $run->of($request, $quick, $bounds('PlusToMinus'));
    $run->of($request, $quick, $bounds('MinusToPlus'));
    $silence = array_map(static fn(Command $command): mixed => $command->silence(), $shell->commands());

    expect(array_map(static fn(mixed $limit): mixed => $limit instanceof SilenceLimit ? $limit->limit() : $limit, $silence))
        ->toEqual([Seconds::of(7.0), Seconds::of(10.0)]);
});

it('judges a mutant that spans lines by the tests of every line it spans, and times it by them all', function (): void {
    $root = library();
    Scratch::write($root, 'src/Span.php', "<?php\n\nfunction add(\$a, \$b)\n{\n    return \$a\n        + \$b;\n}\n");
    [$run, $shell] = killingRun($root);
    $spanned = CoverageMap::empty()
        ->covered(Path::of('src/Span.php'), Line::of(5), TestId::of('Tests\MoneySpec::adds'))
        ->covered(Path::of('src/Span.php'), Line::of(6), TestId::of('Tests\MoneySpec::sums'))
        ->timed(TestId::of('Tests\MoneySpec::adds'), Seconds::of(1.0))
        ->timed(TestId::of('Tests\MoneySpec::sums'), Seconds::of(2.0));
    $result = $run->of(MutationRequest::of(Paths::of(Path::of('src/Span.php')), WholeSuite::tests()), $spanned, LimitBounds::between(Seconds::of(6.0), Seconds::of(30.0)));

    expect(judgedMutants($result))->toBe([['src/Span.php', 'acme/PlusToMinus', 'killed']])
        ->and(array_map(static fn(Command $command): mixed => $command->deadline(), $shell->commands()))
        ->toEqual([Seconds::of(14.0)]);
});

it('makes only the mutators asked for, of the files not left out', function () use ($covered): void {
    [$run] = killingRun(library());
    $request = MutationRequest::of(Paths::of(Path::of('src')), WholeSuite::tests())
        ->narrowedTo(Paths::of(Path::of('src')), Narrowing::none()->toMutators(Mutators::named('acme/PlusToMinus')))
        ->leavingOut(Paths::of(Path::of('src/Tax.php')));

    expect(judgedMutants($run->of($request, $covered, LimitBounds::between(Seconds::of(5.0), Seconds::of(5.0)))))->toBe([['src/Money.php', 'acme/PlusToMinus', 'killed']]);
});

it('makes no mutant of a mutator its request leaves out of a file, and every other', function () use ($covered): void {
    [$run] = killingRun(library());
    $pruned = Pruned::of(MutatorNames::of('acme/PlusToMinus'), Paths::of(Path::of('src/Money.php')));
    $request = MutationRequest::of(Paths::of(Path::of('src')), WholeSuite::tests())
        ->narrowedTo(Paths::of(Path::of('src')), Narrowing::none()->pruning($pruned));

    expect(judgedMutants($run->of($request, $covered, LimitBounds::between(Seconds::of(5.0), Seconds::of(5.0)))))
        ->toBe([['src/Money.php', 'acme/RemoveEcho', 'killed'], ['src/Tax.php', 'acme/PlusToMinus', 'uncovered']]);
});

it('skips with no record every mutant past the request\'s deadline', function () use ($covered): void {
    [$run, $shell] = killingRun(library());
    $request = MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests())->within(Seconds::of(0.0));
    $result = $run->of($request, $covered, LimitBounds::between(Seconds::of(5.0), Seconds::of(5.0)));

    expect(judgedMutants($result))->toBe([])
        ->and($result instanceof MutationResult ? $result->skipped() : -1)->toBe(2)
        ->and($shell->commands())->toBe([]);
});

it('cannot judge a request with a file that does not parse, or a mutant whose files it cannot write', function () use ($covered): void {
    $root = library();
    Scratch::write($root, 'src/Broken.php', "<?php\n\nfunction (\n");
    [$run] = killingRun($root);
    $broken = $run->of(MutationRequest::of(Paths::of(Path::of('src/Broken.php')), WholeSuite::tests()), $covered, LimitBounds::between(Seconds::of(5.0), Seconds::of(5.0)));

    $locked = library();
    Scratch::write($locked, '.mutation-gate/phpunit', 'a file where the directory goes');
    [$lockedRun] = killingRun($locked);
    set_error_handler(static fn(): bool => true);
    $unwritten = $lockedRun->of(MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests()), $covered, LimitBounds::between(Seconds::of(5.0), Seconds::of(5.0)));
    restore_error_handler();

    expect($broken)->toBeInstanceOf(CannotJudge::class)
        ->and($broken instanceof CannotJudge ? $broken->why() : '')->toStartWith('src/Broken.php does not parse')
        ->and($unwritten)->toBeInstanceOf(CannotJudge::class);
});

it('makes only the mutants a run again names, by their ids', function () use ($covered): void {
    [$run, $shell] = killingRun(library());
    $request = MutationRequest::of(Paths::of(Path::of('src')), WholeSuite::tests());
    $all = $run->of($request, $covered, LimitBounds::between(Seconds::of(5.0), Seconds::of(5.0)));
    $ids = $all instanceof MutationResult ? array_map(static fn(Mutant $mutant): MutantId => $mutant->id(), [...$all->mutants()]) : [];
    $again = $run->makingOnly(MutantIds::of(...array_slice($ids, 2)))->of($request, $covered, LimitBounds::between(Seconds::of(5.0), Seconds::of(5.0)));

    expect(judgedMutants($again))->toBe([['src/Tax.php', 'acme/PlusToMinus', 'uncovered']])
        ->and($again instanceof MutationResult ? $again->skipped() : -1)->toBe(0)
        ->and($shell->commands())->toHaveCount(2);
});

/**
 * A library whose one function sums this many pairs, each on a line of its own a test covers, and the map that says so.
 *
 * @return array{string, CoverageMap}
 */
function sums(int $count): array
{
    $root = Scratch::directory();
    $sums = implode('', array_map(static fn(int $at): string => sprintf("    \$s[] = \$a + %d;\n", $at), range(1, $count)));
    Scratch::write($root, 'src/Sums.php', sprintf("<?php\n\nfunction sums(\$a)\n{\n%s\n    return \$s;\n}\n", $sums));
    $map = CoverageMap::empty();

    foreach (range(1, $count) as $at) {
        $map = $map->covered(Path::of('src/Sums.php'), Line::of($at + 4), TestId::of(sprintf('Tests\\SumsSpec::sums%d', $at)));
    }

    return [$root, $map];
}

it('runs the mutants side by side across the request\'s processes, each told its place', function () use ($covered): void {
    [$run, $shell] = killingRun(library());
    $request = MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests())->across(Pool::of(ProcessCount::of(2), Workers::Fresh));
    $result = $run->of($request, $covered, LimitBounds::between(Seconds::of(5.0), Seconds::of(5.0)));
    $told = array_map(
        static fn(WorkerSlot $slot): array => iterator_to_array($slot->variables(), preserve_keys: true),
        $shell->places(),
    );

    expect(judgedMutants($result))->toHaveCount(2)
        ->and(array_column($told, 'TEST_TOKEN'))->toBe(['1', '2'])
        ->and(array_column($told, 'PARATEST'))->toBe(['1', '1']);
});

it('tells a mutant\'s run nothing of places where the request runs one process', function () use ($covered): void {
    [$run, $shell] = killingRun(library());
    $run->of(MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests()), $covered, LimitBounds::between(Seconds::of(5.0), Seconds::of(5.0)));

    expect(array_map(static fn(WorkerSlot $slot): array => iterator_to_array($slot->variables(), preserve_keys: true), $shell->places()))
        ->toBe([[], []]);
});

it('runs sixteen runs a process in each batch, and the rest in the last', function (): void {
    [$root, $map] = sums(17);
    [$run, $shell] = killingRun($root);
    $result = $run->of(MutationRequest::of(Paths::of(Path::of('src/Sums.php')), WholeSuite::tests()), $map, LimitBounds::between(Seconds::of(5.0), Seconds::of(5.0)));

    expect($shell->batches())->toBe([16, 1])
        ->and($result instanceof MutationResult ? count($result->mutants()) : -1)->toBe(17);
});

it('runs as many runs in a batch as sixteen for each process, and no batch once the last is full', function (): void {
    [$root, $map] = sums(33);
    [$run, $shell] = killingRun($root);
    $request = MutationRequest::of(Paths::of(Path::of('src/Sums.php')), WholeSuite::tests())->across(Pool::of(ProcessCount::of(2), Workers::Fresh));
    $run->of($request, $map, LimitBounds::between(Seconds::of(5.0), Seconds::of(5.0)));
    [$exact, $full] = sums(16);
    [$once, $one] = killingRun($exact);
    $once->of(MutationRequest::of(Paths::of(Path::of('src/Sums.php')), WholeSuite::tests()), $full, LimitBounds::between(Seconds::of(5.0), Seconds::of(5.0)));

    expect($shell->batches())->toBe([32, 1])
        ->and($one->batches())->toBe([16]);
});

it('judges every mutant whose batch starts in the time left before the deadline', function (): void {
    [$root, $map] = sums(17);
    [$run, $shell] = killingRun($root);
    $request = MutationRequest::of(Paths::of(Path::of('src/Sums.php')), WholeSuite::tests())->within(Seconds::of(3600.0));
    $result = $run->of($request, $map, LimitBounds::between(Seconds::of(5.0), Seconds::of(5.0)));

    expect($shell->batches())->toBe([16, 1])
        ->and($result instanceof MutationResult ? [count($result->mutants()), $result->skipped()] : [])->toBe([17, 0]);
});

it('judges no mutant after a batch whose runs could not all start by the deadline', function (): void {
    [$root, $map] = sums(17);
    [$run, $shell] = killingRun($root);
    $shell->startingAtMost(3);
    $result = $run->of(MutationRequest::of(Paths::of(Path::of('src/Sums.php')), WholeSuite::tests()), $map, LimitBounds::between(Seconds::of(5.0), Seconds::of(5.0)));

    expect($shell->batches())->toBe([16])
        ->and($result instanceof MutationResult ? [count($result->mutants()), $result->skipped()] : [])->toBe([3, 14]);
});

it('gives each kill a fresh run made how far its run went, from the test its results file says started, and a twin its first\'s', function () use ($covered): void {
    $root = library();
    $project = Project::at($root, Paths::of(Path::of('tests')), Path::of('vendor'), Path::of('.mutation-gate'));
    $shell = new PhpUnitShellFake(static function (Command $command): Ran {
        file_put_contents($command->environment()[Variable::Results->value], "started Tests%5CMoneySpec%3A%3Aadds\nfailed Tests%5CMoneySpec%3A%3Aadds\n");
        file_put_contents($command->environment()[Variable::Guard->value], "served\n");

        return Ran::finished(succeeded: false, output: '')->took(Seconds::of(0.2));
    });
    $invocation = new Invocation($project, '/gate/override.php');
    $judging = new MutantRun($project, $shell, $invocation, new TestFiles($project), PhpUnitScan::uncapped($project), NotGiven::value());
    $run = new MutationRun(
        $project,
        Engine::with(new PlusToMinus(), new PlusToMinusAlso(), new RemoveEcho()),
        $judging,
        new Workforce($project, $shell, $invocation, PhpUnitScan::uncapped($project), $judging),
        Laps::from(new WallClock()->seconds(...)),
    );

    $result = $run->of(MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests()), $covered, LimitBounds::between(Seconds::of(5.0), Seconds::of(5.0)));
    $prefixes = $result instanceof MutationResult
        ? array_map(static fn(Mutant $mutant): Prefix|NotGiven => $result->evidence()->of($mutant->id())->prefix(), [...$result->mutants()])
        : [];
    $expected = Prefix::keyedAt(1, Prefix::keyOf(Paths::none(), OrderDigest::of(TestId::of('Tests\MoneySpec::adds'))->value()));

    expect(judgedMutants($result))->toBe([
        ['src/Money.php', 'acme/RemoveEcho', 'killed'],
        ['src/Money.php', 'acme/PlusToMinus', 'killed'],
        ['src/Money.php', 'acme/PlusToMinusAlso', 'killed'],
    ])
        ->and($prefixes)->toEqual([$expected, $expected, $expected]);
});

<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\AloneRuns;
use NightWorksIO\MutationGate\Adapter\Pest\Command;
use NightWorksIO\MutationGate\Adapter\Pest\Controls;
use NightWorksIO\MutationGate\Adapter\Pest\Covering;
use NightWorksIO\MutationGate\Adapter\Pest\Project;
use NightWorksIO\MutationGate\Adapter\Pest\Remembered;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\DiskPath;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ShellFake;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

afterEach(function (): void {
    Scratch::sweep();
});

/** The mutant of src/Money.php's line with this number, with this status. */
function controlledMutant(int $line, MutantStatus $status): Mutant
{
    $made = Verdicts::mutant(sprintf('src/Money.php:%d', $line), 'Plus', MutatorFamily::Arithmetic, sprintf('-%d', $line));

    return Mutant::of($made->id(), $made->nativeId(), $made->location(), $made->mutation(), $status, Unmeasured::duration());
}

/** A project holding src/Money.php. */
function controlledProject(): Project
{
    $root = (string) realpath(Scratch::directory());
    Scratch::write($root, 'src/Money.php', "<?php\n\nfinal class Money\n{\n}\n");

    return Project::at($root, Paths::none(), Path::of('.mutation-gate'), Path::of('vendor'));
}

/** Coverage in which these tests cover every line. */
function controlledCovering(string ...$tests): Covering
{
    return new readonly class (array_values($tests)) implements Covering {
        /** @param list<string> $tests */
        public function __construct(private array $tests)
        {
        }

        public function testsCovering(DiskPath $file, Line $first, Line $last): TestIds
        {
            return TestIds::of(...array_map(TestId::of(...), $this->tests));
        }

        public function map(Project $project): CoverageMap
        {
            return CoverageMap::empty();
        }
    };
}

it('leaves unjudged each kill confirmed again whose control fails, and keeps every other mutant run again as it was found', function (): void {
    $at = controlledProject();
    $killed = controlledMutant(11, MutantStatus::Killed);
    $survived = controlledMutant(16, MutantStatus::Survived);
    $passing = controlledMutant(21, MutantStatus::Killed);
    $shell = new ShellFake(static fn(Command $command): Ran => Ran::finished(
        succeeded: ! str_contains(implode(' ', $command->arguments()), 'fails'),
        output: '',
    ));
    $controls = Controls::of($at, Mutants::of($killed, $survived), Group::named('g'), static fn(): Covering => controlledCovering('P\Tests\MoneySpec::__pest_evaluable_it_fails'));
    $others = Controls::of($at, Mutants::of($passing), Group::named('g'), static fn(): Covering => controlledCovering('P\Tests\MoneySpec::__pest_evaluable_it_adds'));
    $runs = new AloneRuns($at, $shell, new Remembered());
    $request = MutationRequest::of(Paths::of(Path::of('src/Money.php')), Group::named('g'));
    $results = sprintf('%s/results.jsonl', $at->root());

    $applied = [...$controls->applied(Mutants::of($killed, $survived), $runs, $request, Unlimited::time(), $results)];
    $kept = [...$others->applied(Mutants::of($passing), $runs, $request, Unlimited::time(), $results)];

    expect(array_map(static fn(Mutant $mutant): MutantStatus => $mutant->status(), [...$applied, ...$kept]))
        ->toBe([MutantStatus::Unjudged, MutantStatus::Survived, MutantStatus::Killed])
        ->and($applied[0]->reason())->toEqual(Reason::that(Controls::FAILS_UNMUTATED))
        ->and($shell->commands())->toHaveCount(2);
});

it('controls a kill by the tests that judged its run where Pest\'s filter is too long to pass, as Pest then runs them', function (): void {
    $at = controlledProject();
    $long = array_map(static fn(int $at): string => sprintf('P\Tests\MoneySpec::__pest_evaluable_it_adds_%s_%d', str_repeat('x', 200), $at), range(1, 600));
    $shell = ShellFake::answering(Ran::finished(succeeded: true, output: ''));
    $killed = controlledMutant(11, MutantStatus::Killed);
    $controls = Controls::of($at, Mutants::of($killed), Group::named('holds:src/Money.php'), static fn(): Covering => controlledCovering(...$long));

    $controls->applied(
        Mutants::of($killed),
        new AloneRuns($at, $shell, new Remembered()),
        MutationRequest::of(Paths::of(Path::of('src/Money.php')), Group::named('holds:src/Money.php')),
        Unlimited::time(),
        sprintf('%s/results.jsonl', $at->root()),
    );

    expect($shell->commands()[0]->arguments())->toContain('--group=holds:src/Money.php')
        ->and(implode(' ', $shell->commands()[0]->arguments()))->not->toContain('--filter');
});

it('leaves unjudged every kill confirmed again, and runs nothing, where the coverage its controls are read off cannot be read', function (): void {
    $at = controlledProject();
    $shell = ShellFake::answering(Ran::finished(succeeded: true, output: ''));
    $killed = controlledMutant(11, MutantStatus::Killed);
    $survived = controlledMutant(16, MutantStatus::Survived);
    $controls = Controls::of($at, Mutants::of($killed, $survived), Group::named('g'), static fn(): CannotJudge => CannotJudge::because('No map.'));

    $applied = [...$controls->applied(
        Mutants::of($killed, $survived),
        new AloneRuns($at, $shell, new Remembered()),
        MutationRequest::of(Paths::of(Path::of('src/Money.php')), Group::named('g')),
        Unlimited::time(),
        sprintf('%s/results.jsonl', $at->root()),
    )];

    expect(array_map(static fn(Mutant $mutant): MutantStatus => $mutant->status(), $applied))
        ->toBe([MutantStatus::Unjudged, MutantStatus::Survived])
        ->and($applied[0]->reason())->toEqual(Reason::that('Killed, but its covering tests cannot be told, so nothing vouches for the kill: No map.'))
        ->and($shell->commands())->toBe([])
        ->and([...$controls->doubtful()])->toEqual([$killed, $survived]);
});

it('reads no coverage where there is no doubtful kill to control', function (): void {
    $read = 0;

    $controls = Controls::of(controlledProject(), Mutants::none(), Group::named('g'), static function () use (&$read): CannotJudge {
        $read++;

        return CannotJudge::because('No map.');
    });

    expect([...$controls->doubtful()])->toBe([])
        ->and($read)->toBe(0);
});

it('controls a mutant over several lines by the tests that cover any of them, its last among them', function (): void {
    $at = controlledProject();
    $made = controlledMutant(11, MutantStatus::Killed);
    $spanning = Mutant::of($made->id(), $made->nativeId(), Location::of(Path::of('src/Money.php'), Line::of(11), Line::of(13)), $made->mutation(), MutantStatus::Killed, Unmeasured::duration());
    $covering = new readonly class implements Covering {
        public function testsCovering(DiskPath $file, Line $first, Line $last): TestIds
        {
            return TestIds::of(TestId::of($last->number() === 13 ? 'P\Tests\MoneySpec::__pest_evaluable_it_fails' : 'P\Tests\MoneySpec::__pest_evaluable_it_adds'));
        }

        public function map(Project $project): CoverageMap
        {
            return CoverageMap::empty();
        }
    };
    $shell = new ShellFake(static fn(Command $command): Ran => Ran::finished(
        succeeded: ! str_contains(implode(' ', $command->arguments()), 'fails'),
        output: '',
    ));

    $applied = [...Controls::of($at, Mutants::of($spanning), Group::named('g'), static fn(): Covering => $covering)->applied(
        Mutants::of($spanning),
        new AloneRuns($at, $shell, new Remembered()),
        MutationRequest::of(Paths::of(Path::of('src/Money.php')), Group::named('g')),
        Unlimited::time(),
        sprintf('%s/results.jsonl', $at->root()),
    )];

    expect($applied[0]->status())->toBe(MutantStatus::Unjudged);
});

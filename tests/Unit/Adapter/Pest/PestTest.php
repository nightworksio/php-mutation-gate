<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Command;
use NightWorksIO\MutationGate\Adapter\Pest\Diff;
use NightWorksIO\MutationGate\Adapter\Pest\Interpretation;
use NightWorksIO\MutationGate\Adapter\Pest\Invocation;
use NightWorksIO\MutationGate\Adapter\Pest\Patch;
use NightWorksIO\MutationGate\Adapter\Pest\Patching;
use NightWorksIO\MutationGate\Adapter\Pest\Pest;
use NightWorksIO\MutationGate\Adapter\Pest\ProcessShell;
use NightWorksIO\MutationGate\Adapter\Pest\Project;
use NightWorksIO\MutationGate\Adapter\Pest\Ran;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Recorder;
use NightWorksIO\MutationGate\Adapter\Pest\Selection;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Marker;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Mutant\Mutators;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Runner\CoverageRequest;
use NightWorksIO\MutationGate\Core\Runner\Identity;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Runner\Platform;
use NightWorksIO\MutationGate\Core\Runner\Version;
use NightWorksIO\MutationGate\Core\Runner\Versions;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\Groups;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Extension\Options;
use NightWorksIO\MutationGate\Tests\Support\CoverageMaps;
use NightWorksIO\MutationGate\Tests\Support\PestRun;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ShellFake;
use NightWorksIO\MutationGate\Tests\Support\Tree;
use Pest\Mutate\Mutators\Arithmetic\PlusToMinus;

afterEach(function (): void {
    Scratch::sweep();
});

const RUN_PLUS = PlusToMinus::class;

const RUN_ADDS = 'P\Tests\MoneySpec::__pest_evaluable_it_adds';

const RUN_LISTING = "   INFO  Available test groups:\n\n - default (2 tests)\n - mutation-canary (1 test).\n";

/**
 * A coverage map in a project's root, covering these lines of its files, by the one test RUN_ADDS.
 *
 * @param array<non-empty-string, array<positive-int, list<int<0, max>>>> $lines
 * @param array<non-empty-string, float>                                $durations
 */
function adapterMap(string $root, string $file, array $lines, array $durations): void
{
    CoverageMaps::write(sprintf('%s/%s', $root, $file), sprintf('%s/', $root), $lines, [RUN_ADDS], $durations);
}

/** A request to mutate src/Money.php against the whole suite. */
function adapterMoney(): MutationRequest
{
    return MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests());
}

/** The results file every run of a project records to. */
function adapterResults(Project $at): string
{
    return sprintf('%s/.mutation-gate/pest/results.jsonl', $at->root());
}

/** Pest patched with the canary group mutation-canary. */
function adapterCanary(): Patching
{
    return Patching::on(Group::named('mutation-canary'));
}

/** Pest's command lines in a project that installs its packages in `vendor`. */
function adapterInvocation(): Invocation
{
    return Invocation::installedIn(Path::of('vendor'));
}

/** A project in a new directory, by its real path, that installs its packages in `vendor` or another directory. */
function adapterProject(string $vendor = 'vendor'): Project
{
    $root = (string) realpath(Scratch::directory());

    return Project::at($root, Paths::of(Path::of('tests')), Path::of('.mutation-gate'), Path::of($vendor));
}

/** What Pest and the plugin leave when a run mutates src/Money.php's line 11 once, and a test kills it. */
function adapterKilled(Command $command, Project $project): Ran
{
    $results = sprintf('%s', $command->environment()['MUTATION_GATE_RESULTS'] ?? '');

    if (is_file($results)) {
        return Ran::finished(succeeded: false, output: 'an earlier run\'s results were left in place');
    }

    $money = sprintf('%s/src/Money.php', $project->root());
    $map = Recorder::coverageBeside($results);
    CoverageMaps::write($map, sprintf('%s/', $project->root()), ['src/Money.php' => [11 => [0]]], [RUN_ADDS], []);
    PestRun::write($results, [
        PestRun::planned('n1', $money, 11, RUN_PLUS, 'return $a + $b;', 'return $a - $b;'),
        PestRun::made(1),
        PestRun::finished('n1', 'tested', 0.25),
        PestRun::end(),
    ]);

    return Ran::finished(succeeded: true, output: '  Mutations: 1 tested');
}

/** The mutant that run reports. */
function adapterMutant(): Mutant
{
    $diff = Diff::fromPest("\n  <fg=red>-        return \$a + \$b;</>\n  <fg=green>+        return \$a - \$b;</>\n");
    $path = Path::of('src/Money.php');

    return Mutant::of(
        MutantId::hash($path, RUN_PLUS, $diff, 0),
        'n1',
        Location::of($path, Line::of(11), Line::of(11)),
        Mutation::of(RUN_PLUS, MutatorFamily::Arithmetic, $diff),
        MutantStatus::Killed,
        Seconds::of(0.25),
    );
}

/** A project with a patched copy of the installed pest-plugin-mutate, and the planning job's map. */
function adapterPatched(string $vendor = 'vendor'): Project
{
    $at = adapterProject($vendor);

    foreach (['MutationTest.php', 'Plugins/Mutate.php', 'Tester/MutationTestRunner.php'] as $file) {
        $installed = (string) file_get_contents(Tree::at(sprintf('vendor/pestphp/pest-plugin-mutate/src/%s', $file)));
        Scratch::write($at->root(), sprintf('%s/pestphp/pest-plugin-mutate/src/%s', $vendor, $file), $installed);
    }

    Patch::applyIn(sprintf('%s/%s', $at->root(), $vendor));
    mkdir(sprintf('%s/planned', $at->root()));
    adapterMap($at->root(), 'planned/coverage.php', [], [RUN_ADDS => 1.25, 'Tests\B::c' => 2.0]);

    return $at;
}

it('names Pest, the exact versions it mutates with, and the PHP it runs on', function (): void {
    $at = adapterProject();
    $installed = ['pestphp/pest', 'pestphp/pest-plugin-mutate', 'phpunit/phpunit', 'phpunit/php-code-coverage'];
    $packages = array_map(
        static fn(string $name): array => ['name' => $name, 'version' => '1.0.0', 'dist' => ['reference' => 'abc']],
        $installed,
    );
    Scratch::write($at->root(), 'vendor/composer/installed.json', (string) json_encode(['packages' => $packages]));

    expect(new Pest($at, ShellFake::answering(Ran::stopped('')), Patching::off())->identity())->toEqual(Identity::of(
        'pest',
        Versions::of(...array_map(static fn(string $name): Version => Version::of($name, '1.0.0', 'abc'), $installed)),
        Platform::current()->digest(),
    ));
});

it('cannot say which Pest it runs where Composer installed none', function (): void {
    $at = adapterProject();
    $pest = new Pest($at, ShellFake::answering(Ran::stopped('')), Patching::off());

    expect($pest->identity())->toEqual(CannotJudge::because(sprintf(
        '%s/vendor/composer/installed.json does not list pestphp/pest, pestphp/pest-plugin-mutate, phpunit/phpunit, '
        . 'phpunit/php-code-coverage, so the gate cannot say which Pest judges the mutants. Run composer install.',
        $at->root(),
    )));
});

it('lists the suite\'s groups as Pest lists them', function (): void {
    $shell = ShellFake::answering(Ran::finished(succeeded: true, output: RUN_LISTING));

    $groups = new Pest(adapterProject(), $shell, Patching::off())->groups();

    expect($groups)->toEqual(Groups::of(Group::named('mutation-canary')))
        ->and($shell->commands())->toEqual([adapterInvocation()->listingGroups()]);
});

it('runs the suite under coverage into a directory it makes, and reads the map', function (): void {
    $at = adapterProject();
    $shell = new ShellFake(static function () use ($at): Ran {
        $lines = ['src/Money.php' => [11 => [0]]];
        adapterMap($at->root(), '.mutation-gate/coverage/coverage.php', $lines, [RUN_ADDS => 0.5]);

        return Ran::finished(succeeded: true, output: 'OK');
    });
    $request = CoverageRequest::running(WholeSuite::tests(), Path::of('.mutation-gate/coverage'));
    $directory = sprintf('%s/.mutation-gate/coverage', $at->root());

    expect(new Pest($at, $shell, Patching::off())->coverage($request))->toEqual(CoverageMap::empty()
        ->covered(Path::of('src/Money.php'), Line::of(11), TestId::of(RUN_ADDS))
        ->timed(TestId::of(RUN_ADDS), Seconds::of(0.5)))
        ->and($shell->commands())->toEqual([adapterInvocation()->coverage($request, $directory)]);
});

it('cannot judge a coverage run that failed, with what Pest said', function (): void {
    $request = CoverageRequest::running(WholeSuite::tests(), Path::of('.mutation-gate/coverage'));
    $shell = ShellFake::answering(Ran::finished(succeeded: false, output: 'No code coverage driver'));

    expect(new Pest(adapterProject(), $shell, Patching::off())->coverage($request))
        ->toEqual(CannotJudge::because("Pest's coverage run failed. Pest said:\nNo code coverage driver"));
});

it('reads the map another job wrote, running nothing', function (): void {
    $at = adapterProject();
    mkdir(sprintf('%s/planned', $at->root()));
    adapterMap($at->root(), 'planned/coverage.php', ['src/Held.php' => [5 => [0]]], []);
    $shell = ShellFake::answering(Ran::finished(succeeded: false, output: 'not run'));
    $pest = new Pest($at, $shell, Patching::off());

    expect($pest->coverage(CoverageRequest::reading(Path::of('planned'))))
        ->toEqual(CoverageMap::empty()->covered(Path::of('src/Held.php'), Line::of(5), TestId::of(RUN_ADDS)))
        ->and($pest->coverage(CoverageRequest::reading(Path::of('elsewhere'))))->toBeInstanceOf(CannotJudge::class)
        ->and($shell->commands())->toBe([]);
});

it('names the test files a covering test\'s filter selects, or all when it will not fit', function (): void {
    $at = adapterProject();
    Scratch::write($at->root(), 'tests/MoneySpec.php', '<?php');
    Scratch::write($at->root(), 'tests/HeldSpec.php', '<?php');
    $long = sprintf('P\Tests\HeldSpec::__pest_evaluable_%s', str_repeat('x', Selection::CEILING));
    $map = CoverageMap::empty()
        ->covered(Path::of('src/Money.php'), Line::of(11), TestId::of(RUN_ADDS))
        ->covered(Path::of('src/Kernel.php'), Line::of(3), TestId::of($long));
    $pest = new Pest($at, ShellFake::answering(Ran::stopped('')), Patching::off());
    $every = Paths::of(Path::of('tests/HeldSpec.php'), Path::of('tests/MoneySpec.php'));

    expect($pest->judges(Path::of('src/Money.php'), $map))->toEqual(Paths::of(Path::of('tests/MoneySpec.php')))
        ->and($pest->judges(Path::of('src/Kernel.php'), $map))->toEqual($every)
        ->and($pest->judges(Path::of('src/Nowhere.php'), $map))->toEqual(Paths::none());
});

it('refuses to judge by a filter, which Pest cannot select held tests by', function (): void {
    $shell = ShellFake::answering(Ran::stopped(''));
    $request = MutationRequest::of(Paths::of(Path::of('src/Kernel.php')), Filter::matching('KernelTest'));

    expect(new Pest(adapterProject(), $shell, Patching::off())->mutate($request))->toEqual(CannotJudge::because(
        'Pest selects held tests by the holds: groups its plugin adds for #[Holds], not by the filter KernelTest.',
    ))->and($shell->commands())->toBe([]);
});

it('mutates with a fresh results file, and reads what the plugin recorded', function (): void {
    $at = adapterProject();
    Scratch::write($at->root(), '.mutation-gate/pest/results.jsonl', 'an earlier run');
    $shell = new ShellFake(static fn(Command $command): Ran => adapterKilled($command, $at));

    $result = new Pest($at, $shell, Patching::off())->mutate(adapterMoney());

    expect($result)->toEqual(MutationResult::of(Mutants::of(adapterMutant()), 0))
        ->and($shell->commands())
        ->toEqual([adapterInvocation()->mutation(adapterMoney(), WholeSuite::tests(), adapterResults($at))]);
});

it('mutates against a group without reading a shared map', function (): void {
    $at = adapterProject();
    $shell = new ShellFake(static fn(Command $command): Ran => adapterKilled($command, $at));
    $held = Group::named('holds:src/Money.php');
    $request = MutationRequest::of(Paths::of(Path::of('src/Money.php')), $held)->reusingCoverage(Path::of('planned'));

    new Pest($at, $shell, adapterCanary())->mutate($request);

    expect($shell->commands())->toEqual([adapterInvocation()->mutation($request, $held, adapterResults($at))]);
});

it('opens a patched shard on the canary group, reading the planning job\'s map', function (): void {
    $at = adapterPatched();
    $shell = new ShellFake(static fn(Command $command, int $before): Ran => $before === 0
        ? Ran::finished(succeeded: true, output: RUN_LISTING)
        : adapterKilled($command, $at));
    $request = adapterMoney()->reusingCoverage(Path::of('planned'));
    $result = new Pest($at, $shell, adapterCanary())->mutate($request);

    expect($result)->toBeInstanceOf(MutationResult::class)
        ->and($shell->commands())->toEqual([
            adapterInvocation()->listingGroups(),
            adapterInvocation()->mutation($request, WholeSuite::tests(), adapterResults($at))->with([
                'MUTATION_GATE_SHARED_COVERAGE' => sprintf('%s/planned/coverage.php', $at->root()),
                'MUTATION_GATE_SUITE_SECONDS' => '3.250000',
                'MUTATION_GATE_CANARY' => 'mutation-canary',
            ]),
        ]);
});

it('opens a shard on its own suite unpatched, or when it collects its own map', function (): void {
    $at = adapterPatched();
    $shell = new ShellFake(static fn(Command $command): Ran => adapterKilled($command, $at));
    $reusing = adapterMoney()->reusingCoverage(Path::of('planned'));

    new Pest($at, $shell, Patching::off())->mutate($reusing);
    new Pest($at, $shell, adapterCanary())->mutate(adapterMoney());

    expect($shell->commands())->toEqual([
        adapterInvocation()->mutation($reusing, WholeSuite::tests(), adapterResults($at)),
        adapterInvocation()->mutation(adapterMoney(), WholeSuite::tests(), adapterResults($at)),
    ]);
});

it('cannot open a shard on the canary group without the patch applied', function (): void {
    $shell = ShellFake::answering(Ran::finished(succeeded: true, output: RUN_LISTING));
    $request = adapterMoney()->reusingCoverage(Path::of('planned'));

    expect(new Pest(adapterProject(), $shell, adapterCanary())->mutate($request))->toEqual(CannotJudge::because(
        'pest.patch is on, but pest-plugin-mutate in vendor is not patched. Run mutation-gate pest:patch.',
    ))->and($shell->commands())->toBe([]);
});

it('finds Pest, what Composer installed and the patch in the vendor directory the project installs into', function (): void {
    $at = adapterPatched('lib/vendor');
    $installed = ['pestphp/pest', 'pestphp/pest-plugin-mutate', 'phpunit/phpunit', 'phpunit/php-code-coverage'];
    $packages = array_map(static fn(string $name): array => ['name' => $name, 'version' => '1.0.0'], $installed);
    Scratch::write($at->root(), 'lib/vendor/composer/installed.json', (string) json_encode(['packages' => $packages]));
    $shell = new ShellFake(static fn(Command $command, int $before): Ran => $before === 0
        ? Ran::finished(succeeded: true, output: RUN_LISTING)
        : adapterKilled($command, $at));
    $pest = new Pest($at, $shell, adapterCanary());
    $invocation = Invocation::installedIn(Path::of('lib/vendor'));

    expect($pest->identity())->toBeInstanceOf(Identity::class)
        ->and($pest->mutate(adapterMoney()->reusingCoverage(Path::of('planned'))))->toBeInstanceOf(MutationResult::class)
        ->and($shell->commands()[0])->toEqual($invocation->listingGroups());
});

it('cannot open a shard on a canary group with no test, or one it cannot list', function (): void {
    $at = adapterPatched();
    $request = adapterMoney()->reusingCoverage(Path::of('planned'));
    $empty = ShellFake::answering(Ran::finished(succeeded: true, output: RUN_LISTING));
    $unlisted = ShellFake::answering(Ran::finished(succeeded: false, output: 'broken'));
    $other = Patching::on(Group::named('canary'));

    expect(new Pest($at, $empty, $other)->mutate($request))
        ->toEqual(CannotJudge::because('pest.patch is on, but the canary group canary holds no test. Add one.'))
        ->and(new Pest($at, $unlisted, $other)->mutate($request))
        ->toEqual(CannotJudge::because(
            "Pest did not list the suite's groups, so no group can hold a path. Pest said:\nbroken",
        ));
});

it('cannot open a shard on the canary group without the planning job\'s map', function (): void {
    $at = adapterPatched();
    $request = adapterMoney()->reusingCoverage(Path::of('absent'));
    $shell = ShellFake::answering(Ran::finished(succeeded: true, output: RUN_LISTING));

    expect(new Pest($at, $shell, adapterCanary())->mutate($request))->toEqual(CannotJudge::because(
        sprintf('There is no coverage map at %s/absent/coverage.php, so no test runs any line.', $at->root()),
    ));
});

it('runs the mutants again once per file and mutator, with no deadline, matched back by id', function (): void {
    $at = adapterProject();
    $shell = new ShellFake(static fn(Command $command): Ran => adapterKilled($command, $at));
    $survivor = adapterMutant();
    $place = Location::of(Path::of('src/Money.php'), Line::of(12), Line::of(12));
    $change = Mutation::of(RUN_PLUS, MutatorFamily::Arithmetic, '-gone');
    $id = MutantId::hash(Path::of('src/Money.php'), RUN_PLUS, '-gone', 0);
    $gone = Mutant::of($id, 'n9', $place, $change, MutantStatus::Survived, Unmeasured::duration());
    $elsewhere = Mutant::of(
        MutantId::hash(Path::of('src/Held.php'), RUN_PLUS, '-held', 0),
        'n8',
        Location::of(Path::of('src/Held.php'), Line::of(3), Line::of(3)),
        Mutation::of(RUN_PLUS, MutatorFamily::Arithmetic, '-held'),
        MutantStatus::Survived,
        Unmeasured::duration(),
    );
    $request = adapterMoney()->onlyMutators(Mutators::named(RUN_PLUS));
    $held = MutationRequest::of(Paths::of(Path::of('src/Held.php')), WholeSuite::tests())
        ->onlyMutators(Mutators::named(RUN_PLUS));
    $retried = new Pest($at, $shell, Patching::off())
        ->retry(Mutants::of($survivor, $gone, $elsewhere), Seconds::of(20.0), WholeSuite::tests(), Withheld::standard());
    $notFound = Reason::that('Run again alone, Pest made no mutant with this id.');

    expect($retried)->toEqual(Mutants::of(
        $survivor,
        Mutant::of($id, 'n9', $place, $change, MutantStatus::Unjudged, Unmeasured::duration())->because($notFound),
        Interpretation::unjudged($elsewhere, $notFound),
    ))->and($shell->commands())->toEqual([
        adapterInvocation()->mutation($request, WholeSuite::tests(), adapterResults($at)),
        adapterInvocation()->mutation($held, WholeSuite::tests(), adapterResults($at)),
    ]);
});

it('runs a held unit\'s mutant again by the group that holds it, withholding what it is told to', function (): void {
    $at = adapterProject();
    $shell = new ShellFake(static fn(Command $command): Ran => adapterKilled($command, $at));
    $holding = Group::named('holds:src/Money.php');
    $request = MutationRequest::of(Paths::of(Path::of('src/Money.php')), $holding)
        ->onlyMutators(Mutators::named(RUN_PLUS))
        ->withholding(Withheld::of('DEPLOY_*'));

    new Pest($at, $shell, Patching::off())
        ->retry(Mutants::of(adapterMutant()), Seconds::of(20.0), $holding, Withheld::of('DEPLOY_*'));

    expect($shell->commands())->toEqual([adapterInvocation()->mutation($request, $holding, adapterResults($at))]);
});

it('cannot judge a retry whose run failed', function (): void {
    $shell = ShellFake::answering(Ran::finished(succeeded: false, output: 'broken'));

    $retried = new Pest(adapterProject(), $shell, Patching::off())
        ->retry(Mutants::of(adapterMutant()), Seconds::of(20.0), WholeSuite::tests(), Withheld::standard());

    expect($retried)->toEqual(CannotJudge::because("Pest's mutation run failed. Pest said:\nbroken"));
});

it('mutates nothing, and runs nothing, where no file is asked for', function (): void {
    $shell = ShellFake::answering(Ran::finished(succeeded: true, output: ''));
    $request = MutationRequest::of(Paths::none(), WholeSuite::tests());

    expect(new Pest(adapterProject(), $shell, Patching::off())->mutate($request))
        ->toEqual(MutationResult::of(Mutants::none(), 0))
        ->and($shell->commands())->toBe([]);
});

it('refuses a path with a comma, which Pest\'s lists of paths split on', function (): void {
    $shell = ShellFake::answering(Ran::finished(succeeded: true, output: ''));
    $pest = new Pest(adapterProject(), $shell, Patching::off());
    $asked = MutationRequest::of(Paths::of(Path::of('src/a,b.php')), WholeSuite::tests());
    $leftOut = adapterMoney()->leavingOut(Paths::of(Path::of('src/c,d.php'), Path::of('src/e.php')));

    expect($pest->mutate($asked))->toEqual(CannotJudge::because(
        "Pest's --path and --ignore split on commas, so Pest cannot mutate src/a,b.php less .",
    ))->and($pest->mutate($leftOut))->toEqual(CannotJudge::because(
        "Pest's --path and --ignore split on commas, so Pest cannot mutate src/Money.php less src/c,d.php, src/e.php.",
    ))->and($shell->commands())->toBe([]);
});

it('cannot judge a run whose earlier results cannot be removed', function (): void {
    $at = adapterProject();
    mkdir(adapterResults($at), recursive: true);
    $shell = ShellFake::answering(Ran::finished(succeeded: true, output: ''));

    expect(new Pest($at, $shell, Patching::off())->mutate(adapterMoney()))->toEqual(CannotJudge::because(sprintf(
        'An earlier run left %s or the map beside it, and the gate cannot remove them.',
        adapterResults($at),
    )))->and($shell->commands())->toBe([]);
});

it('removes an earlier coverage run\'s map and log before it measures again', function (): void {
    $at = adapterProject();
    $directory = sprintf('%s/.mutation-gate/coverage', $at->root());
    Scratch::write($at->root(), '.mutation-gate/coverage/coverage.php', '<?php return [];');
    Scratch::write($at->root(), '.mutation-gate/coverage/junit.xml', '<testsuites/>');
    $request = CoverageRequest::running(WholeSuite::tests(), Path::of('.mutation-gate/coverage'));
    $seen = [];
    $shell = new ShellFake(static function () use ($directory, &$seen): Ran {
        $seen = [is_file(sprintf('%s/coverage.php', $directory)), is_file(sprintf('%s/junit.xml', $directory))];

        return Ran::finished(succeeded: true, output: '');
    });

    expect(new Pest($at, $shell, Patching::off())->coverage($request))->toEqual(CannotJudge::because(
        sprintf('There is no coverage map at %s/coverage.php, so no test runs any line.', $directory),
    ))->and($seen)->toBe([false, false]);
});

it('cannot measure coverage where an earlier map cannot be removed', function (): void {
    $at = adapterProject();
    mkdir(sprintf('%s/.mutation-gate/coverage/coverage.php', $at->root()), recursive: true);
    $shell = ShellFake::answering(Ran::finished(succeeded: true, output: ''));
    $request = CoverageRequest::running(WholeSuite::tests(), Path::of('.mutation-gate/coverage'));

    expect(new Pest($at, $shell, Patching::off())->coverage($request))->toEqual(CannotJudge::because(sprintf(
        'An earlier run left %s/.mutation-gate/coverage/coverage.php or its JUnit log, '
        . 'and the gate cannot remove them.',
        $at->root(),
    )))->and($shell->commands())->toBe([]);
});

it('finds every @pest-mutate-ignore in the files asked for, running nothing', function (): void {
    $at = adapterProject();
    Scratch::write($at->root(), 'src/Money.php', "<?php\n\n// @pest-mutate-ignore\n");
    $shell = ShellFake::answering(Ran::stopped(''));
    $markers = new Pest($at, $shell, Patching::off())->markers(Paths::of(Path::of('src')));

    expect(array_map(static fn(Marker $marker): string => $marker->where(), iterator_to_array($markers, preserve_keys: false)))
        ->toBe(['src/Money.php:3'])
        ->and($shell->commands())->toBe([]);
});

it('is Pest in the project the gate runs in, as its options say, or the options\' problem', function (): void {
    expect(Pest::fromOptions(Options::none(), Path::of('lib/vendor')))->toEqual(new Pest(
        Project::at('.', Paths::of(Path::of('tests')), Path::of('.mutation-gate'), Path::of('lib/vendor')),
        new ProcessShell(Project::at('.', Paths::none(), Path::of('.mutation-gate'), Path::of('vendor'))->root()),
        Patching::off(),
    ))->and(Pest::fromOptions(Options::ofJson('{"patch": 1}'), Path::of('vendor')))->toEqual(Invalid::because(
        Problem::at('patch', 'Whether the project applies pest:patch is true or false.'),
    ));
});

it('is defined by tests/Pest.php and the PHPUnit config in the project\'s root, by any of its names', function (): void {
    $definitions = new Pest(adapterProject(), ShellFake::answering(Ran::stopped('')), Patching::off())->definitions();

    expect(array_map(static fn(Path $path): string => $path->value(), [...$definitions]))
        ->toBe(['tests/Pest.php', 'phpunit.xml', 'phpunit.dist.xml', 'phpunit.xml.dist']);
});

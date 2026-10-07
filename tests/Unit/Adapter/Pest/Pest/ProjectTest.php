<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Bridges;
use NightWorksIO\MutationGate\Adapter\Pest\Command;
use NightWorksIO\MutationGate\Adapter\Pest\GateVariable;
use NightWorksIO\MutationGate\Adapter\Pest\Patching;
use NightWorksIO\MutationGate\Adapter\Pest\Pest;
use NightWorksIO\MutationGate\Adapter\Pest\ProcessShell;
use NightWorksIO\MutationGate\Adapter\Pest\Project;
use NightWorksIO\MutationGate\Adapter\Process\LocalProcesses;
use NightWorksIO\MutationGate\Adapter\Runtime\CapDirectory;
use NightWorksIO\MutationGate\Cli\SystemClock;
use NightWorksIO\MutationGate\Core\Analysis\Checkable;
use NightWorksIO\MutationGate\Core\Assertion\AssertionStyle;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Config\Triage;
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
use NightWorksIO\MutationGate\Core\Runner\LimitBounds;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Runner\RunnerBehaviour;
use NightWorksIO\MutationGate\Core\Runner\TighterSilence;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\TestName;
use NightWorksIO\MutationGate\Core\Test\TestNames;
use NightWorksIO\MutationGate\Core\Test\TestRow;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Mutator\Engine\Enabled;
use NightWorksIO\MutationGate\Mutator\MutatorSet;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\Mutators\PlusToMinus as AcmePlusToMinus;
use NightWorksIO\MutationGate\Tests\Support\PestCases;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ShellFake;

afterEach(function (): void {
    Scratch::sweep();
});

it('names each test as the plugin names it in a run that lists the tests, withholding what it is told to', function (): void {
    $at = PestCases::project();
    $shell = new ShellFake(static fn(Command $command): Ran => PestCases::named($command, $at));
    $asked = TestIds::of(
        TestId::of(PestCases::RUN_ADDS),
        TestId::of('P\\Tests\\MoneySpec::__pest_evaluable_it_adds#dataset "one"'),
        TestId::of('LegacySpec::decrements#3'),
        TestId::of('P\\Tests\\GoneSpec::__pest_evaluable_it_goes'),
    );
    $names = new Pest($at, $shell, Patching::off(), new CapDirectory(), Triage::standard()->bounds())->names($asked, Withheld::of('CI_JOB_TOKEN'));
    $adds = TestName::in(Path::of('tests/MoneySpec.php'), 'it adds');

    expect($names)->toEqual(TestNames::none()
        ->with(TestId::of(PestCases::RUN_ADDS), $adds)
        ->with(TestId::of('P\\Tests\\MoneySpec::__pest_evaluable_it_adds#dataset "one"'), TestRow::of($adds, '"dataset "one""'))
        ->with(TestId::of('LegacySpec::decrements#3'), TestRow::of(TestName::in(Path::of('tests/LegacySpec.php'), 'decrements'), '#3')))
        ->and($shell->commands())->toEqual([PestCases::invocation()->listingTests(Withheld::of('CI_JOB_TOKEN'), PestCases::names($at))]);
});

it('cannot name the tests where the listing run fails, names nothing, or cannot start afresh', function (): void {
    $at = PestCases::project();
    $failed = ShellFake::answering(Ran::finished(succeeded: false, output: 'Fatal error'));
    $silent = ShellFake::answering(Ran::finished(succeeded: true, output: '   INFO  Available tests:'));
    $asked = TestIds::of(TestId::of(PestCases::RUN_ADDS));
    $unnamed = "Pest did not name the suite's tests. Pest said:\n%s";

    expect(new Pest($at, $failed, Patching::off(), new CapDirectory(), Triage::standard()->bounds())->names($asked, Withheld::standard()))
        ->toEqual(CannotJudge::because(sprintf($unnamed, 'Fatal error')))
        ->and(new Pest($at, $silent, Patching::off(), new CapDirectory(), Triage::standard()->bounds())->names($asked, Withheld::standard()))
        ->toEqual(CannotJudge::because(sprintf($unnamed, '   INFO  Available tests:')));

    mkdir(PestCases::names($at), recursive: true);
    $shell = ShellFake::answering(Ran::finished(succeeded: true, output: ''));

    expect(new Pest($at, $shell, Patching::off(), new CapDirectory(), Triage::standard()->bounds())->names($asked, Withheld::standard()))->toEqual(CannotJudge::because(
        sprintf('An earlier run left %s, and the gate cannot remove it.', PestCases::names($at)),
    ))->and($shell->commands())->toBe([]);
});

it('roots itself in a package that installs Pest, running there with the package\'s own files', function (): void {
    $at = PestCases::project();
    Scratch::write($at->root(), 'packages/billing/vendor/pestphp/pest/bin/pest', '<?php');
    $shell = new ShellFake(static fn(Command $command): Ran => PestCases::named($command, $at));
    $rooted = new Pest($at, $shell, Patching::off(), new CapDirectory(), Triage::standard()->bounds())->rootedAt(Path::of('packages/billing'), Paths::of(Path::of('tests')));
    $package = sprintf('%s/packages/billing', $at->root());
    $names = $rooted instanceof Pest ? $rooted->names(TestIds::of(), Withheld::standard()) : $rooted;

    expect($names)->toEqual(TestNames::none())
        ->and($shell->directories())->toBe([$package])
        ->and($shell->commands()[0]->environment()[GateVariable::Names->value] ?? '')
        ->toBe(sprintf('%s/.mutation-gate/pest/names.json', $package));
});

it('roots itself in a package with the cap it was given, and the tests the package keeps', function (): void {
    $at = PestCases::project();
    Scratch::write($at->root(), 'packages/billing/vendor/pestphp/pest/bin/pest', '<?php');
    $shell = ShellFake::answering(Ran::finished(succeeded: true, output: ''));
    $package = Path::of('packages/billing');
    $spec = Paths::of(Path::of('spec'));
    $rooted = $at->in($package, $spec);

    expect(new Pest($at, $shell, Patching::off(), new CapDirectory(), LimitBounds::between(Seconds::of(30.0), Seconds::of(30.0)))->rootedAt($package, $spec))
        ->toEqual(new Pest($rooted, $shell->in($rooted->root()), Patching::off(), new CapDirectory(), LimitBounds::between(Seconds::of(30.0), Seconds::of(30.0))))
        ->and($rooted->tests())->toEqual($spec);
});

it('cannot root itself in a directory that installs no Pest', function (): void {
    $at = PestCases::project('lib/vendor');
    $shell = ShellFake::answering(Ran::finished(succeeded: true, output: ''));

    expect(new Pest($at, $shell, Patching::off(), new CapDirectory(), Triage::standard()->bounds())->rootedAt(Path::of('packages/billing'), Paths::of(Path::of('tests'))))->toEqual(CannotJudge::because(
        'packages/billing holds no project Pest can run: Pest is not installed in its lib/vendor.',
    ))->and($shell->directories())->toBe([]);
});

it('is Pest in the project the gate runs in, as its options say, or the options\' problem', function (): void {
    $pest = static fn(LimitBounds $bounds): Pest => new Pest(
        Project::at('.', Paths::of(Path::of('tests')), Path::of('.mutation-gate'), Path::of('lib/vendor')),
        new ProcessShell(new LocalProcesses(new SystemClock()), Project::at('.', Paths::none(), Path::of('.mutation-gate'), Path::of('vendor'))->root()),
        Patching::off(),
        new CapDirectory(),
        $bounds->tighterFor(TighterSilence::standard()),
    );

    expect(Pest::fromOptions(Options::none(), Path::of('lib/vendor'), new CapDirectory(), new LocalProcesses(new SystemClock())))->toEqual($pest(LimitBounds::between(Seconds::of(10.0), Seconds::of(300.0))))
        ->and(Pest::fromOptions(Configs::options('{"timeout": 30, "most": 60}'), Path::of('lib/vendor'), new CapDirectory(), new LocalProcesses(new SystemClock())))->toEqual($pest(LimitBounds::between(Seconds::of(30.0), Seconds::of(60.0))))
        ->and(Pest::fromOptions(Configs::options('{"patch": 1}'), Path::of('vendor'), new CapDirectory(), new LocalProcesses(new SystemClock())))->toEqual(Invalid::because(
            Problem::at('patch', 'expected true or false, got 1'),
        ));
});

it('is defined by tests/Pest.php and the PHPUnit config in the project\'s root, by any of its names', function (): void {
    $definitions = new Pest(PestCases::project(), ShellFake::answering(Ran::stopped('')), Patching::off(), new CapDirectory(), Triage::standard()->bounds())->definitions();

    expect(array_map(static fn(Path $path): string => $path->value(), [...$definitions]))
        ->toBe(['tests/Pest.php', 'phpunit.xml', 'phpunit.dist.xml', 'phpunit.xml.dist']);
});

it('reads holds as it loads them, and patched, raises a limit and has every key read its canary; unpatched, raises none', function (): void {
    $canary = Group::named('mutation-canary');
    $shell = ShellFake::answering(Ran::finished(succeeded: true, output: ''));
    $patched = new Pest(PestCases::project(), $shell, Patching::on($canary), new CapDirectory(), Triage::standard()->bounds());
    $unpatched = new Pest(PestCases::project(), $shell, Patching::off(), new CapDirectory(), Triage::standard()->bounds());
    $pest = RunnerBehaviour::standard()
        ->holdingAsLoaded()
        ->runningPerCore()
        ->writingTestsIn(AssertionStyle::Pest);

    expect($patched->behaviour())->toEqual($pest->readingInEveryKey($canary))
        ->and($unpatched->behaviour())->toEqual($pest->openingEachShard());
});

it('gives a mutant as an analyser checks it: its diff put onto the file as Pest prints it', function (): void {
    $at = PestCases::project();
    Scratch::write($at->root(), 'src/Money.php', "<?php\nfunction add(){return 1+1;}\n");
    $diff = "@@ @@\n-    return 1 + 1;\n+    return 1 - 1;";
    $mutant = Mutant::of(
        MutantId::hash(Path::of('src/Money.php'), 'PlusToMinus', $diff, 0),
        'PlusToMinus',
        Location::of(Path::of('src/Money.php'), Line::of(2), Line::of(2)),
        Mutation::of('PlusToMinus', MutatorFamily::Arithmetic, $diff),
        MutantStatus::Survived,
        Seconds::of(0.1),
    );
    $checkable = new Pest($at, ShellFake::answering(Ran::stopped('')), Patching::off(), new CapDirectory(), Triage::standard()->bounds())->checkable($mutant);

    expect($checkable instanceof Checkable ? $checkable->mutant()->text() : '')
        ->toBe("<?php\n\nfunction add()\n{\n    return 1 - 1;\n}");
});

it('makes the registered mutators\' mutants through the bridges it writes for its plugin, naming each for Pest', function (): void {
    $at = PestCases::project();
    $shell = new ShellFake(static fn(Command $command): Ran => PestCases::killed($command, $at));
    $bridges = Bridges::to(Enabled::of(MutatorSet::of(AcmePlusToMinus::class)));
    $file = sprintf('%s/.mutation-gate/mutators/pest/bridges.php', $at->root());

    $result = new Pest($at, $shell, Patching::off(), new CapDirectory(), Triage::standard()->bounds(), bridges: $bridges)->mutate(PestCases::money());

    expect(PestCases::untimed($result))->toEqual(MutationResult::of(Mutants::of(PestCases::mutant()), 0))
        ->and($shell->commands())->toEqual([
            PestCases::invocation()->mutation(PestCases::money(), WholeSuite::tests(), PestCases::results($at), $bridges)
                ->with(['MUTATION_GATE_MUTATORS' => $file]),
        ])
        ->and(is_file($file) ? (string) file_get_contents($file) : '')->toContain("return 'acme/PlusToMinus';");
});

it('cannot judge a run whose options name a class that is not a mutator, and starts no Pest', function (): void {
    $shell = new ShellFake(static fn(): Ran => Ran::finished(succeeded: true, output: ''));
    $why = CannotJudge::because('The pest runner cannot make mutants with stdClass, which is not a mutator.');

    expect(new Pest(PestCases::project(), $shell, Patching::off(), new CapDirectory(), Triage::standard()->bounds(), bridges: Bridges::refusing($why))->mutate(PestCases::money()))
        ->toBe($why)
        ->and($shell->commands())->toBe([]);
});

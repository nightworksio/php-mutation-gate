<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Infection\Command;
use NightWorksIO\MutationGate\Adapter\Infection\Infection;
use NightWorksIO\MutationGate\Adapter\Infection\MemoryScan;
use NightWorksIO\MutationGate\Adapter\Infection\Project;
use NightWorksIO\MutationGate\Adapter\Infection\UnmutatedRuns;
use NightWorksIO\MutationGate\Adapter\Runtime\CapDirectory;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Control\Control;
use NightWorksIO\MutationGate\Core\Control\ControlEnd;
use NightWorksIO\MutationGate\Core\Control\ControlRun;
use NightWorksIO\MutationGate\Core\Control\ControlRuns;
use NightWorksIO\MutationGate\Core\Control\Controls;
use NightWorksIO\MutationGate\Core\Control\PeakLauncher;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\Runner\LimitBounds;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\MemoryUnit;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Support\InfectionShellFake;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** A project of Infection's with a PHPUnit config, `src/Money.php` and the test class that covers it. */
function controlledInfection(): Project
{
    $root = (string) realpath(Scratch::directory());
    Scratch::write($root, 'infection.json5', '{}');
    Scratch::write($root, 'phpunit.xml', '<phpunit/>');
    Scratch::write($root, 'src/Money.php', '<?php // money');
    Scratch::write($root, 'tests/MoneyTest.php', "<?php\n\nnamespace Tests;\n\nfinal class MoneyTest\n{\n}\n");

    return Project::at(Root::of($root), Paths::of(Path::of('tests')), Path::of('.gate'));
}

/** A control of a file by Money's test, allowed this long. */
function infectionControl(string $file, float $limit = 5.0): Control
{
    return Control::of(Path::of($file), TestIds::of(TestId::of('Tests\MoneyTest::testAdds')), Seconds::of($limit));
}

/** What the controls found, failing where they cannot run. */
function infectionControlled(Project $at, InfectionShellFake $shell, Controls $controls, MutationRequest $request): ControlRuns
{
    $runs = new UnmutatedRuns($at, $shell, new CapDirectory())->of($request, $controls);

    return $runs instanceof ControlRuns ? $runs : throw new LogicException($runs->why());
}

$request = MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests());

it('runs each control on a config whose suite holds the files of its tests\' classes, serving its file through Infection\'s interceptor, allowed its limit', function () use ($request): void {
    $at = controlledInfection();
    $shell = InfectionShellFake::answering(Ran::finished(succeeded: true, output: '')->took(Seconds::of(0.8)));

    $runs = infectionControlled($at, $shell, Controls::of(infectionControl('src/Money.php', 6.0)), $request->withholding(Withheld::of('SECRET')));
    $command = $shell->commands()[0];
    $config = sprintf('%s/.gate/infection/unmutated/control-0/phpunit.xml', $at->root());

    expect($runs->of(infectionControl('src/Money.php', 6.0)))->toEqual(ControlRun::passed(Seconds::of(0.8)))
        ->and($command->arguments())->toContain(sprintf('--configuration=%s', $config))
        ->and(file_get_contents($config))->toContain(sprintf('<file>%s/tests/MoneyTest.php</file>', $at->root()))
        ->and(file_get_contents(sprintf('%s/.gate/infection/unmutated/control-0/interceptor.autoload.php', $at->root())))
        ->toContain(sprintf("IncludeInterceptor::intercept('%s/src/Money.php'", $at->root()))
        ->and($command->deadline())->toEqual(Seconds::of(6.0))
        ->and($command->withheld())->toEqual(Withheld::standard()->and(Withheld::of('SECRET')));
});

it('reads how each control ended: passed, failed, or ran out of its limit', function (Ran $ran, ControlEnd $end) use ($request): void {
    $runs = infectionControlled(controlledInfection(), InfectionShellFake::answering($ran), Controls::of(infectionControl('src/Money.php')), $request);

    expect($runs->of(infectionControl('src/Money.php'))->end())->toBe($end);
})->with([
    'passed' => [Ran::finished(succeeded: true, output: ''), ControlEnd::Passed],
    'failed' => [Ran::finished(succeeded: false, output: 'FAILURES!'), ControlEnd::Failed],
    'stopped at its limit' => [Ran::stopped(''), ControlEnd::RanOut],
]);

it('runs the controls one after another, each in a place of its own, and never one whose file is gone, saying why', function () use ($request): void {
    $at = controlledInfection();
    $shell = InfectionShellFake::answering(Ran::finished(succeeded: true, output: ''));

    $runs = infectionControlled($at, $shell, Controls::of(infectionControl('src/Gone.php'), infectionControl('src/Money.php')), $request);

    expect($runs->of(infectionControl('src/Gone.php'))->why())
        ->toBe('A run on Infection\'s config for a mutant needs an unchanged copy of src/Gone.php, which was not made.')
        ->and(array_map(static fn(Command $command): string => $command->arguments()[5], $shell->commands()))
        ->toBe([sprintf('--configuration=%s/.gate/infection/unmutated/control-1/phpunit.xml', $at->root())]);
});

it('starts no control once the request\'s deadline has passed', function () use ($request): void {
    $at = controlledInfection();
    $shell = InfectionShellFake::answering(Ran::finished(succeeded: true, output: ''));

    $runs = infectionControlled($at, $shell, Controls::of(infectionControl('src/Money.php')), $request->within(Seconds::of(0.0)));

    expect($shell->commands())->toBe([])
        ->and($runs->of(infectionControl('src/Money.php'))->why())->toBe(ControlRuns::NOT_RUN);
});

it('caps each control\'s process at the request\'s memory cap, as a mutant\'s run is', function () use ($request): void {
    $at = controlledInfection();
    $shell = InfectionShellFake::answering(Ran::finished(succeeded: true, output: ''));

    infectionControlled($at, $shell, Controls::of(infectionControl('src/Money.php')), $request->cappedAt(MemoryCap::of(64, MemoryUnit::Megabytes)));

    expect($shell->commands()[0]->environment())->toHaveKey(MemoryCap::SCAN_DIR);
});

it('never runs a control of a project with no PHPUnit config, saying why', function () use ($request): void {
    $root = (string) realpath(Scratch::directory());
    Scratch::write($root, 'src/Money.php', '<?php // money');
    $at = Project::at(Root::of($root), Paths::of(Path::of('tests')), Path::of('.gate'));
    $shell = InfectionShellFake::answering(Ran::finished(succeeded: true, output: ''));

    $runs = infectionControlled($at, $shell, Controls::of(infectionControl('src/Money.php')), $request);

    expect($runs->of(infectionControl('src/Money.php'))->why())
        ->toBe(sprintf('A run on Infection\'s config for a mutant needs PHPUnit\'s config, and there is none in %s.', $root))
        ->and($shell->commands())->toBe([]);
});

it('cannot run the controls of a project whose Infection config it cannot read, nor where their memory cap cannot be written', function (string $broken) use ($request): void {
    $at = controlledInfection();
    $capped = $request->cappedAt(MemoryCap::standard());

    if ($broken === 'config') {
        Scratch::write($at->root(), 'infection.json5', 'not json');
    }

    if ($broken === 'cap') {
        mkdir(sprintf('%s/%s', MemoryScan::directoryIn($at), MemoryCap::FILE), recursive: true);
    }

    expect(new UnmutatedRuns($at, InfectionShellFake::answering(Ran::finished(succeeded: true, output: '')), new CapDirectory())->of($capped, Controls::of(infectionControl('src/Money.php'))))
        ->toBeInstanceOf(CannotJudge::class);
})->with(['config', 'cap']);

it('runs each control through the Infection runner', function () use ($request): void {
    $at = controlledInfection();
    $shell = InfectionShellFake::answering(Ran::finished(succeeded: true, output: ''));
    $runs = new Infection($at, $shell, LimitBounds::between(Seconds::of(10.0), Seconds::of(10.0)), nativeMarkersAllowed: false, files: new CapDirectory())
        ->controls($request, Controls::of(infectionControl('src/Money.php')));

    expect($runs instanceof ControlRuns ? $runs->of(infectionControl('src/Money.php'))->end() : $runs)->toBe(ControlEnd::Passed);
});

it('starts each control through the launcher, and gives it the peak the launcher wrote', function () use ($request): void {
    $at = controlledInfection();
    $shell = new InfectionShellFake(static function (Command $command): Ran {
        file_put_contents($command->arguments()[2], '20480');

        return Ran::finished(succeeded: true, output: '');
    });

    $runs = infectionControlled($at, $shell, Controls::of(infectionControl('src/Money.php')), $request);
    $arguments = $shell->commands()[0]->arguments();

    expect(array_slice($arguments, 0, 2))->toBe([PHP_BINARY, PeakLauncher::in($at->own(Control::DIRECTORY))])
        ->and($arguments[3])->toBe(PHP_BINARY)
        ->and($runs->of(infectionControl('src/Money.php'))->peak())->toEqual(PeakLauncher::peakIn('20480', PHP_OS_FAMILY));
});

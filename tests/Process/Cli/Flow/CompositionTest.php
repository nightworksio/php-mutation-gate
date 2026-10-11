<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Cli\Config\Detected;
use NightWorksIO\MutationGate\Cli\Config\Effective;
use NightWorksIO\MutationGate\Cli\FirstParty;
use NightWorksIO\MutationGate\Cli\Flow\Composed;
use NightWorksIO\MutationGate\Cli\Flow\Composition;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\ConfigReads;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Registry\Origin;
use NightWorksIO\MutationGate\Core\Runner\Version;
use NightWorksIO\MutationGate\Core\ThisPackage;
use NightWorksIO\MutationGate\Extension\Extension;
use NightWorksIO\MutationGate\Extension\Extensions;
use NightWorksIO\MutationGate\Tests\Fakes\ExtensionFake;
use NightWorksIO\MutationGate\Tests\Fakes\RunnerFake;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\PeakMemoryFake;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\StoppedClock;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;

$holds = [
    'holds:src/Adapter/GitHub/GitHubPlan.php',
    'holds:src/Cli/CommandLine.php',
    'holds:src/Cli/Config/Chosen.php',
    'holds:src/Cli/Config/ConfigLocation.php',
    'holds:src/Cli/Config/Detected.php',
    'holds:src/Cli/Config/Effective.php',
    'holds:src/Cli/Config/Formats.php',
    'holds:src/Cli/Flow/Adapters.php',
    'holds:src/Cli/Flow/Composition.php',
    'holds:src/Cli/Flow/Wiring.php',
    'holds:src/Core/Config/Definition/ReportEntry.php',
];

afterEach(function (): void {
    Scratch::sweep();
});

/**
 * The composition of a project holding these files, with this package's
 * own extension, and the fake runner's where asked, in these environment
 * variables.
 *
 * @param array<string, string> $files
 */
function compositionOf(array $files, Variables $environment, bool $fake): Composition
{
    $project = Scratch::directory();

    foreach ($files as $path => $contents) {
        Scratch::write($project, $path, $contents);
    }

    $vendor = sprintf('%s/vendor', $project);
    $firstParty = new FirstParty()->extend(new Extensions(Origin::of(ThisPackage::COMPOSER)));
    $extensions = $fake ? new ExtensionFake()->extend($firstParty) : $firstParty;
    $clock = new StoppedClock(Configs::NOW);
    $effective = new Effective(
        $project,
        $extensions,
        new Detected(Directory::at($project), Directory::at($vendor)),
        $clock->now(),
    );

    return new Composition($effective, $extensions, $project, $vendor, $clock, new PeakMemoryFake(NotGiven::value()), $environment);
}

/**
 * The command line, with these options.
 *
 * @param array<string, string|bool> $options
 */
function compositionInput(array $options): ArrayInput
{
    return new ArrayInput($options, new InputDefinition([
        new InputOption('config', mode: InputOption::VALUE_REQUIRED),
        new InputOption('runner', mode: InputOption::VALUE_REQUIRED),
        new InputOption('report', mode: InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY),
        new InputOption('budget', mode: InputOption::VALUE_REQUIRED),
        new InputOption('ci', mode: InputOption::VALUE_REQUIRED),
        new InputOption('no-extensions', mode: InputOption::VALUE_NONE),
    ]));
}

it('composes the settings, the adapters they choose, the gate\'s setup and the reporting', function (): void {
    $composed = compositionOf([
        'mutation-gate.json' => '{"runner": "fake"}',
        'vendor/composer/installed.json' => '{"packages": []}',
    ], Variables::of([]), fake: true)->compose(compositionInput([]));

    expect($composed)->toBeInstanceOf(Composed::class)
        ->and($composed instanceof Composed ? $composed->adapters->runner : $composed)
        ->toEqual(RunnerFake::ofTheFixture())
        ->and($composed instanceof Composed ? $composed->setup->configFile : $composed)
        ->toEqual(Path::of('mutation-gate.json'))
        ->and($composed instanceof Composed ? $composed->setup->installed : $composed)
        ->toEqual(Digest::sha256Of('{"packages": []}'))
        ->and($composed instanceof Composed ? $composed->setup->gate : $composed)->toEqual(Version::of(
            ThisPackage::COMPOSER,
            (string) InstalledVersions::getPrettyVersion(ThisPackage::COMPOSER),
            (string) InstalledVersions::getReference(ThisPackage::COMPOSER),
        ))
        ->and($composed instanceof Composed ? $composed->setup->clock->now() : $composed)
        ->toEqual(new DateTimeImmutable(Configs::NOW))
        ->and($composed instanceof Composed ? $composed->settings->runner()->choice()->use()->value() : $composed)->toBe('fake');
})->group(...$holds);

it('reads no config file and nothing installed where there is none', function (): void {
    $composed = compositionOf([], Variables::of([]), fake: true)->compose(compositionInput(['--runner' => 'fake']));

    expect($composed instanceof Composed ? $composed->setup->configFile : $composed)->toEqual(Absent::setting())
        ->and($composed instanceof Composed ? $composed->setup->installed : $composed)->toEqual(Digest::sha256Of(''));
})->group(...$holds);

it('hands the reporting and the adapters the environment the run was started in', function (): void {
    $composed = compositionOf(
        ['mutation-gate.json' => '{"runner": "fake"}'],
        Variables::of(['GITHUB_ACTIONS' => 'true']),
        fake: true,
    )->compose(compositionInput([]));
    $onMain = RunOn::at(Scope::branch('main'), Scope::branch('main'));
    $reporters = $composed instanceof Composed
        ? $composed->reporting->reporters($composed->settings, $onMain)
        : $composed;

    expect(is_array($reporters) ? count($reporters) : $reporters)->toBe(2)
        ->and($composed instanceof Composed ? $composed->adapters->environment : $composed)
        ->toEqual(Variables::of(['GITHUB_ACTIONS' => 'true']));
})->group(...$holds);

it('says what is wrong with a config it cannot compose', function (
    string $config,
    string $runner,
    Invalid|CannotJudge $wrong,
): void {
    expect(compositionOf(['mutation-gate.json' => $config], Variables::of([]), fake: true)
        ->compose(compositionInput(['--runner' => $runner])))->toEqual($wrong);
})->with([
    'an invalid config' => [
        '{"runner": "fake", "shards": {"max": 0}}',
        'fake',
        fn(): Invalid => Invalid::because(Problem::at('shards.max', 'expected an integer of at least 1, got 0')),
    ],
    'a runner no extension offers' => [
        '{"runner": "fake"}',
        'nowhere',
        fn(): CannotJudge => CannotJudge::because('No runner is registered as "nowhere".'),
    ],
    'an extension that is not one' => [
        '{"runner": "fake", "extensions": ["Nowhere\\\\Gone"]}',
        'fake',
        fn(): CannotJudge => CannotJudge::because(sprintf(
            'the config file names Nowhere\\Gone in extensions, and it is not a class that implements %s.',
            Extension::class,
        )),
    ],
    'a reporter whose options the config gets wrong' => [
        '{"runner": "fake", "reports": [{"use": "badge", "path": "p", "with": {"colors": "x"}}]}',
        'fake',
        fn(): Invalid => Invalid::because(Problem::at(
            'reports[0].with.colors',
            'expected an object of numbers, got "x"',
        )),
    ],
])->group(...$holds);

it('loads the config\'s own extensions, unless asked for this package\'s alone', function (): void {
    $config = sprintf('{"runner": "fake", "extensions": [%s]}', json_encode(ExtensionFake::class));
    $composition = compositionOf(['mutation-gate.json' => $config], Variables::of([]), fake: false);
    $composed = $composition->compose(compositionInput([]));
    $alone = $composition->compose(compositionInput(['--no-extensions' => true]));

    expect($composed instanceof Composed ? $composed->adapters->runner : $composed)->toEqual(RunnerFake::ofTheFixture())
        ->and($alone)->toEqual(CannotJudge::because('No runner is registered as "fake".'));
})->group(...$holds);

it('hands the adapters the files the config file reads beside itself, and none for one that reads none', function (): void {
    $php = compositionOf([
        'mutation-gate.php' => "<?php\n\nrequire __DIR__ . '/settings/shared.php';\n\nreturn NightWorksIO\\MutationGate\\Config\\Gate::configure()->runner(NightWorksIO\\MutationGate\\Config\\Runner::uses('fake'));\n",
        'settings/shared.php' => "<?php\n",
    ], Variables::of([]), fake: true)->compose(compositionInput([]));
    $json = compositionOf(['mutation-gate.json' => '{"runner": "fake"}'], Variables::of([]), fake: true)
        ->compose(compositionInput([]));

    expect($php instanceof Composed ? $php->adapters->configReads : $php)
        ->toEqual(ConfigReads::named(Path::of('settings/shared.php')))
        ->and($json instanceof Composed ? $json->adapters->configReads : $json)->toEqual(ConfigReads::none());
})->group(...$holds);

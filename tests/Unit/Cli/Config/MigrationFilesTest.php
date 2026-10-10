<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Json\JsonConfig;
use NightWorksIO\MutationGate\Adapter\Neon\NeonConfig;
use NightWorksIO\MutationGate\Adapter\Php\PhpConfig;
use NightWorksIO\MutationGate\Adapter\Yaml\YamlConfig;
use NightWorksIO\MutationGate\Cli\CommandLine;
use NightWorksIO\MutationGate\Cli\Config\MigrationFiles;
use NightWorksIO\MutationGate\Cli\Config\NoConfigFile;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\ConfigFile;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Migration\MapValue;
use NightWorksIO\MutationGate\Core\Migration\Migrated;
use NightWorksIO\MutationGate\Core\Migration\Migration;
use NightWorksIO\MutationGate\Core\Migration\Migrations;
use NightWorksIO\MutationGate\Core\Migration\Move;
use NightWorksIO\MutationGate\Core\Migration\Remove;
use NightWorksIO\MutationGate\Core\Migration\Rename;
use NightWorksIO\MutationGate\Core\Migration\Spelling;
use NightWorksIO\MutationGate\Core\Migration\Split;
use NightWorksIO\MutationGate\Core\Migration\SplitPart;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/**
 * A release with one step of each kind (ADR-0026, decision 6): made-up keys
 * moved to real ones, so a migrated file reads with no problem.
 */
function migrationEverySteps(): Migrations
{
    return Migrations::of(Migration::in(
        '2.0.0',
        Rename::of('runnr', 'runner')->spelt(Spelling::replacing('Gate::runnr', 'Gate::runner')),
        Move::of('everything', 'reach.everything')->spelt(Spelling::replacing('Reach::all', 'Reach::everything')),
        MapValue::of('shards.seconds', 1, 600)->spelt(Spelling::retiring('Shards::seconds')),
        Split::of('limits', SplitPart::of('seconds', 'timeouts.seconds'), SplitPart::of('most', 'timeouts.most'))
            ->spelt(Spelling::retiring('Timeouts::limits')),
        Remove::of('legacy', 'nothing reads it')->spelt(Spelling::retiring('Gate::legacy')),
    ));
}

/** The files of a project, with YAML and NEON installed or not. */
function migrationFilesIn(string $project, bool $installed = true): MigrationFiles
{
    return new MigrationFiles($project, static fn(): bool => $installed);
}

const MIGRATION_BUILDER = "<?php\n\nuse NightWorksIO\\MutationGate\\Config\\Gate;\nuse NightWorksIO\\MutationGate\\Config\\Reach;\nuse NightWorksIO\\MutationGate\\Config\\Runner;\nuse NightWorksIO\\MutationGate\\Config\\Shards;\nuse NightWorksIO\\MutationGate\\Config\\Timeouts;\n\nreturn Gate::configure()\n%s;\n";

it('migrates a fixture of each step in each format to a file that reads with no problem', function (string $name, string $old): void {
    $project = Scratch::directory();
    Scratch::write($project, $name, $old);
    $migrated = migrationFilesIn($project)->config(CommandLine::nothing(), migrationEverySteps());
    Scratch::write($project, $name, $migrated instanceof Migrated ? $migrated->after() : $old);
    $file = ConfigFile::at(Path::of(sprintf('%s/%s', $project, $name)), Path::of($project));
    $loader = match (pathinfo($name, PATHINFO_EXTENSION)) {
        'json' => new JsonConfig(),
        'yaml' => new YamlConfig(),
        'neon' => new NeonConfig(),
        default => new PhpConfig(),
    };

    expect($migrated instanceof Migrated ? [$migrated->changes(), $migrated->left()] : $migrated)->toEqual([true, NotGiven::value()])
        ->and($loader->load($file))->toBeInstanceOf(Layer::class);
})->with([
    'rename, JSON' => ['mutation-gate.json', '{"runnr": "pest"}'],
    'rename, YAML' => ['mutation-gate.yaml', "runnr: pest\n"],
    'rename, NEON' => ['mutation-gate.neon', "runnr: pest\n"],
    'rename, PHP' => ['mutation-gate.php', fn(): string => sprintf(MIGRATION_BUILDER, '    ->runnr(Runner::pest())')],
    'move, JSON' => ['mutation-gate.json', '{"everything": ["composer.lock"]}'],
    'move, YAML' => ['mutation-gate.yaml', "everything:\n  - composer.lock\n"],
    'move, NEON' => ['mutation-gate.neon', "everything:\n    - composer.lock\n"],
    'move, PHP' => ['mutation-gate.php', fn(): string => sprintf(MIGRATION_BUILDER, "    ->with(Reach::all('composer.lock'))")],
    'map a value, JSON' => ['mutation-gate.json', '{"shards": {"seconds": 1}}'],
    'map a value, YAML' => ['mutation-gate.yaml', "shards:\n  seconds: 1\n"],
    'map a value, NEON' => ['mutation-gate.neon', "shards:\n    seconds: 1\n"],
    'map a value, PHP' => ['mutation-gate.php', fn(): string => sprintf(MIGRATION_BUILDER, '    ->with(Shards::seconds(1))')],
    'split, JSON' => ['mutation-gate.json', '{"limits": {"seconds": 10, "most": 60}}'],
    'split, YAML' => ['mutation-gate.yaml', "limits:\n  seconds: 10\n  most: 60\n"],
    'split, NEON' => ['mutation-gate.neon', "limits:\n    seconds: 10\n    most: 60\n"],
    'remove, JSON' => ['mutation-gate.json', '{"runner": "pest", "legacy": true}'],
    'remove, YAML' => ['mutation-gate.yaml', "runner: pest\nlegacy: true\n"],
    'remove, NEON' => ['mutation-gate.neon', "runner: pest\nlegacy: true\n"],
    'remove, PHP' => ['mutation-gate.php', fn(): string => sprintf(MIGRATION_BUILDER, "    ->runner(Runner::pest())\n    ->legacy()")],
]);

it('leaves a PHP split for a hand edit, at its line, since one call cannot become several', function (): void {
    $project = Scratch::directory();
    Scratch::write($project, 'mutation-gate.php', sprintf(MIGRATION_BUILDER, '    ->with(Timeouts::limits(10, 60))'));
    $migrated = migrationFilesIn($project)->config(CommandLine::nothing(), migrationEverySteps());

    expect($migrated instanceof Migrated ? [$migrated->changes(), $migrated->left()] : $migrated)->toEqual([false, Invalid::because(
        Problem::at(
            'line 10',
            '`limits` became `timeouts.seconds` and `timeouts.most` in 2.0.0, and migrate cannot make that change here: edit it by hand',
        ),
    )]);
});

it('migrates the baseline the config names, the standard one while the config does not read, and none where there is none', function (): void {
    $project = Scratch::directory();
    $baselines = Migrations::of(Migration::in('2.0.0', Rename::of('floors', 'trees')));
    $none = migrationFilesIn($project)->baseline(CommandLine::nothing(), $baselines);
    Scratch::write($project, 'mutation-gate.baseline.json', '{"format": 1, "floors": {}}');
    Scratch::write($project, 'mutation-gate.json', '{"runnr": "pest"}');
    $standard = migrationFilesIn($project)->baseline(CommandLine::nothing(), $baselines);
    Scratch::write($project, 'mutation-gate.json', '{"baseline": {"path": "kept/baseline.json"}}');
    Scratch::write($project, 'kept/baseline.json', '{"format": 1, "floors": {"src": {"floor": 80}}}');
    $named = migrationFilesIn($project)->baseline(CommandLine::nothing(), $baselines);

    expect($none)->toEqual(NotGiven::value())
        ->and($standard instanceof Migrated ? [$standard->file(), $standard->after()] : $standard)
        ->toBe(['mutation-gate.baseline.json', '{"format": 1, "trees": {}}'])
        ->and($named instanceof Migrated ? [$named->file(), $named->after(), migrationFilesIn($project)->onDisk($named)] : $named)
        ->toBe(['kept/baseline.json', '{"format": 1, "trees": {"src": {"floor": 80}}}', sprintf('%s/kept/baseline.json', $project)]);
});

it('finds no config where the project has none, and cannot migrate one it cannot read, of no format it knows, or that needs a library', function (): void {
    $project = Scratch::directory();
    $none = migrationFilesIn($project)->config(CommandLine::nothing(), migrationEverySteps());
    Scratch::write($project, 'mutation-gate.toml', 'runner = "pest"');
    Scratch::write($project, 'mutation-gate.yaml', 'runner: pest');
    Scratch::write($project, 'mutation-gate.neon', 'runner: pest');
    $config = static fn(string $named, bool $installed = true): object => migrationFilesIn($project, $installed)
        ->config(CommandLine::nothing()->withConfig($named), migrationEverySteps());

    expect($none)->toBeInstanceOf(NoConfigFile::class)
        ->and($config('gone.json'))->toEqual(CannotJudge::because('gone.json could not be read.'))
        ->and($config('mutation-gate.toml'))->toEqual(CannotJudge::because(
            'mutation-gate.toml is no config migrate can read. Name a mutation-gate.php, .json, .yaml, .yml or .neon file.',
        ))
        ->and($config('mutation-gate.yaml', installed: false))->toEqual(CannotJudge::because(
            'mutation-gate.yaml is YAML, which needs symfony/yaml to be migrated. Install it: composer require --dev symfony/yaml',
        ))
        ->and($config('mutation-gate.neon', installed: false))->toEqual(CannotJudge::because(
            'mutation-gate.neon is NEON, which needs nette/neon to be migrated. Install it: composer require --dev nette/neon',
        ));
});

it('runs nothing of a PHP config it migrates, or reads the baseline of, however its top level would act', function (): void {
    $project = Scratch::directory();
    $marker = sprintf('%s/ran', $project);
    Scratch::write($project, 'mutation-gate.php', sprintf(<<<'PHP'
        <?php

        use NightWorksIO\MutationGate\Config\Baseline;
        use NightWorksIO\MutationGate\Config\Gate;

        file_put_contents(%s, 'the config ran');

        throw new RuntimeException('the config ran');

        return Gate::configure()->runnr()->with(Baseline::at('kept/baseline.json'));
        PHP, var_export($marker, return: true)));
    Scratch::write($project, 'kept/baseline.json', '{"format": 1, "floors": {}}');
    $files = migrationFilesIn($project);
    $config = $files->config(CommandLine::nothing(), migrationEverySteps());
    $baseline = $files->baseline(CommandLine::nothing(), Migrations::of(Migration::in('2.0.0', Rename::of('floors', 'trees'))));

    expect(is_file($marker))->toBeFalse()
        ->and($config instanceof Migrated ? $config->after() : $config)->toContain('->runner()')
        ->and($baseline instanceof Migrated ? [$baseline->file(), $baseline->after()] : $baseline)
        ->toBe(['kept/baseline.json', '{"format": 1, "trees": {}}']);
});

it('reads a PHP config\'s baseline from a literal Baseline::at() alone, and the standard one where none is written so', function (): void {
    $project = Scratch::directory();
    Scratch::write($project, 'mutation-gate.baseline.json', '{"format": 1, "floors": {}}');
    Scratch::write($project, 'mutation-gate.php', "<?php\n\nuse NightWorksIO\\MutationGate\\Config\\Baseline;\nuse NightWorksIO\\MutationGate\\Config\\Gate;\n\nreturn Gate::configure()->with(Baseline::at(\$elsewhere), Baseline::requiringImprovement(), Other::at('x'));\n");
    $baseline = migrationFilesIn($project)->baseline(CommandLine::nothing(), Migrations::of(Migration::in('2.0.0', Rename::of('floors', 'trees'))));

    expect($baseline instanceof Migrated ? $baseline->file() : $baseline)->toBe('mutation-gate.baseline.json');
});

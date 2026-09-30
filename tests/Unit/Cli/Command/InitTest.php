<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Neon\NeonConfig;
use NightWorksIO\MutationGate\Adapter\Yaml\YamlConfig;
use NightWorksIO\MutationGate\Core\Config\Document;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Port\ConfigLoader;
use NightWorksIO\MutationGate\Tests\Support\Commands;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

$here = (string) getcwd();

afterEach(function () use ($here): void {
    chdir($here);
    Scratch::sweep();
});

/**
 * `init` run in a copy of a fixture project, from the project's root as the gate runs.
 *
 * @param array<string, mixed> $input
 */
$init = static function (string $project, array $input = []): Commands {
    chdir($project);

    return Commands::run($project, 'init', $input);
};

/** A file of a project, or '' when it is not there. */
$file = static fn(string $project, string $path): string => is_file(sprintf('%s/%s', $project, $path))
    ? (string) file_get_contents(sprintf('%s/%s', $project, $path))
    : '';

it('writes a mutation-gate.php holding what zero-config found, and keeps .mutation-gate/ out of git', function () use (
    $init,
    $file,
): void {
    $project = Scratch::copy('tests/Fixtures/Projects/Laravel');
    $ran = $init($project);

    expect([$ran->code, $ran->output, $ran->errors])->toBe([
        0,
        "Wrote mutation-gate.php with what zero-config found, and added .mutation-gate/ to .gitignore.\n",
        '',
    ])->and($file($project, 'mutation-gate.php'))->toBe(<<<'PHP'
        <?php

        declare(strict_types=1);

        use NightWorksIO\MutationGate\Config\Gate;
        use NightWorksIO\MutationGate\Config\Preset;
        use NightWorksIO\MutationGate\Config\Runner;
        use NightWorksIO\MutationGate\Config\Tree;

        return Gate::configure()
            ->preset(Preset::laravel())
            ->runner(Runner::pest())
            ->trees(Tree::at('app'));

        PHP)->and($file($project, '.gitignore'))->toBe(".mutation-gate/\n");
});

it('writes a mutation-gate.json that names its JSON Schema', function () use ($init, $file): void {
    $project = Scratch::copy('tests/Fixtures/Projects/Library');
    Scratch::write($project, '.gitignore', "/vendor/\n.mutation-gate/\n");
    $ran = $init($project, ['--format' => 'json']);

    expect([$ran->code, $ran->output, $ran->errors])
        ->toBe([0, "Wrote mutation-gate.json with what zero-config found.\n", ''])
        ->and($file($project, 'mutation-gate.json'))->toBe(<<<'JSON'
            {
                "$schema": "vendor/nightworksio/mutation-gate/resources/mutation-gate.schema.json",
                "preset": "library",
                "runner": "infection",
                "trees": [
                    {
                        "path": "src"
                    }
                ]
            }

            JSON)
        ->and($file($project, '.gitignore'))->toBe("/vendor/\n.mutation-gate/\n");
});

it('writes a YAML or NEON config that reads back into what zero-config found', function (
    string $format,
    ConfigLoader $loader,
) use ($init): void {
    $project = Scratch::copy('tests/Fixtures/Projects/Laravel');
    $init($project, ['--format' => $format]);
    $document = $loader->load(Path::of(sprintf('%s/mutation-gate.%s', $project, $format)));

    expect($document instanceof Document ? $document->json() : $document)
        ->toBe('{"preset":"laravel","runner":"pest","trees":[{"path":"app"}]}');
})->with([
    'yaml' => ['yaml', fn(): ConfigLoader => new YamlConfig()],
    'neon' => ['neon', fn(): ConfigLoader => new NeonConfig()],
]);

it('writes a tree phpunit.xml excludes from its source with a floor of 0 and the reason', function () use (
    $init,
    $file,
): void {
    $project = Scratch::copy('tests/Fixtures/Projects/Laravel');
    Scratch::write(
        $project,
        'phpunit.xml',
        '<phpunit><source><include><directory>app</directory></include>'
        . '<exclude><directory>app/Generated</directory></exclude></source></phpunit>',
    );
    $init($project, ['--format' => 'json']);

    expect(json_decode($file($project, 'mutation-gate.json'), associative: true))->toMatchArray(['trees' => [
        ['path' => 'app'],
        ['path' => 'app/Generated', 'floor' => 0, 'reason' => 'phpunit.xml excludes it from <source>'],
    ]]);
});

it('adds .mutation-gate/ to a .gitignore on a line of its own', function (string $before, string $after) use (
    $init,
    $file,
): void {
    $project = Scratch::copy('tests/Fixtures/Projects/Laravel');
    Scratch::write($project, '.gitignore', $before);
    $init($project);

    expect($file($project, '.gitignore'))->toBe($after);
})->with([
    'after a last line without its newline' => ["/vendor", "/vendor\n.mutation-gate/\n"],
    'after a last line with its newline' => ["/vendor\n", "/vendor\n.mutation-gate/\n"],
    'not where it is ignored from the root already' => ["/.mutation-gate/\n", "/.mutation-gate/\n"],
    'not where it is ignored already, written with spaces' => ["  .mutation-gate/  \n", "  .mutation-gate/  \n"],
    'not where it is ignored without its slash' => [".mutation-gate\n", ".mutation-gate\n"],
    'not where it is ignored from the root without its trailing slash' => ["/.mutation-gate\n", "/.mutation-gate\n"],
    'after a line that only starts like it' => [".mutation-gates\n", ".mutation-gates\n.mutation-gate/\n"],
]);

it('writes nothing where a config is already there', function () use ($init, $file): void {
    $project = Scratch::copy('tests/Fixtures/Projects/Configured');
    $before = $file($project, 'mutation-gate.json');
    $ran = $init($project);

    expect([$ran->code, $ran->output, $ran->errors])->toBe([
        2,
        '',
        sprintf(
            "%s is already here, and init writes a config only where there is none.\n",
            Path::of(sprintf('%s/mutation-gate.json', $project))->value(),
        ),
    ])->and($file($project, 'mutation-gate.json'))->toBe($before)
        ->and($file($project, 'mutation-gate.php'))->toBe('');
});

it('writes nothing where zero-config cannot decide', function (string $fixture, string $why) use ($init, $file): void {
    $project = Scratch::copy(sprintf('tests/Fixtures/Projects/%s', $fixture));
    $ran = $init($project);

    expect([$ran->code, $ran->output, $ran->errors])->toBe([2, '', sprintf("%s\n", $why)])
        ->and($file($project, 'mutation-gate.php'))->toBe('')
        ->and($file($project, '.gitignore'))->toBe('');
})->with([
    'two runners installed' => [
        'TwoRunners',
        'Both pestphp/pest-plugin-mutate and infection/infection are installed. '
        . 'Choose one: set runner in the config, or pass --runner.',
    ],
    'two config files' => [
        'TwoConfigs',
        'More than one config file is here: mutation-gate.json, mutation-gate.yaml. '
        . 'Keep one, or name one with --config.',
    ],
]);

it('writes nothing where no tree can be found', function () use ($init, $file): void {
    $project = Scratch::directory();
    Scratch::write($project, 'composer.json', '{"name": "acme/empty"}');
    Scratch::write($project, 'vendor/composer/installed.json', '{"packages": [{"name": "infection/infection"}]}');
    $ran = $init($project);

    expect([$ran->code, $ran->errors])->toBe([
        2,
        "No tree found in the <source> of phpunit.xml or the autoload of composer.json; list trees in config.\n",
    ])->and($file($project, 'mutation-gate.php'))->toBe('');
});

it('takes the runner --runner names over the ones installed', function () use ($init, $file): void {
    $project = Scratch::copy('tests/Fixtures/Projects/TwoRunners');
    Scratch::write($project, 'src/.gitkeep', '');
    Scratch::write($project, 'composer.json', '{"autoload": {"psr-4": {"Acme\\\\": "src/"}}}');
    $init($project, ['--runner' => 'pest', '--format' => 'json']);

    expect(json_decode($file($project, 'mutation-gate.json'), associative: true))
        ->toMatchArray(['preset' => 'library', 'runner' => 'pest', 'trees' => [['path' => 'src']]]);
});

it('writes nothing in a format it does not know', function () use ($init, $file): void {
    $project = Scratch::copy('tests/Fixtures/Projects/Laravel');
    $ran = $init($project, ['--format' => 'toml']);

    expect([$ran->code, $ran->errors])->toBe([2, "--format is php, json, yaml or neon, not \"toml\".\n"])
        ->and($file($project, 'mutation-gate.toml'))->toBe('')
        ->and($file($project, '.gitignore'))->toBe('');
});

it('says so where it cannot read .gitignore', function () use ($init): void {
    $project = Scratch::copy('tests/Fixtures/Projects/Laravel');
    mkdir(sprintf('%s/.gitignore', $project));
    $ran = $init($project);

    expect([$ran->code, $ran->output])->toBe([2, ''])
        ->and($ran->errors)->toEndWith("/.gitignore could not be read.\n");
});

it('writes the file --config names, in the format its extension names', function () use (
    $init,
    $file,
): void {
    $project = Scratch::copy('tests/Fixtures/Projects/Laravel');
    $ran = $init($project, ['--config' => 'ci/gate.json', '--format' => 'php']);

    expect([$ran->code, $ran->output, $ran->errors])->toBe([
        0,
        "Wrote ci/gate.json with what zero-config found, and added .mutation-gate/ to .gitignore.\n",
        '',
    ])->and($file($project, 'ci/gate.json'))->toBe(<<<'JSON'
        {
            "$schema": "vendor/nightworksio/mutation-gate/resources/mutation-gate.schema.json",
            "preset": "laravel",
            "runner": "pest",
            "trees": [
                {
                    "path": "app"
                }
            ]
        }

        JSON)
        ->and($file($project, 'mutation-gate.php'))->toBe('')
        ->and($file($project, '.gitignore'))->toBe(".mutation-gate/\n");
});

it('reads the config it wrote at --config back into what zero-config found', function () use ($init): void {
    $project = Scratch::copy('tests/Fixtures/Projects/Laravel');
    $init($project, ['--config' => 'ci/gate.yml']);
    $shown = Commands::run($project, 'config:show', ['--config' => 'ci/gate.yml']);

    expect(json_decode($shown->output, associative: true))
        ->toMatchArray(['preset' => 'laravel', 'runner' => 'pest', 'trees' => [['path' => 'app', 'exclude' => []]]]);
});

it('writes nothing where the file --config names is in no format it writes', function () use ($init, $file): void {
    $project = Scratch::copy('tests/Fixtures/Projects/Laravel');
    $ran = $init($project, ['--config' => 'ci/gate.toml', '--format' => 'json']);

    expect([$ran->code, $ran->errors])->toBe([
        2,
        "ci/gate.toml is in no format init writes. Name a .php, .json, .yaml, .yml or .neon file.\n",
    ])->and($file($project, 'ci/gate.toml'))->toBe('')
        ->and($file($project, '.gitignore'))->toBe('');
});

it('writes nothing where the file --config names is already there', function () use ($init, $file): void {
    $project = Scratch::copy('tests/Fixtures/Projects/Laravel');
    Scratch::write($project, 'ci/gate.neon', "runner: pest\n");
    $ran = $init($project, ['--config' => 'ci/gate.neon']);

    expect([$ran->code, $ran->output, $ran->errors])->toBe([
        2,
        '',
        sprintf(
            "%s is already here, and init writes a config only where there is none.\n",
            Path::of(sprintf('%s/ci/gate.neon', $project))->value(),
        ),
    ])->and($file($project, 'ci/gate.neon'))->toBe("runner: pest\n");
});

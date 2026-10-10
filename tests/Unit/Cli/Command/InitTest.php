<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Neon\NeonConfig;
use NightWorksIO\MutationGate\Adapter\Yaml\YamlConfig;
use NightWorksIO\MutationGate\Core\Config\ConfigFile;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Port\ConfigLoader;
use NightWorksIO\MutationGate\Tests\Support\Commands;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

$makeHere = static fn(): string => (string) getcwd();

afterEach(function () use ($makeHere): void {
    $here = $makeHere();

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

    return Commands::run($project, 'init', ['--no-measure' => true, ...$input]);
};

/** A file of a project, or '' when it is not there. */
$file = static fn(string $project, string $path): string => is_file(sprintf('%s/%s', $project, $path))
    ? (string) file_get_contents(sprintf('%s/%s', $project, $path))
    : '';

it('writes a mutation-gate.php holding the preset and runner zero-config found, lists the trees, and keeps .mutation-gate/ out of git', function () use (
    $init,
    $file,
): void {
    $project = Scratch::copy('tests/Fixtures/Projects/Laravel');
    $ran = $init($project);

    expect([$ran->code, $ran->output, $ran->errors])->toBe([
        0,
        sprintf("Wrote mutation-gate.php with what zero-config found, and added .mutation-gate/ to .gitignore.\n%s", INIT_NEXT),
        '',
    ])->and($file($project, 'mutation-gate.php'))->toBe(<<<'PHP'
        <?php

        declare(strict_types=1);

        use NightWorksIO\MutationGate\Config\Gate;
        use NightWorksIO\MutationGate\Config\Preset;
        use NightWorksIO\MutationGate\Config\Runner;

        return Gate::configure()
            ->preset(Preset::laravel())
            ->runner(Runner::pest());

        // Zero-config finds these trees, so this file names none:
        //   app

        PHP)->and($file($project, '.gitignore'))->toBe(".mutation-gate/\n");
});

it('writes a mutation-gate.json that names its JSON Schema, and says the trees it holds no comment of', function () use ($init, $file): void {
    $project = Scratch::copy('tests/Fixtures/Projects/Library');
    Scratch::write($project, '.gitignore', "/vendor/\n.mutation-gate/\n");
    $ran = $init($project, ['--format' => 'json']);

    expect([$ran->code, $ran->output, $ran->errors])
        ->toBe([0, sprintf(
            "Wrote mutation-gate.json with what zero-config found.\nZero-config finds the trees src, so the config names none.\n%s",
            INIT_NEXT,
        ), ''])
        ->and($file($project, 'mutation-gate.json'))->toBe(<<<'JSON'
            {
                "$schema": "vendor/nightworksio/mutation-gate/resources/mutation-gate.schema.json",
                "preset": "library",
                "runner": "infection"
            }

            JSON)
        ->and($file($project, '.gitignore'))->toBe("/vendor/\n.mutation-gate/\n");
});

it('writes a YAML or NEON config that reads back into the preset and runner, the trees in a comment', function (
    string $format,
    ConfigLoader $loader,
) use ($init, $file): void {
    $project = Scratch::copy('tests/Fixtures/Projects/Laravel');
    $init($project, ['--format' => $format]);
    $layer = $loader->load(
        ConfigFile::at(Path::of(sprintf('%s/mutation-gate.%s', $project, $format)), Path::of($project)),
    );

    expect($layer instanceof Layer ? Configs::decoded($layer) : Configs::problems($layer))
        ->toBe(['preset' => 'laravel', 'runner' => 'pest'])
        ->and($file($project, sprintf('mutation-gate.%s', $format)))
        ->toEndWith("\n\n# Zero-config finds these trees, so this file names none:\n#   app\n");
})->with([
    'yaml' => ['yaml', fn(): ConfigLoader => new YamlConfig()],
    'neon' => ['neon', fn(): ConfigLoader => new NeonConfig()],
]);

it('lists a tree phpunit.xml excludes from its source with the reason it is exempt', function () use (
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
    $init($project, ['--format' => 'yaml']);

    expect($file($project, 'mutation-gate.yaml'))->toEndWith(
        "#   app\n#   app/Generated (exempt: phpunit.xml excludes it from <source>)\n",
    );
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
        ->toBe([
            '$schema' => 'vendor/nightworksio/mutation-gate/resources/mutation-gate.schema.json',
            'preset' => 'library',
            'runner' => 'pest',
        ]);
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

it('writes the file --config names, in the format its extension names, its paths from its directory', function () use (
    $init,
    $file,
): void {
    $project = Scratch::copy('tests/Fixtures/Projects/Laravel');
    $ran = $init($project, ['--config' => 'ci/gate.json', '--format' => 'php']);
    $other = Scratch::copy('tests/Fixtures/Projects/Laravel');
    $yaml = $init($other, ['--config' => 'ci/gate.yaml']);

    expect([$ran->code, $ran->output, $ran->errors])->toBe([
        0,
        sprintf(
            "Wrote ci/gate.json with what zero-config found, and added .mutation-gate/ to .gitignore.\n"
            . "Zero-config finds the trees app, so the config names none.\n%s",
            INIT_NEXT,
        ),
        '',
    ])->and($file($project, 'ci/gate.json'))->toBe(<<<'JSON'
        {
            "$schema": "../vendor/nightworksio/mutation-gate/resources/mutation-gate.schema.json",
            "preset": "laravel",
            "runner": "pest"
        }

        JSON)
        ->and($file($other, 'ci/gate.yaml'))->toEndWith("#   ../app\n")
        ->and($yaml->code)->toBe(0)
        ->and($file($project, 'mutation-gate.php'))->toBe('')
        ->and($file($project, '.gitignore'))->toBe(".mutation-gate/\n");
});

it('reads the config it wrote at --config back into the preset and runner, the trees left to the tree source', function () use ($init): void {
    $project = Scratch::copy('tests/Fixtures/Projects/Laravel');
    $init($project, ['--config' => 'ci/gate.yml']);
    $shown = Commands::run($project, 'config:show', ['--config' => 'ci/gate.yml']);

    $effective = json_decode($shown->output, associative: true);

    expect($effective)->toMatchArray(['preset' => 'laravel', 'runner' => ['use' => 'pest', 'memory' => '1G']])
        ->and(is_array($effective) && array_key_exists('trees', $effective))->toBeFalse();
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

it('starts the config from an Infection config with --from, and says what became of each of its keys', function () use ($init, $file): void {
    $project = Scratch::copy('tests/Fixtures/Projects/TwoRunners');
    Scratch::write($project, '.gitignore', ".mutation-gate/\n");
    Scratch::write($project, 'phpunit.xml', '<?xml version="1.0"?><phpunit><source><include><directory>app</directory></include></source></phpunit>');
    Scratch::write($project, 'infection.json5', '{source: {directories: ["src"]}, minMsi: 80, threads: 4}');
    $ran = $init($project, ['--from' => null, '--format' => 'json']);

    expect([$ran->code, $ran->errors])->toBe([0, ''])
        ->and($ran->output)->toBe(implode("\n", [
            'Wrote mutation-gate.json with infection.json5 and what zero-config found.',
            'What became of each key of infection.json5:',
            '  source.directories: imported as trees: src, which replace the list the tree source finds',
            '  minMsi: imported as the floor of every tree, 80.00',
            '  threads: stays in infection.json5, because the gate overrides it for each run',
            'phpunit.xml\'s <source> names app, which infection.json5\'s source.directories does not.',
            'infection.json5\'s source.directories names src, which phpunit.xml\'s <source> does not.',
            'Delete these keys from infection.json5, since the gate no longer reads them there: source.directories, minMsi.',
            'Run mutation-gate locally once.',
            'It writes the baseline at what the gate measures, and says if a tree is below the imported floor.',
            INIT_NEXT,
        ]))
        ->and(json_decode($file($project, 'mutation-gate.json'), associative: true))->toMatchArray([
            'runner' => 'infection',
            'trees' => [['path' => 'src', 'floor' => 80]],
        ]);
});

it('gives the trees zero-config found the floor an Infection config imports, and writes them, listing none', function () use ($init, $file): void {
    $project = Scratch::copy('tests/Fixtures/Projects/Laravel');
    Scratch::write($project, 'infection.json5', '{minMsi: 80}');
    $ran = $init($project, ['--from' => null, '--format' => 'yaml']);

    expect($ran->code)->toBe(0)
        ->and($file($project, 'mutation-gate.yaml'))->toContain("    path: app\n    floor: 80\n")
        ->and($file($project, 'mutation-gate.yaml'))->not->toContain('Zero-config finds');
});

it('imports the file import names, as init --from does', function () use ($file): void {
    $project = Scratch::copy('tests/Fixtures/Projects/Library');
    Scratch::write($project, 'ci/infection.json', '{"timeout": 9}');
    chdir($project);
    $ran = Commands::run($project, 'import', ['file' => 'ci/infection.json', '--format' => 'json']);

    expect($ran->code)->toBe(0)
        ->and($ran->output)->toStartWith("Wrote mutation-gate.json with ci/infection.json and what zero-config found, and added .mutation-gate/ to .gitignore.\n")
        ->and($ran->output)->toContain("  timeout: imported as timeouts.seconds: 9\n")
        ->and(json_decode($file($project, 'mutation-gate.json'), associative: true))->toMatchArray(['timeouts' => ['seconds' => 9]]);
});

it('writes nothing from an Infection config it cannot take over from, or one that is not there', function () use ($init, $file): void {
    $project = Scratch::copy('tests/Fixtures/Projects/Library');
    Scratch::write($project, 'infection.json5', '{testFramework: "phpspec"}');
    $refused = $init($project, ['--from' => null]);
    $missing = $init($project, ['--from' => 'nowhere.json5']);
    $none = $init(Scratch::copy('tests/Fixtures/Projects/Library'), ['--from' => null]);

    expect([$refused->code, $refused->errors])->toBe([
        2,
        "infection.json5 sets testFramework to phpspec. The gate runs Infection with PHPUnit alone, so it cannot judge that suite.\n",
    ])->and([$missing->code, $missing->errors])->toBe([2, "nowhere.json5 is not here to import from.\n"])
        ->and([$none->code, $none->errors])->toBe([2, "There is no infection.json5, infection.json or .dist of either here to import from.\n"])
        ->and($file($project, 'mutation-gate.php'))->toBe('');
});

/**
 * `init` run in a copy of the Laravel fixture, with these files written into it first.
 *
 * @param  array<string, string|bool|null> $input
 * @param  array<string, string>           $files
 * @return list{string, Commands}
 */
function initCi(array $input, array $files = []): array
{
    $project = Scratch::copy('tests/Fixtures/Projects/Laravel');

    foreach ($files as $path => $text) {
        Scratch::write($project, $path, $text);
    }

    chdir($project);
    $ran = Commands::run($project, 'init', ['--no-measure' => true, ...$input]);

    return [$project, $ran];
}

/**
 * What `init` answered, and the config it wrote, where it refuses.
 *
 * @param  array<string, string|bool|null> $input
 * @param  array<string, string>           $files
 * @return list{int, string, string, string}
 */
function initCiRefused(array $input, array $files = []): array
{
    [$project, $ran] = initCi($input, $files);

    return [$ran->code, $ran->output, $ran->errors, initCiFile($project, 'mutation-gate.php')];
}

/** A file of a project, or '' when it is not there. */
function initCiFile(string $project, string $path): string
{
    $file = sprintf('%s/%s', $project, $path);

    return is_file($file) ? (string) file_get_contents($file) : '';
}

/** Code for a full run's estimate to count: six lines. */
const INIT_CI_MONEY = <<<'PHP'
    <?php

    final class Money
    {
        public function add(int $cents): int
        {
            return $cents + 1;
        }
    }

    PHP;

const INIT_CI_WROTE = "Wrote mutation-gate.php with what zero-config found, and added .mutation-gate/ to .gitignore.\n";

/**
 * What init says last, outside a repository: a fixture's full run estimated
 * from its few lines of code, as --no-measure asks, and what to run next,
 * where git adds nothing.
 */
const INIT_NEXT = <<<'SAID'
    A full run is estimated at about 0s in one job, from its lines of code. A coverage run of the suite,
    which init runs without --no-measure, measures it.
    Next:
      vendor/bin/mutation-gate doctor
      vendor/bin/mutation-gate

    SAID;

const INIT_CI_UNPINNED
    = 'Composer did not install the gate here, so the definition names <the commit of a release>: pin the commit of a release.';

it('writes the GitHub one-step action --single asks for, and says which check to require', function (): void {
    [$project, $ran] = initCi(['--ci' => 'github', '--single' => true]);
    $workflow = initCiFile($project, '.github/workflows/mutation.yml');

    expect([$ran->code, $ran->output, $ran->errors])->toBe([0, sprintf("%s%s\n%s", INIT_CI_WROTE, implode("\n", [
        'Wrote .github/workflows/mutation.yml.',
        'It is the one-step action, as --single asks.',
        'Require the check `mutation / verdict` in the protection of main.',
        INIT_CI_UNPINNED,
    ]), INIT_NEXT), ''])
        ->and($workflow)->toContain("branches: ['main']")
        ->and($workflow)->toContain("php-version: '8.5'")
        ->and($workflow)->toContain("runner: 'pest'")
        ->and($workflow)->toContain("nightworksio/php-mutation-gate@<the commit of a release>\n")
        ->and($workflow)->not->toContain('%%');
});

it('picks the GitHub definition a full run\'s estimate fits, where neither is asked for', function (
    string $perLine,
    string $picked,
    string $check,
): void {
    [$project, $ran] = initCi(['--ci' => 'github'], [
        'mutation-gate.json' => sprintf(
            '{"runner": "pest", "trees": [{"path": "app"}], "costs": {"secondsPerLine": {"": %s}}}',
            $perLine,
        ),
        'app/Money.php' => INIT_CI_MONEY,
    ]);

    expect($ran->code)->toBe(0)
        ->and($ran->output)->toStartWith("mutation-gate.json is already here, so init makes only what --ci, --editor and --hook ask for.\n")
        ->and($ran->output)->toContain($picked)
        ->and($ran->output)->toContain(sprintf('Require the check `%s` in the protection of main.', $check))
        ->and(initCiFile($project, '.github/workflows/mutation.yml'))->not->toBe('');
})->with([
    'within one shard' => [
        '0.5',
        'It is the one-step action: a full run is estimated at',
        'mutation / verdict',
    ],
    'more than one shard' => [
        '1000',
        'It is the reusable workflow: a full run is estimated at',
        'mutation / verdict',
    ],
]);

it('says the one-step action is taken where a full run cannot be estimated', function (): void {
    [, $ran] = initCi(['--ci' => 'github'], [
        'mutation-gate.json' => '{"runner": "pest", "treeSource": "\\\\Acme\\\\NoSource", "trees": [{"path": "app"}]}',
    ]);

    expect($ran->output)->toContain('It is the one-step action, since a full run cannot be estimated: ');
});

it('writes GitLab\'s template and prints the jobs to add to .gitlab-ci.yml', function (): void {
    [$project, $ran] = initCi(['--ci' => 'gitlab']);

    expect($ran->code)->toBe(0)
        ->and($ran->output)->toStartWith(sprintf("%sWrote .gitlab/mutation-gate.yml.\nAdd this to .gitlab-ci.yml:\n\n", INIT_CI_WROTE))
        ->and($ran->output)->toContain("  - local: '.gitlab/mutation-gate.yml'")
        ->and($ran->output)->not->toContain('Require the check')
        ->and(initCiFile($project, '.gitlab/mutation-gate.yml'))->toContain('.mutation-gate:')
        ->and(initCiFile($project, '.gitlab-ci.yml'))->toBe('');
});

it('writes Buildkite\'s pipeline, names it as the definition in the config, and prints the upload step', function (): void {
    [$project, $ran] = initCi(['--ci' => 'buildkite', '--format' => 'json']);

    expect($ran->code)->toBe(0)
        ->and($ran->output)->toContain("Wrote .buildkite/mutation-gate.yml.\nAdd this to the pipeline Buildkite runs:\n\n")
        ->and($ran->output)->toContain("buildkite-agent pipeline upload '.buildkite/mutation-gate.yml'")
        ->and(initCiFile($project, '.buildkite/mutation-gate.yml'))->toContain("label: 'mutation: plan'")
        ->and($ran->output)->not->toContain('Set ci.buildkite.definition')
        ->and(substr_count($ran->output, "Any branch can save a cache under any key on Buildkite, so a branch can plant proofs that main's runs then trust.\nKeep the ledger in S3 with credentials only main's runs hold, and drop the cache steps."))->toBe(1)
        ->and(initCiFile($project, 'mutation-gate.json'))->toContain('"definition": ".buildkite/mutation-gate.yml"');
});

it('writes Azure\'s template, names it and the default branch in the config, and prints the line that takes it in', function (): void {
    [$project, $ran] = initCi(['--ci' => 'azure', '--format' => 'json']);
    $config = json_decode(initCiFile($project, 'mutation-gate.json'), associative: true);

    expect($ran->code)->toBe(0)
        ->and($ran->output)->toEndWith(sprintf(
            "Wrote .azure/mutation-gate.yml.\nAdd this to azure-pipelines.yml:\n\njobs:\n  - template: '.azure/mutation-gate.yml'\n\n%s",
            INIT_NEXT,
        ))
        ->and(initCiFile($project, '.azure/mutation-gate.yml'))->toContain("  - job: mutation_plan\n")
        ->and(is_array($config) ? $config['ci'] : null)
        ->toBe(['defaultBranch' => 'main', 'azure' => ['definition' => '.azure/mutation-gate.yml']]);
});

it('writes a PHP config for Azure DevOps that names its template and default branch, and loads', function (): void {
    [$project, $ran] = initCi(['--ci' => 'azure']);
    $config = initCiFile($project, 'mutation-gate.php');

    expect($ran->code)->toBe(0)
        ->and($config)->toContain("Ci::defaultBranch('main')")
        ->and($config)->toContain("Pipeline::azureDefinition('.azure/mutation-gate.yml')")
        ->and(Commands::run($project, 'config:show', [])->code)->toBe(0);
});

it('prints CircleCI\'s config to add to .circleci/config.yml, and writes none', function (): void {
    [$project, $ran] = initCi(['--ci' => 'circleci']);

    expect($ran->code)->toBe(0)
        ->and($ran->output)->toStartWith(sprintf("%sAdd this to .circleci/config.yml:\n\n# Set each image", INIT_CI_WROTE))
        ->and($ran->output)->toContain("\nversion: 2.1\n")
        ->and(substr_count($ran->output, "Any branch can save a cache under any key on CircleCI, so a branch can plant proofs that main's runs then trust.\nKeep the ledger in S3 with credentials only main's runs hold, and drop the cache steps."))->toBe(1)
        ->and(initCiFile($project, '.circleci/config.yml'))->toBe('');
});

it('says on CircleCI and Buildkite, with a config kept, that their caches keep no ledger safe', function (string $ci, string $named): void {
    [, $ran] = initCi(['--ci' => $ci], ['mutation-gate.json' => '{"runner": "pest", "trees": [{"path": "app"}]}']);

    expect($ran->code)->toBe(0)
        ->and($ran->output)->toContain(sprintf("Any branch can save a cache under any key on %s, so a branch can plant proofs that main's runs then trust.", $named));
})->with([['circleci', 'CircleCI'], ['buildkite', 'Buildkite']]);

it('prints Bitbucket\'s pipelines to add to bitbucket-pipelines.yml, writes none, and names the default branch in the config', function (): void {
    [$project, $ran] = initCi(['--ci' => 'bitbucket', '--format' => 'json']);
    $config = json_decode(initCiFile($project, 'mutation-gate.json'), associative: true);

    expect($ran->code)->toBe(0)
        ->and($ran->output)->toContain("\nAdd this to bitbucket-pipelines.yml:\n\n# Written by `mutation-gate init --ci=bitbucket`")
        ->and($ran->output)->toContain("      - final: *mutation-verdict\n")
        ->and(initCiFile($project, 'bitbucket-pipelines.yml'))->toBe('')
        ->and(is_array($config) ? $config['ci'] : null)->toBe(['defaultBranch' => 'main']);
});

it('writes for Bitbucket where the project holds its pipelines, and --ci names no CI', function (): void {
    [$project, $ran] = initCi(['--ci' => null], ['bitbucket-pipelines.yml' => "pipelines: {}\n"]);

    expect($ran->code)->toBe(0)
        ->and($ran->output)->toContain('Add this to bitbucket-pipelines.yml:')
        ->and(initCiFile($project, 'bitbucket-pipelines.yml'))->toBe("pipelines: {}\n");
});

it('prints Jenkins\' pipeline to add to the Jenkinsfile, writes none, and names the default branch in the config', function (): void {
    [$project, $ran] = initCi(['--ci' => 'jenkins', '--format' => 'json']);
    $config = json_decode(initCiFile($project, 'mutation-gate.json'), associative: true);

    expect($ran->code)->toBe(0)
        ->and($ran->output)->toContain("\nAdd this to Jenkinsfile:\n\n// Written by `mutation-gate init --ci=jenkins`")
        ->and($ran->output)->toContain("    MUTATION_GATE_DEFAULT_BRANCH = 'main'\n")
        ->and(initCiFile($project, 'Jenkinsfile'))->toBe('')
        ->and(is_array($config) ? $config['ci'] : null)->toBe(['defaultBranch' => 'main']);
});

it('writes for Jenkins where the project holds a Jenkinsfile, and prints for the one a config already here names', function (): void {
    [$project, $ran] = initCi(['--ci' => null], ['Jenkinsfile' => "pipeline {}\n"]);
    [, $named] = initCi(['--ci' => 'jenkins'], [
        'mutation-gate.json' => '{"runner": "pest", "trees": [{"path": "app"}], "ci": {"jenkins": {"definition": "ci/Jenkinsfile"}}}',
    ]);

    expect($ran->code)->toBe(0)
        ->and($ran->output)->toContain('Add this to Jenkinsfile:')
        ->and(initCiFile($project, 'Jenkinsfile'))->toBe("pipeline {}\n")
        ->and($named->output)->toContain('Add this to ci/Jenkinsfile:');
});

it('prints every file with --dry-run, or --stdout, the config and the .gitignore line among them, and writes none', function (
    string $option,
): void {
    [$project, $ran] = initCi(['--ci' => 'github', '--single' => true, $option => true]);

    expect($ran->code)->toBe(0)
        ->and($ran->output)->toStartWith("mutation-gate.php:\n\n<?php\n")
        ->and($ran->output)->toContain(".gitignore gains the line .mutation-gate/\n.github/workflows/mutation.yml:\n\n# Written by `mutation-gate init --ci=github`")
        ->and($ran->output)->not->toContain('Next:')
        ->and(initCiFile($project, '.github/workflows/mutation.yml'))->toBe('')
        ->and(initCiFile($project, 'mutation-gate.php'))->toBe('')
        ->and(initCiFile($project, '.gitignore'))->toBe('');
})->with(['--dry-run', '--stdout']);

it('writes for the one CI the project\'s files show, where --ci names none', function (): void {
    [$project, $ran] = initCi(['--ci' => null], ['.gitlab-ci.yml' => "stages: [test]\n"]);

    expect($ran->code)->toBe(0)
        ->and($ran->output)->toContain('Wrote .gitlab/mutation-gate.yml.')
        ->and(initCiFile($project, '.gitlab-ci.yml'))->toBe("stages: [test]\n");
});

it('writes nothing where --ci names no CI it writes for, or where the files show none or several', function (): void {
    $refused = initCiRefused(...);
    $nothing = static fn(string $why): array => [2, '', sprintf("%s\n", $why), ''];
    $unwritten = 'init --ci writes a definition for github, gitlab, buildkite, circleci, azure, bitbucket and jenkins, not for %s.';

    expect($refused(['--ci' => null]))
        ->toBe($nothing('No CI is detected here, so init writes nothing. Name one with --ci=<name>.'))
        ->and($refused(['--ci' => null], ['.gitlab-ci.yml' => "stages: [test]\n", '.circleci/config.yml' => "version: 2.1\n"]))
        ->toBe($nothing('gitlab, circleci are all detected here, so init writes nothing. Name one with --ci=<name>.'))
        ->and($refused(['--ci' => 'teamcity']))->toBe($nothing(sprintf($unwritten, 'teamcity')))
        ->and($refused(['--ci' => 'json']))->toBe($nothing(sprintf($unwritten, 'json')))
        ->and($refused(['--ci' => 'gitlab', '--sharded' => true]))
        ->toBe($nothing('--sharded chooses GitHub\'s definition, so it takes --ci=github.'))
        ->and($refused(['--ci' => 'github', '--sharded' => true, '--single' => true]))
        ->toBe($nothing('--sharded and --single each choose one; pass one of them.'));
});

it('writes nothing where the definition it would write is already here', function (): void {
    [$project, $ran] = initCi(['--ci' => 'github'], ['.github/workflows/mutation.yml' => "name: ours\n"]);

    expect([$ran->code, $ran->output, $ran->errors])->toBe([
        2,
        '',
        ".github/workflows/mutation.yml is already here, and init --ci writes a definition only where there is none. --stdout prints it instead.\n",
    ])->and(initCiFile($project, '.github/workflows/mutation.yml'))->toBe("name: ours\n")
        ->and(initCiFile($project, 'mutation-gate.php'))->toBe('');
});

it('prints a definition already here with --stdout, as it does any other', function (): void {
    [$project, $ran] = initCi(
        ['--ci' => 'github', '--single' => true, '--stdout' => true],
        ['.github/workflows/mutation.yml' => "name: ours\n"],
    );

    expect($ran->code)->toBe(0)
        ->and($ran->output)->toContain(".github/workflows/mutation.yml:\n\n")
        ->and(initCiFile($project, '.github/workflows/mutation.yml'))->toBe("name: ours\n");
});

it('sets VS Code up with --editor=vscode beside the config, and beside one already here', function (): void {
    [$project, $ran] = initCi(['--editor' => 'vscode']);
    [$configured, $kept] = initCi(['--editor' => 'vscode'], [
        'mutation-gate.json' => '{"runner": "pest", "trees": [{"path": "app"}]}',
    ]);

    expect([$ran->code, $ran->output, $ran->errors])
        ->toBe([0, sprintf("%sWrote .vscode/tasks.json.\nWrote .vscode/extensions.json.\n%s", INIT_CI_WROTE, INIT_NEXT), ''])
        ->and(initCiFile($project, '.vscode/tasks.json'))->toContain('"label": "mutation-gate: watch"')
        ->and($kept->output)->toBe(sprintf(
            "%s is already here, so init makes only what --ci, --editor and --hook ask for.\n%s",
            'mutation-gate.json',
            "Wrote .vscode/tasks.json.\nWrote .vscode/extensions.json.\n",
        ))
        ->and(initCiFile($configured, 'mutation-gate.json'))->toBe('{"runner": "pest", "trees": [{"path": "app"}]}');
});

it('makes the CI definition and the editor\'s files in one init', function (): void {
    [, $ran] = initCi(['--ci' => 'gitlab', '--editor' => 'vscode', '--stdout' => true]);

    expect($ran->code)->toBe(0)
        ->and($ran->output)->toContain(".gitlab/mutation-gate.yml:\n\n")
        ->and($ran->output)->toContain(".vscode/tasks.json:\n\n")
        ->and($ran->output)->toContain(".vscode/extensions.json:\n\n");
});

it('writes nothing for an editor it does not set up', function (): void {
    expect(initCiRefused(['--editor' => 'phpstorm']))
        ->toBe([2, '', "phpstorm is no editor init --editor sets up. Name vscode.\n", '']);
});

it('sets a hook manager up with --hook beside the config, and beside one already here', function (): void {
    [$project, $ran] = initCi(['--hook' => 'pre-commit']);
    [$configured, $kept] = initCi(['--hook' => 'captainhook'], [
        'mutation-gate.json' => '{"runner": "pest", "trees": [{"path": "app"}]}',
    ]);

    expect([$ran->code, $ran->output, $ran->errors])->toBe([
        0,
        sprintf("%sWrote .pre-commit-config.yaml.\nThen install its hooks: pre-commit install\n%s", INIT_CI_WROTE, INIT_NEXT),
        '',
    ])
        ->and(initCiFile($project, '.pre-commit-config.yaml'))->toContain('- id: mutation-gate-pre-push')
        ->and($kept->output)->toBe(sprintf(
            "%s is already here, so init makes only what --ci, --editor and --hook ask for.\n%s",
            'mutation-gate.json',
            "Wrote captainhook.json.\nThen install its hooks: vendor/bin/captainhook install\n",
        ))
        ->and(initCiFile($configured, 'captainhook.json'))->toContain('pre-push --stdin={$STDIN}');
});

it('makes the editor\'s files and the hook manager\'s config in one init, printed with --stdout', function (): void {
    [$project, $ran] = initCi(['--editor' => 'vscode', '--hook' => 'grumphp', '--stdout' => true]);

    expect($ran->code)->toBe(0)
        ->and($ran->output)->toContain(".vscode/tasks.json:\n\n")
        ->and($ran->output)->toContain("grumphp.yml:\n\ngrumphp:\n")
        ->and(initCiFile($project, 'grumphp.yml'))->toBe('');
});

it('writes nothing for a hook manager it does not set up', function (): void {
    expect(initCiRefused(['--hook' => 'husky']))->toBe([
        2,
        '',
        "husky is nowhere init --hook sets hooks up. Name one of git|captainhook|grumphp|pre-commit|none.\n",
        '',
    ]);
});

it('sets no hook manager up after the editor\'s files could not be', function (): void {
    [$project, $ran] = initCi(['--editor' => 'vscode', '--hook' => 'captainhook'], ['.vscode/tasks.json/inside' => '']);

    expect($ran->code)->toBe(2)
        ->and(initCiFile($project, 'captainhook.json'))->toBe('');
});

it('says what it wrote before a hook manager\'s config it could not read', function (): void {
    [$project, $ran] = initCi(['--hook' => 'captainhook'], ['captainhook.json/inside' => '']);

    expect($ran->code)->toBe(2)
        ->and($ran->errors)->toBe(sprintf("%s%s/captainhook.json could not be read.\n", INIT_CI_WROTE, $project));
});

it('writes nothing for a CI where the default branch would run as code', function (string $ci): void {
    [$project, $ran] = initCi(['--ci' => $ci], [
        'mutation-gate.json' => '{"runner": "pest", "trees": [{"path": "app"}], "ci": {"defaultBranch": "main$(id)"}}',
    ]);

    expect([$ran->code, $ran->output])->toBe([2, ''])
        ->and($ran->errors)->toStartWith('The default branch "main$(id)" cannot go into a CI definition')
        ->and(initCiFile($project, '.github/workflows/mutation.yml'))->toBe('')
        ->and(initCiFile($project, '.gitlab/mutation-gate.yml'))->toBe('')
        ->and(initCiFile($project, '.buildkite/mutation-gate.yml'))->toBe('')
        ->and(initCiFile($project, '.azure/mutation-gate.yml'))->toBe('');
})->with(['github', 'gitlab', 'buildkite', 'circleci', 'azure', 'bitbucket', 'jenkins']);

it('says which file of the gate\'s jobs a config already here must name, where it names another', function (
    string $ci,
    string $written,
): void {
    [, $ran] = initCi(['--ci' => $ci], [
        'mutation-gate.json' => '{"runner": "pest", "trees": [{"path": "app"}]}',
    ]);
    [, $named] = initCi(['--ci' => $ci], [
        'mutation-gate.json' => sprintf(
            '{"runner": "pest", "trees": [{"path": "app"}], "ci": {"%s": {"definition": "%s"}}}',
            $ci,
            $written,
        ),
    ]);

    expect($ran->output)->toContain(sprintf(
        "Set ci.%s.definition to %s in the config: reach and the proof key read it.\n",
        $ci,
        $written,
    ))->and($named->output)->not->toContain(sprintf('Set ci.%s.definition', $ci));
})->with([
    'Buildkite' => ['buildkite', '.buildkite/mutation-gate.yml'],
    'Azure DevOps' => ['azure', '.azure/mutation-gate.yml'],
]);

it('says to name the default branch in a config already here that names none, for a CI that names none', function (
    string $ci,
): void {
    [, $ran] = initCi(['--ci' => $ci], [
        'mutation-gate.json' => '{"runner": "pest", "trees": [{"path": "app"}]}',
    ]);
    [, $named] = initCi(['--ci' => $ci], [
        'mutation-gate.json' => '{"runner": "pest", "trees": [{"path": "app"}], "ci": {"defaultBranch": "main"}}',
    ]);

    expect($ran->output)->toContain(
        "Set ci.defaultBranch to main in the config, the branch the definition is written for: this CI names none.\n",
    )->and($named->output)->not->toContain('Set ci.defaultBranch');
})->with(['azure', 'bitbucket', 'jenkins']);

it('says nothing of the default branch in a config already here, for any other CI', function (
    string $ci,
): void {
    [, $ran] = initCi(['--ci' => $ci], [
        'mutation-gate.json' => '{"runner": "pest", "trees": [{"path": "app"}]}',
    ]);

    expect($ran->output)->not->toContain('Set ci.defaultBranch');
})->with(['github', 'gitlab', 'buildkite', 'circleci']);

it('says what it wrote before a file it could not write', function (): void {
    [$project, $ran] = initCi(['--editor' => 'vscode'], ['.vscode/tasks.json/inside' => '']);

    expect($ran->code)->toBe(2)
        ->and($ran->errors)->toBe(sprintf(
            "%s%s/.vscode/tasks.json could not be read.\n",
            INIT_CI_WROTE,
            $project,
        ))
        ->and(initCiFile($project, 'mutation-gate.php'))->not->toBe('');
});

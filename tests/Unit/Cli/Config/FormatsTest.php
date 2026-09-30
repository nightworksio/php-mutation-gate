<?php

declare(strict_types=1);

use Nette\Neon\Neon;
use NightWorksIO\MutationGate\Adapter\Json\JsonConfig;
use NightWorksIO\MutationGate\Adapter\Yaml\YamlConfig;
use NightWorksIO\MutationGate\Cli\Config\Formats;
use NightWorksIO\MutationGate\Cli\Config\Registered;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\ConfigFile;
use NightWorksIO\MutationGate\Core\Config\Format;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Config\ProjectRoot;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Extension\Extensions;
use NightWorksIO\MutationGate\Extension\Origin;
use NightWorksIO\MutationGate\Port\ConfigLoader;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use Symfony\Component\Yaml\Yaml;

/** This package's registry, with YAML and NEON installed or not. */
$registry = static fn(bool $installed): Extensions => Registered::config(
    new Extensions(Origin::of('nightworksio/mutation-gate')),
    static fn(): bool => $installed,
);

/** A config file in the project, by its path from it. */
$file = static fn(string $path): ConfigFile => ConfigFile::at(Path::of($path), Path::of('/project'));

it('takes the format --format names', function (): void {
    expect(Formats::chosen('yaml'))->toBe(Format::Yaml)
        ->and(Formats::chosen('yml'))->toEqual(CannotJudge::because('--format is php, json, yaml or neon, not "yml".'));
});

it('finds the loader registered for a config file\'s format', function () use ($registry, $file): void {
    expect(Formats::loader($registry(installed: true), $file('mutation-gate.json')))->toEqual(new JsonConfig())
        ->and(Formats::loader($registry(installed: true), $file('mutation-gate.yml')))->toEqual(new YamlConfig());
});

it('says what to install to read YAML or NEON', function (string $name, string $message) use ($registry, $file): void {
    expect(Formats::loader($registry(installed: false), $file($name)))->toEqual(CannotJudge::because($message));
})->with([
    [
        'mutation-gate.yaml',
        'mutation-gate.yaml is YAML, which needs symfony/yaml to be read. '
        . 'Install it: composer require --dev symfony/yaml',
    ],
    [
        'mutation-gate.neon',
        'mutation-gate.neon is NEON, which needs nette/neon to be read. '
        . 'Install it: composer require --dev nette/neon',
    ],
]);

it('cannot judge a config file in no format a loader reads', function () use ($registry, $file): void {
    expect(Formats::loader($registry(installed: true), $file('gate.toml')))->toEqual(CannotJudge::because(
        'No config loader reads gate.toml. Name a mutation-gate.php, .json, .yaml, .yml or .neon file.',
    ));
});

it('cannot judge a PHP or JSON file where no loader is registered', function () use ($file): void {
    expect(Formats::loader(new Extensions(Origin::of('acme/gate')), $file('gate.json')))->toEqual(
        CannotJudge::because(
            'No config loader reads gate.json. Name a mutation-gate.php, .json, .yaml, .yml or .neon file.',
        ),
    );
});

it('cannot judge with a loader that will not be built without options', function () use ($file): void {
    $registry = new Extensions(Origin::of('acme/gate'))->withConfigLoader(
        Name::of('toml'),
        static fn(): Invalid => Invalid::because(Problem::at('strict', 'expected true or false, got nothing')),
    );

    expect(Formats::loader($registry, $file('gate.toml')))->toEqual(
        CannotJudge::because('The toml config loader needs options, and nothing can give it any.'),
    );
});

it('writes a config in each format', function (): void {
    $formats = new Formats(static fn(): bool => true);
    $origin = ProjectRoot::origin();
    $pest = Configs::valid('{"runner": "pest"}');

    expect($formats->render($pest, Format::Json, $origin))->toBe("{\n    \"runner\": \"pest\"\n}\n")
        ->and($formats->render($pest, Format::Yaml, $origin))->toBe("runner: pest\n")
        ->and($formats->render($pest, Format::Neon, $origin))->toBe("runner: pest\n")
        ->and($formats->render($pest, Format::Php, $origin))->toBe(<<<'PHP'
            <?php

            declare(strict_types=1);

            use NightWorksIO\MutationGate\Config\Gate;
            use NightWorksIO\MutationGate\Config\Runner;

            return Gate::configure()
                ->runner(Runner::pest());

            PHP);
});

it('says what to install to write YAML or NEON', function (Format $format, string $package): void {
    expect(new Formats(static fn(): bool => false)->render(Layer::none(), $format, ProjectRoot::origin()))->toEqual(
        CannotJudge::because(
            sprintf('--format=%s needs %s. Install it: composer require --dev %s', $format->value, $package, $package),
        ),
    );
})->with([[Format::Yaml, 'symfony/yaml'], [Format::Neon, 'nette/neon']]);

it('asks the installed check about the library each format needs', function (): void {
    $asked = new ArrayObject();
    $formats = new Formats(static function (string $class) use ($asked): bool {
        $asked->append($class);

        return false;
    });
    $formats->render(Layer::none(), Format::Yaml, ProjectRoot::origin());
    $formats->render(Layer::none(), Format::Neon, ProjectRoot::origin());

    expect($asked->getArrayCopy())->toBe([Yaml::class, Neon::class]);
});

it('finds a loader for every format it writes', function () use ($registry, $file): void {
    expect(array_map(
        static fn(Format $format): bool => Formats::loader(
            $registry(installed: true),
            $file($format->fileName()),
        ) instanceof ConfigLoader,
        Format::cases(),
    ))->toBe([true, true, true, true]);
});

it('writes a config file with its paths from its own directory, naming the JSON Schema only in JSON', function () use (
    $file,
): void {
    $formats = new Formats(static fn(): bool => true);
    $trees = Configs::valid('{"trees": [{"path": "src"}]}');

    expect($formats->file($trees, Format::Json, $file('/project/ci/mutation-gate.json')))->toBe(<<<'JSON'
        {
            "$schema": "../vendor/nightworksio/mutation-gate/resources/mutation-gate.schema.json",
            "trees": [
                {
                    "path": "../src"
                }
            ]
        }

        JSON)
        ->and($formats->file($trees, Format::Yaml, $file('/project/mutation-gate.yaml')))
        ->toBe("trees:\n  -\n    path: src\n");
});

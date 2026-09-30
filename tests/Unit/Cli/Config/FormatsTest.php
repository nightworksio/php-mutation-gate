<?php

declare(strict_types=1);

use Nette\Neon\Neon;
use NightWorksIO\MutationGate\Adapter\Json\JsonConfig;
use NightWorksIO\MutationGate\Adapter\Yaml\YamlConfig;
use NightWorksIO\MutationGate\Cli\Config\Formats;
use NightWorksIO\MutationGate\Cli\Config\Registered;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Core\Config\Problem;
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

it('names the format of a config file by its extension', function (string $file, string $format): void {
    expect(Formats::of(Path::of($file)))->toBe($format);
})->with([
    ['mutation-gate.php', 'php'],
    ['mutation-gate.json', 'json'],
    ['mutation-gate.yaml', 'yaml'],
    ['mutation-gate.yml', 'yaml'],
    ['ci/gate.neon', 'neon'],
    ['gate', ''],
]);

it('finds the loader registered for a config file\'s format', function () use ($registry): void {
    expect(Formats::loader($registry(installed: true), Path::of('mutation-gate.json')))->toEqual(new JsonConfig())
        ->and(Formats::loader($registry(installed: true), Path::of('mutation-gate.yml')))->toEqual(new YamlConfig());
});

it('says what to install to read YAML or NEON', function (string $file, string $message) use ($registry): void {
    expect(Formats::loader($registry(installed: false), Path::of($file)))->toEqual(CannotJudge::because($message));
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

it('cannot judge a config file in no format a loader reads', function () use ($registry): void {
    expect(Formats::loader($registry(installed: true), Path::of('gate.toml')))->toEqual(CannotJudge::because(
        'No config loader reads gate.toml. Name a mutation-gate.php, .json, .yaml, .yml or .neon file.',
    ));
});

it('cannot judge with a loader that will not be built without options', function (): void {
    $registry = new Extensions(Origin::of('acme/gate'))->withConfigLoader(
        Name::of('toml'),
        static fn(): Invalid => Invalid::because(Problem::at('strict', 'expected true or false, got nothing')),
    );

    expect(Formats::loader($registry, Path::of('gate.toml')))->toEqual(
        CannotJudge::because('The toml config loader needs options, and nothing can give it any.'),
    );
});

it('writes a config in each format', function (): void {
    $formats = new Formats(static fn(): bool => true);
    $document = Configs::document("{\n    \"runner\": \"pest\"\n}");

    expect($formats->render($document, 'json'))->toBe("{\n    \"runner\": \"pest\"\n}\n")
        ->and($formats->render($document, 'yaml'))->toBe("runner: pest\n")
        ->and($formats->render($document, 'neon'))->toBe("runner: pest\n")
        ->and($formats->render($document, 'php'))->toBe(<<<'PHP'
            <?php

            declare(strict_types=1);

            use NightWorksIO\MutationGate\Config\Gate;
            use NightWorksIO\MutationGate\Config\Runner;

            return Gate::configure()
                ->runner(Runner::pest());

            PHP);
});

it('says what to install to write YAML or NEON', function (string $format, string $package): void {
    expect(new Formats(static fn(): bool => false)->render(Configs::document('{}'), $format))->toEqual(
        CannotJudge::because(
            sprintf('--format=%s needs %s. Install it: composer require --dev %s', $format, $package, $package),
        ),
    );
})->with([['yaml', 'symfony/yaml'], ['neon', 'nette/neon']]);

it('asks the installed check about the library each format needs', function (): void {
    $asked = new ArrayObject();
    $formats = new Formats(static function (string $class) use ($asked): bool {
        $asked->append($class);

        return false;
    });
    $formats->render(Configs::document('{}'), 'yaml');
    $formats->render(Configs::document('{}'), 'neon');

    expect($asked->getArrayCopy())->toBe([Yaml::class, Neon::class]);
});

it('cannot write a config in a format it does not know', function (): void {
    expect(new Formats(static fn(): bool => true)->render(Configs::document('{}'), 'toml'))
        ->toEqual(CannotJudge::because('--format is php, json, yaml or neon, not "toml".'));
});

it('finds a loader for every format it writes', function () use ($registry): void {
    expect(array_map(
        static fn(string $format): bool => Formats::loader(
            $registry(installed: true),
            Path::of(sprintf('f.%s', $format)),
        ) instanceof ConfigLoader,
        ['php', 'json', 'yaml', 'neon'],
    ))->toBe([true, true, true, true]);
});

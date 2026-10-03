<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Cli\Config\Detected;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Composer\Installed;
use NightWorksIO\MutationGate\Core\Composer\Package;
use NightWorksIO\MutationGate\Core\Config\BuiltinPreset;
use NightWorksIO\MutationGate\Core\Config\BuiltinRunner;
use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** What zero-config finds in a project with this composer.json and these installed packages. */
/** @param list<string> $installed the packages installed, by name */
$detected = static function (string $manifest, array $installed): Detected {
    $project = Scratch::directory();

    if ($manifest !== '') {
        Scratch::write($project, 'composer.json', $manifest);
    }

    Scratch::write($project, 'vendor/composer/installed.json', (string) json_encode([
        'packages' => array_map(static fn(mixed $name): array => ['name' => $name], $installed),
    ]));

    return new Detected(Directory::at($project), Directory::at(sprintf('%s/vendor', $project)));
};

it('chooses the preset from what composer.json requires', function (string $manifest, BuiltinPreset $preset) use (
    $detected,
): void {
    expect($detected($manifest, [])->preset())->toBe($preset);
})->with([
    'Laravel' => ['{"require": {"php": "^8.5", "laravel/framework": "^12.0"}}', BuiltinPreset::Laravel],
    'Symfony' => ['{"require": {"symfony/framework-bundle": "^8.0"}}', BuiltinPreset::Symfony],
    'Laravel before Symfony' => [
        '{"require": {"symfony/framework-bundle": "^8.0", "laravel/framework": "^12.0"}}',
        BuiltinPreset::Laravel,
    ],
    'a library' => ['{"require": {"php": "^8.5"}}', BuiltinPreset::Library],
    'a framework only a dev dependency' => ['{"require-dev": {"laravel/framework": "^12.0"}}', BuiltinPreset::Library],
    'no requirements' => ['{"name": "acme/x"}', BuiltinPreset::Library],
    'no composer.json' => ['', BuiltinPreset::Library],
]);

it('chooses the runner that is installed', function (array $installed, BuiltinRunner $runner) use ($detected): void {
    expect($detected('', $installed)->runner())->toBe($runner);
})->with([
    'Pest' => [['pestphp/pest', 'pestphp/pest-plugin-mutate'], BuiltinRunner::Pest],
    'Infection' => [['infection/infection'], BuiltinRunner::Infection],
]);

it('asks for a choice when both runners are installed', function () use ($detected): void {
    expect($detected('', ['infection/infection', 'pestphp/pest-plugin-mutate'])->runner())
        ->toEqual(CannotJudge::because(
            'Both pestphp/pest-plugin-mutate and infection/infection are installed. '
        . 'Choose one: set runner in the config, or pass --runner.',
        ));
});

it('cannot judge without a runner installed', function () use ($detected): void {
    expect($detected('', ['pestphp/pest'])->runner())->toEqual(CannotJudge::because(
        'Neither pestphp/pest-plugin-mutate nor infection/infection is installed, so nothing can mutate. '
        . 'Install one of them.',
    ));
});

it('finds no runner in a vendor directory without the list of what is installed', function (): void {
    $project = Scratch::directory();

    expect(new Detected(Directory::at($project), Directory::at($project))->runner())
        ->toBeInstanceOf(CannotJudge::class);
});

it('reads only the packages installed.json names by a string', function (): void {
    $project = Scratch::directory();
    Scratch::write($project, 'vendor/composer/installed.json', (string) json_encode(['packages' => [
        ['name' => ['pestphp/pest-plugin-mutate']],
        ['name' => 'infection/infection'],
        ['version' => '1.0.0'],
    ]]));

    expect(new Detected(Directory::at($project), Directory::at(sprintf('%s/vendor', $project)))->runner())
        ->toBe(BuiltinRunner::Infection);
});

it('cannot judge a composer.json it cannot read', function (): void {
    $project = Scratch::directory();
    mkdir(sprintf('%s/composer.json', $project));

    expect(new Detected(Directory::at($project), Directory::at(sprintf('%s/vendor', $project)))->preset())
        ->toBeInstanceOf(CannotJudge::class);
});

it('cannot judge a manifest that is not JSON, or not the list of what is installed', function (): void {
    $project = Scratch::directory();
    Scratch::write($project, 'composer.json', '{"require": ');
    Scratch::write($project, 'vendor/composer/installed.json', 'packages');
    $detected = new Detected(Directory::at($project), Directory::at(sprintf('%s/vendor', $project)));

    expect($detected->preset())->toEqual(CannotJudge::because('composer.json is not a JSON object.'))
        ->and($detected->runner())->toEqual(CannotJudge::because(
            'composer/installed.json is not the list of installed packages Composer 2 writes, so what it installed cannot be read.',
        ));
});

it('cannot judge an installed.json it cannot read', function (): void {
    $project = Scratch::directory();
    mkdir(sprintf('%s/vendor/composer/installed.json', $project), recursive: true);

    expect(new Detected(Directory::at($project), Directory::at(sprintf('%s/vendor', $project)))->runner())
        ->toEqual(CannotJudge::because(sprintf('%s/vendor/composer/installed.json could not be read.', $project)));
});

it('reads what Composer installed, or says it lists nothing', function () use ($detected): void {
    $installed = $detected('', ['infection/infection'])->installed();
    $project = Scratch::directory();
    $bare = new Detected(Directory::at($project), Directory::at(sprintf('%s/vendor', $project)))->installed();

    expect($installed instanceof Installed && $installed->has(Package::Infection->value))->toBeTrue()
        ->and($installed instanceof Installed && $installed->has(Package::PestMutate->value))->toBeFalse()
        ->and($bare instanceof Installed && $bare->has(Package::Infection->value))->toBeFalse();
});

/** What zero-config finds in a project with these commands in vendor/bin and these files at its root. */
$analysing = static function (string ...$paths): Detected {
    $project = Scratch::directory();

    foreach ($paths as $path) {
        Scratch::write($project, $path, '');
    }

    return new Detected(Directory::at($project), Directory::at(sprintf('%s/vendor', $project)));
};

it('takes the analyser that is installed and configured', function (string $analyser, string $config) use (
    $analysing,
): void {
    expect($analysing(sprintf('vendor/bin/%s', $analyser), $config)->staticChecker())->toEqual(Name::of($analyser));
})->with([
    'Mago by its TOML' => ['mago', 'mago.toml'],
    'Mago by its YAML' => ['mago', 'mago.yaml'],
    'Mago by its JSON' => ['mago', 'mago.json'],
    'PHPStan by its neon' => ['phpstan', 'phpstan.neon'],
    'PHPStan by its distributed neon' => ['phpstan', 'phpstan.neon.dist'],
    'PHPStan by its other distributed neon' => ['phpstan', 'phpstan.dist.neon'],
    'Psalm by its XML' => ['psalm', 'psalm.xml'],
    'Psalm by its distributed XML' => ['psalm', 'psalm.xml.dist'],
]);

it('takes Mago before PHPStan, and PHPStan before Psalm', function () use ($analysing): void {
    $configs = ['mago.toml', 'phpstan.neon', 'psalm.xml'];

    expect($analysing('vendor/bin/psalm', 'vendor/bin/phpstan', 'vendor/bin/mago', ...$configs)->staticChecker())
        ->toEqual(Name::of('mago'))
        ->and($analysing('vendor/bin/psalm', 'vendor/bin/phpstan', ...$configs)->staticChecker())
        ->toEqual(Name::of('phpstan'));
});

it('takes no analyser that is installed but not configured, or configured but not installed', function (
    string ...$paths,
) use ($analysing): void {
    expect($analysing(...$paths)->staticChecker())->toEqual(Name::of('none'));
})->with([
    'nothing' => [],
    'installed only' => ['vendor/bin/mago', 'vendor/bin/phpstan', 'vendor/bin/psalm'],
    'configured only' => ['mago.toml', 'phpstan.neon', 'psalm.xml'],
    'each by the other\'s config' => ['vendor/bin/mago', 'vendor/bin/psalm', 'phpstan.neon'],
]);

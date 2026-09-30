<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Cli\Config\Detected;
use NightWorksIO\MutationGate\Core\CannotJudge;
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

it('chooses the preset from what composer.json requires', function (string $manifest, string $preset) use (
    $detected,
): void {
    expect($detected($manifest, [])->preset())->toBe($preset);
})->with([
    'Laravel' => ['{"require": {"php": "^8.5", "laravel/framework": "^12.0"}}', 'laravel'],
    'Symfony' => ['{"require": {"symfony/framework-bundle": "^8.0"}}', 'symfony'],
    'Laravel before Symfony' => [
        '{"require": {"symfony/framework-bundle": "^8.0", "laravel/framework": "^12.0"}}',
        'laravel',
    ],
    'a library' => ['{"require": {"php": "^8.5"}}', 'library'],
    'a framework only a dev dependency' => ['{"require-dev": {"laravel/framework": "^12.0"}}', 'library'],
    'no requirements' => ['{"name": "acme/x"}', 'library'],
    'no composer.json' => ['', 'library'],
]);

it('chooses the runner that is installed', function (array $installed, string $runner) use ($detected): void {
    expect($detected('', $installed)->runner())->toBe($runner);
})->with([
    'Pest' => [['pestphp/pest', 'pestphp/pest-plugin-mutate'], 'pest'],
    'Infection' => [['infection/infection'], 'infection'],
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
        ->toBe('infection');
});

it('cannot judge a composer.json it cannot read', function (): void {
    $project = Scratch::directory();
    mkdir(sprintf('%s/composer.json', $project));

    expect(new Detected(Directory::at($project), Directory::at(sprintf('%s/vendor', $project)))->preset())
        ->toBeInstanceOf(CannotJudge::class);
});

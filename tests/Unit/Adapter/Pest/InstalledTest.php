<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Installed;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Runner\Version;
use NightWorksIO\MutationGate\Core\Runner\Versions;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/**
 * An installed.json listing these packages.
 *
 * @param list<mixed> $packages
 */
$manifest = static function (array $packages): string {
    $root = Scratch::directory();
    Scratch::write($root, 'installed.json', (string) json_encode(['packages' => $packages]));

    return sprintf('%s/installed.json', $root);
};

/**
 * A package as Composer lists it.
 *
 * @return array<string, mixed>
 */
$package = static fn(string $name, string $version): array => [
    'name' => $name,
    'version' => $version,
    'source' => ['reference' => sprintf('%s-source', $version)],
    'dist' => ['reference' => sprintf('%s-dist', $version)],
];

it('names the version and source of every package Pest mutates with', function () use ($manifest, $package): void {
    $versions = Installed::versionsIn($manifest([
        $package('phpunit/php-code-coverage', '14.3.5'),
        $package('symfony/console', 'v8.0.0'),
        $package('pestphp/pest', 'v5.2.1'),
        $package('phpunit/phpunit', '13.3.4'),
        $package('pestphp/pest-plugin-mutate', 'v5.0.2'),
    ]));

    expect($versions)->toEqual(Versions::of(
        Version::of('pestphp/pest', 'v5.2.1', 'v5.2.1-source'),
        Version::of('pestphp/pest-plugin-mutate', 'v5.0.2', 'v5.0.2-source'),
        Version::of('phpunit/phpunit', '13.3.4', '13.3.4-source'),
        Version::of('phpunit/php-code-coverage', '14.3.5', '14.3.5-source'),
    ));
});

it('takes the dist reference without a source one, and none without either', function () use ($manifest): void {
    $versions = Installed::versionsIn($manifest([
        ['name' => 'pestphp/pest', 'version' => 'v5.2.1', 'dist' => ['reference' => 'd1']],
        [
            'name' => 'pestphp/pest-plugin-mutate',
            'version' => 'v5.0.2',
            'source' => ['reference' => ''],
            'dist' => ['reference' => 'd2'],
        ],
        ['name' => 'phpunit/phpunit', 'version' => '13.3.4', 'source' => 'not a source'],
        ['name' => 'phpunit/php-code-coverage', 'version' => 14],
    ]));

    expect($versions)->toEqual(Versions::of(
        Version::of('pestphp/pest', 'v5.2.1', 'd1'),
        Version::of('pestphp/pest-plugin-mutate', 'v5.0.2', 'd2'),
        Version::of('phpunit/phpunit', '13.3.4', ''),
        Version::of('phpunit/php-code-coverage', '', ''),
    ));
});

it('cannot judge when a package Pest mutates with is not installed', function () use ($manifest, $package): void {
    $file = $manifest([
        $package('pestphp/pest', 'v5.2.1'),
        'not a package',
        ['name' => ['not a name']],
        $package('phpunit/phpunit', '13.3.4'),
    ]);

    expect(Installed::versionsIn($file))->toEqual(CannotJudge::because(sprintf(
        '%s does not list pestphp/pest-plugin-mutate, phpunit/php-code-coverage, '
        . 'so the gate cannot say which Pest judges the mutants. Run composer install.',
        $file,
    )));
});

it('cannot judge with no manifest, which lists nothing', function (): void {
    $file = sprintf('%s/absent.json', Scratch::directory());

    expect(Installed::versionsIn($file))->toEqual(CannotJudge::because(sprintf(
        '%s does not list %s, so the gate cannot say which Pest judges the mutants. Run composer install.',
        $file,
        'pestphp/pest, pestphp/pest-plugin-mutate, phpunit/phpunit, phpunit/php-code-coverage',
    )));
});

it('cannot judge with a manifest that is not JSON, or that holds no list of packages', function (string $text): void {
    $root = Scratch::directory();
    Scratch::write($root, 'installed.json', $text);
    $file = sprintf('%s/installed.json', $root);

    expect(Installed::versionsIn($file))->toEqual(CannotJudge::because(sprintf(
        '%s is not the list of installed packages Composer 2 writes, so what it installed cannot be read.',
        $file,
    )));
})->with(['{', '{"packages": "none"}']);

it('cannot judge with a directory where the manifest should be', function (): void {
    $root = Scratch::directory();

    expect(Installed::versionsIn($root))->toBeInstanceOf(CannotJudge::class);
});

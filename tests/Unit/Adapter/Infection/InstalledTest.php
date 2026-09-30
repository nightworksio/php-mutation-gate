<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Infection\Installed;
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

it('names the version and source of every package Infection mutates with', function () use ($manifest, $package): void {
    $versions = Installed::versionsIn($manifest([
        $package('phpunit/php-code-coverage', '14.3.5'),
        $package('symfony/console', 'v8.0.0'),
        $package('infection/infection', '0.35.5'),
        $package('phpunit/phpunit', '13.3.4'),
    ]));

    expect($versions)->toEqual(Versions::of(
        Version::of('infection/infection', '0.35.5', '0.35.5-source'),
        Version::of('phpunit/phpunit', '13.3.4', '13.3.4-source'),
        Version::of('phpunit/php-code-coverage', '14.3.5', '14.3.5-source'),
    ));
});

it('takes the dist reference without a source one, and none without either', function () use ($manifest): void {
    $versions = Installed::versionsIn($manifest([
        [
            'name' => 'infection/infection',
            'version' => '0.35.5',
            'source' => ['reference' => ''],
            'dist' => ['reference' => 'd1'],
        ],
        ['name' => 'phpunit/phpunit', 'version' => '13.3.4', 'source' => 'not a source'],
        ['name' => 'phpunit/php-code-coverage', 'version' => 14],
    ]));

    expect($versions)->toEqual(Versions::of(
        Version::of('infection/infection', '0.35.5', 'd1'),
        Version::of('phpunit/phpunit', '13.3.4', ''),
        Version::of('phpunit/php-code-coverage', '', ''),
    ));
});

it('cannot judge when a package Infection mutates with is not installed', function () use ($manifest, $package): void {
    $file = $manifest([
        $package('infection/infection', '0.35.5'),
        'not a package',
        ['name' => ['not a name']],
    ]);

    expect(Installed::versionsIn($file))->toEqual(CannotJudge::because(sprintf(
        '%s does not list phpunit/phpunit, phpunit/php-code-coverage, '
        . 'so the gate cannot say which Infection judges the mutants. Run composer install.',
        $file,
    )));
});

it('cannot judge with no manifest, one that is not JSON, or one that lists no packages', function (): void {
    $root = Scratch::directory();
    Scratch::write($root, 'broken.json', '{');
    Scratch::write($root, 'empty.json', '{"packages": "none"}');
    $missing = 'infection/infection, phpunit/phpunit, phpunit/php-code-coverage';

    foreach (['absent.json', 'broken.json', 'empty.json'] as $name) {
        $file = sprintf('%s/%s', $root, $name);

        expect(Installed::versionsIn($file))->toEqual(CannotJudge::because(sprintf(
            '%s does not list %s, so the gate cannot say which Infection judges the mutants. Run composer install.',
            $file,
            $missing,
        )));
    }
});

it('cannot judge with a directory where the manifest should be', function (): void {
    $root = Scratch::directory();

    expect(Installed::versionsIn($root))->toBeInstanceOf(CannotJudge::class);
});

it('names the version of each other package asked for, after the ones Infection always drives', function () use ($manifest, $package): void {
    $file = $manifest([
        $package('phpstan/phpstan', '2.2.0'),
        $package('infection/infection', '0.35.5'),
        $package('phpunit/phpunit', '13.3.4'),
        $package('phpunit/php-code-coverage', '14.3.5'),
    ]);

    expect(Installed::versionsIn($file, 'phpstan/phpstan'))->toEqual(Versions::of(
        Version::of('infection/infection', '0.35.5', '0.35.5-source'),
        Version::of('phpunit/phpunit', '13.3.4', '13.3.4-source'),
        Version::of('phpunit/php-code-coverage', '14.3.5', '14.3.5-source'),
        Version::of('phpstan/phpstan', '2.2.0', '2.2.0-source'),
    ))->and(Installed::versionsIn($file, 'carthage-software/mago'))->toEqual(CannotJudge::because(sprintf(
        '%s does not list carthage-software/mago, so the gate cannot say which Infection judges the mutants. Run composer install.',
        $file,
    )));
});

<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Warm\BootSpec;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\PestBoot;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use PHPUnit\Event\Facade;
use PHPUnit\TextUI\XmlConfiguration\LoadedFromFileConfiguration;
use PHPUnit\TextUI\XmlConfiguration\Loader;
use PHPUnit\TextUI\XmlConfiguration\PHPUnit;

afterEach(function (): void {
    Scratch::sweep();
});

/** A project whose PHPUnit config, by this name, holds these attributes on its root and this inside it. */
function bootedProject(string $attributes = '', string $inside = '', string $name = 'phpunit.xml'): string
{
    $root = (string) realpath(Scratch::directory());
    Scratch::write($root, 'tests/Unit/MoneySpec.php', "<?php\n");
    Scratch::write($root, 'tests/Feature/PageSpec.php', "<?php\n");
    Scratch::write($root, 'boot.php', "<?php\n");
    Scratch::write($root, 'unit-boot.php', "<?php\n");
    Scratch::write($root, $name, sprintf(
        "<?xml version=\"1.0\"?>\n<phpunit %s>\n  <testsuites>\n"
        . "    <testsuite name=\"Unit\" bootstrap=\"unit-boot.php\"><directory suffix=\"Spec.php\">tests/Unit</directory></testsuite>\n"
        . "    <testsuite name=\"Feature\"><directory suffix=\"Spec.php\">tests/Feature</directory></testsuite>\n"
        . "  </testsuites>\n%s</phpunit>\n",
        $attributes,
        $inside,
    ));

    return $root;
}

/** The project's config, read as PHPUnit reads it. */
function bootedConfig(string $root, string $name = 'phpunit.xml'): LoadedFromFileConfiguration
{
    return new Loader(Facade::emitter())->load(sprintf('%s/%s', $root, $name));
}

/** @param list<string> $suites */
function booting(string $root, array $suites = []): PestBoot
{
    return PestBoot::of(BootSpec::of($root, sprintf('%s/vendor/autoload.php', $root), 'vendor/pestphp/pest/bin/pest', $suites));
}

/** What a worker of mutants' runs of the project boots. */
function bootingMutants(string $root): PestBoot
{
    return PestBoot::of(BootSpec::of($root, sprintf('%s/vendor/autoload.php', $root), 'vendor/pestphp/pest/bin/pest', [])->forMutants());
}

it('finds the PHPUnit config by the names PHPUnit looks for, in its order, and none where there is none', function (): void {
    $dist = bootedProject(name: 'phpunit.xml.dist');
    $both = bootedProject(name: 'phpunit.dist.xml');
    Scratch::write($both, 'phpunit.xml.dist', '<phpunit/>');
    $none = Scratch::directory();

    expect(booting($dist)->config())->toBe(sprintf('%s/phpunit.xml.dist', $dist))
        ->and(booting($both)->config())->toBe(sprintf('%s/phpunit.dist.xml', $both))
        ->and(booting($none)->config())->toEqual(NotGiven::value())
        ->and(booting($none)->unconfigured())
        ->toBe(sprintf("The tail's warm worker found no PHPUnit config in %s, so it booted nothing of the project.", $none));
});

it('includes the suites the runs name, else the config\'s default, else every one', function (): void {
    $plain = bootedProject();
    $defaulted = bootedProject('defaultTestSuite="Feature"');

    expect(booting($plain, ['Unit', '', 'Feature'])->suites(bootedConfig($plain)))->toBe(['Unit', 'Feature'])
        ->and(booting($defaulted)->suites(bootedConfig($defaulted)))->toBe(['Feature'])
        ->and(booting($defaulted, ['Unit'])->suites(bootedConfig($defaulted)))->toBe(['Unit'])
        ->and(booting($plain)->suites(bootedConfig($plain)))->toBe([]);
});

it('runs the config\'s bootstrap, and each included suite\'s, as PHPUnit does before it builds the suite', function (): void {
    $root = bootedProject('bootstrap="boot.php"');
    $reading = booting($root);
    $xml = bootedConfig($root);

    expect($reading->bootstraps($xml, []))->toBe([sprintf('%s/boot.php', $root), sprintf('%s/unit-boot.php', $root)])
        ->and($reading->bootstraps($xml, ['Unit']))->toBe([sprintf('%s/boot.php', $root), sprintf('%s/unit-boot.php', $root)])
        ->and($reading->bootstraps($xml, ['Feature']))->toBe([sprintf('%s/boot.php', $root)])
        ->and(booting(bootedProject())->bootstraps(bootedConfig(bootedProject()), ['Feature']))->toBe([]);
});

it('refuses a boot of a config that caches PHPUnit\'s test index, where PHPUnit has one, and none of one that does not', function (): void {
    $indexed = bootedProject('cacheDirectory=".cache" cacheTestIndex="true"');
    $plain = bootedProject();
    $refused = sprintf(
        'PHPUnit caches its test index for %s/phpunit.xml, which may leave test files unloaded, so nothing was booted.',
        $indexed,
    );

    $cached = in_array('cacheTestIndex', get_class_methods(PHPUnit::class), strict: true);

    expect(booting($indexed)->refusal(bootedConfig($indexed)))->toBe($cached ? $refused : '')
        ->and(booting($plain)->refusal(bootedConfig($plain)))->toBe('');
});

it('forks unbooted, saying nothing, where a worker of mutants\' runs finds no config, and refuses no config that caches the test index', function (): void {
    $none = Scratch::directory();
    $indexed = bootedProject('cacheDirectory=".cache" cacheTestIndex="true"');

    expect(bootingMutants($none)->unconfigured())->toBe('')
        ->and(bootingMutants($indexed)->refusal(bootedConfig($indexed)))->toBe('');
});

it('names the worker whose runs ran fresh where it could not read the tests, or its boot left a socket open, and says nothing of none left', function (): void {
    $root = bootedProject();

    expect([booting($root)->unread('/p/phpunit.xml', 'no tests'), bootingMutants($root)->unread('/p/phpunit.xml', 'no tests')])->toBe([
        "The tail's warm worker could not read the tests /p/phpunit.xml names, so its runs ran fresh: no tests",
        "A warm worker of mutants' runs could not read the tests /p/phpunit.xml names, so its runs ran fresh: no tests",
    ])
        ->and([booting($root)->leftOpen(1), bootingMutants($root)->leftOpen(2), booting($root)->leftOpen(0)])->toBe([
            "The tail's warm worker left 1 socket open once it booted, which every child would share, so its runs ran fresh.",
            "A warm worker of mutants' runs left 2 socket open once it booted, which every child would share, so its runs ran fresh.",
            '',
        ]);
});

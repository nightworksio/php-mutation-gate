<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Glob;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Reach\Layout;

/** The layout of a project whose runner is defined by these files. */
function layoutDefinedBy(string ...$files): Layout
{
    return Layout::standard(Paths::of(...array_map(Path::of(...), $files)));
}

it('decides how the gate runs with the files every project decides with, spelt from its package', function (string $file): void {
    expect(layoutDefinedBy()->decides(Path::of($file)))->toBeTrue();
})->with([
    'mutation-gate.php',
    'mutation-gate.json',
    'mutation-gate.yaml',
    'mutation-gate.yml',
    'mutation-gate.neon',
    'composer.json',
    'phpunit.xml',
    'phpunit.xml.dist',
    'phpunit.dist.xml',
]);

it('decides how the gate runs with the files that define the runner, and not with another runner\'s', function (): void {
    $layout = layoutDefinedBy('infection.json5', 'config/phpunit.xml');

    expect($layout->decides(Path::of('infection.json5')))->toBeTrue()
        ->and($layout->decides(Path::of('config/phpunit.xml')))->toBeTrue()
        ->and($layout->decides(Path::of('tests/Pest.php')))->toBeFalse()
        ->and(layoutDefinedBy('tests/Pest.php')->decides(Path::of('tests/Pest.php')))->toBeTrue()
        ->and(layoutDefinedBy('tests/Pest.php')->decides(Path::of('infection.json5')))->toBeFalse();
});

it('decides nothing with any other file', function (string $file): void {
    expect(layoutDefinedBy('tests/Pest.php')->decides(Path::of($file)))->toBeFalse();
})->with(['README.md', 'src/composer.json', 'composer.lock', 'tests/Unit/PestTest.php', 'src/phpunit.xml']);

it('decides with the files a preset or reach.everything adds', function (): void {
    $layout = layoutDefinedBy()->decidedAlsoBy(Glob::of('config/**'))->decidedAlsoBy(Glob::of('routes/*.php'));

    expect($layout->decides(Path::of('config/app.php')))->toBeTrue()
        ->and($layout->decides(Path::of('routes/web.php')))->toBeTrue()
        ->and($layout->decides(Path::of('phpunit.xml')))->toBeTrue()
        ->and(layoutDefinedBy()->decides(Path::of('config/app.php')))->toBeFalse();
});

it('runs the gate from the CI definitions it is given, and from no other', function (): void {
    $layout = layoutDefinedBy()->runBy(Glob::of('.github/workflows/gate.yml'));

    expect($layout->runsTheGate(Path::of('.github/workflows/gate.yml')))->toBeTrue()
        ->and($layout->runsTheGate(Path::of('.github/workflows/lint.yml')))->toBeFalse()
        ->and(layoutDefinedBy()->runsTheGate(Path::of('.github/workflows/gate.yml')))->toBeFalse();
});

it('finds files of test cases and test support under the tests', function (): void {
    $layout = layoutDefinedBy();

    expect($layout->isTest(Path::of('tests/Unit/MoneyTest.php')))->toBeTrue()
        ->and($layout->isTest(Path::of('tests/Fakes/ClockFake.php')))->toBeFalse()
        ->and($layout->isTest(Path::of('src/MoneyTest.php')))->toBeFalse()
        ->and($layout->isSupport(Path::of('tests/Fakes/ClockFake.php')))->toBeTrue()
        ->and($layout->isSupport(Path::of('tests/Unit/MoneyTest.php')))->toBeFalse()
        ->and($layout->isSupport(Path::of('tests/fixtures/money.json')))->toBeFalse()
        ->and($layout->isSupport(Path::of('src/Clock.php')))->toBeFalse();
});

it('finds tests in the directories it is given in place of tests', function (): void {
    $layout = layoutDefinedBy()->testedIn(Paths::of(Path::of('spec'), Path::of('modules/billing/tests')));

    expect($layout->isTest(Path::of('spec/MoneyTest.php')))->toBeTrue()
        ->and($layout->isTest(Path::of('modules/billing/tests/InvoiceTest.php')))->toBeTrue()
        ->and($layout->isSupport(Path::of('spec/Fakes/ClockFake.php')))->toBeTrue()
        ->and($layout->isTest(Path::of('tests/MoneyTest.php')))->toBeFalse();
});

it('finds the modules a file is inside', function (): void {
    $layout = layoutDefinedBy()
        ->withModule(Path::of('app-modules/billing'))
        ->withModule(Path::of('app-modules/shop'));

    expect($layout->modulesHolding(Path::of('app-modules/billing/tests/InvoiceTest.php')))->toEqual(Paths::of(Path::of('app-modules/billing')))
        ->and($layout->modulesHolding(Path::of('tests/MoneyTest.php')))->toEqual(Paths::none())
        ->and(layoutDefinedBy()->modulesHolding(Path::of('app-modules/billing/tests/InvoiceTest.php')))->toEqual(Paths::none());
});

it('keeps what it was given when given more', function (): void {
    $layout = layoutDefinedBy()
        ->withModule(Path::of('app-modules/billing'))
        ->testedIn(Paths::of(Path::of('spec')))
        ->runBy(Glob::of('.github/workflows/gate.yml'))
        ->decidedAlsoBy(Glob::of('config/**'))
        ->withModule(Path::of('app-modules/shop'))
        ->testedIn(Paths::of(Path::of('specs')))
        ->runBy(Glob::of('.gitlab-ci.yml'));

    expect($layout->modulesHolding(Path::of('app-modules/billing/specs/InvoiceTest.php')))->toEqual(Paths::of(Path::of('app-modules/billing')))
        ->and($layout->isTest(Path::of('specs/MoneyTest.php')))->toBeTrue()
        ->and($layout->runsTheGate(Path::of('.github/workflows/gate.yml')))->toBeTrue()
        ->and($layout->runsTheGate(Path::of('.gitlab-ci.yml')))->toBeTrue()
        ->and($layout->decides(Path::of('config/app.php')))->toBeTrue()
        ->and($layout->decides(Path::of('composer.json')))->toBeTrue();
});

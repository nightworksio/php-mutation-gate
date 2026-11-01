<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Warm\GateClasses;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Tests\Support\LaravelTesting;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('loads a class of the gate\'s namespace from the file its name names under the source, and nothing else', function (): void {
    $source = Scratch::directory();
    mkdir(sprintf('%s/Probed', $source));
    file_put_contents(
        sprintf('%s/Probed/Loaded.php', $source),
        "<?php\n\nnamespace NightWorksIO\\MutationGate\\Probed;\n\nfinal class Loaded\n{\n}\n",
    );
    file_put_contents(sprintf('%s/Probed/Elsewhere.php', $source), "<?php\n\nthrow new LogicException('loaded');\n");
    $loaded = false;
    $elsewhere = false;
    $missing = false;
    $registered = LaravelTesting::spiesOf(static function () use ($source, &$loaded, &$elsewhere, &$missing): void {
        GateClasses::register($source);
        $loaded = class_exists('NightWorksIO\MutationGate\Probed\Loaded');
        $elsewhere = class_exists('Elsewhere\NotTheGateAtAll\Probed\Elsewhere');
        $missing = class_exists('NightWorksIO\MutationGate\Probed\Missing');
    });

    expect($loaded)->toBeTrue()
        ->and($elsewhere)->toBeFalse()
        ->and($missing)->toBeFalse()
        ->and($registered)->toBe(1);
});

it('loads the polyfill of PHP 8.5\'s array functions from the gate\'s own vendor directory, or the project\'s it is installed in', function (string $root, string $polyfill): void {
    $project = Scratch::directory();
    mkdir(sprintf('%s/%s', $project, $root), recursive: true);
    mkdir(dirname(sprintf('%s/%s', $project, $polyfill)), recursive: true);
    file_put_contents(sprintf('%s/%s', $project, $polyfill), "<?php\n");
    file_put_contents(sprintf('%s/Php85.php', dirname(sprintf('%s/%s', $project, $polyfill))), "<?php\n");

    expect(GateClasses::polyfilledFrom(sprintf('%s/%s', $project, $root)))
        ->toBe(sprintf('%s/%s/%s', $project, $root, $root === 'gate' ? 'vendor/symfony/polyfill-php85/bootstrap.php' : '../../symfony/polyfill-php85/bootstrap.php'))
        ->and(get_included_files())->toContain(
            (string) realpath(sprintf('%s/%s', $project, $polyfill)),
            (string) realpath(sprintf('%s/Php85.php', dirname(sprintf('%s/%s', $project, $polyfill)))),
        );
})->with([
    'its own' => ['gate', 'gate/vendor/symfony/polyfill-php85/bootstrap.php'],
    'the project\'s' => ['vendor/nightworksio/mutation-gate', 'vendor/symfony/polyfill-php85/bootstrap.php'],
]);

it('loads no polyfill where no vendor directory beside the gate holds one', function (): void {
    expect(GateClasses::polyfilledFrom(Scratch::directory()))->toEqual(NotGiven::value());
});

it('loads no polyfill on registering where PHP\'s array functions are already there', function (): void {
    $gate = sprintf('%s/gate', Scratch::directory());
    $polyfill = sprintf('%s/vendor/symfony/polyfill-php85/bootstrap.php', $gate);
    mkdir(dirname($polyfill), recursive: true);
    file_put_contents($polyfill, "<?php\n");
    file_put_contents(sprintf('%s/Php85.php', dirname($polyfill)), "<?php\n");

    LaravelTesting::spiesOf(static function () use ($gate): void {
        GateClasses::register(sprintf('%s/src', $gate));
    });

    expect(function_exists('array_last'))->toBeTrue()
        ->and(get_included_files())->not->toContain((string) realpath($polyfill));
});

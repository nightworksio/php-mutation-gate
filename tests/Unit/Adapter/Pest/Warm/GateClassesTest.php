<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Warm\GateClasses;
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

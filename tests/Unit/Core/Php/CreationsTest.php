<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Php\Creations;
use NightWorksIO\MutationGate\Core\Php\Hierarchy;
use NightWorksIO\MutationGate\Core\Php\Symbol;
use NightWorksIO\MutationGate\Tests\Support\Php;

it('reads a property\'s default wherever the owner or a class that reaches it is made', function (): void {
    $classes = Php::source('<?php namespace App; class Money { public $cents = 1; } class Cash extends Money { }');
    $source = Php::source(<<<'PHP'
        <?php
        namespace App;
        new Money();
        new Cash;
        new \App\Other();
        class Coin extends Money {
            function a() { return new self(); }
            function b() { return new static(); }
            function c() { return new parent(); }
        }
        new class extends Money { };
        new $class();
        PHP, 'src/Maker.php');
    $read = Creations::of(Symbol::property('App\Money', 'cents', static: false), $source, Hierarchy::of($classes, $source));

    expect(Php::sites($read))->toBe([
        'src/Maker.php:3', 'src/Maker.php:4', 'src/Maker.php:7', 'src/Maker.php:8', 'src/Maker.php:9',
    ])->and($read->isAmbiguous())->toBeTrue()
        ->and(Creations::of(
            Symbol::property('App\Money', 'cents', static: false),
            Php::source('<?php new (Factory::name())();'),
            Hierarchy::of($classes),
        )->isAmbiguous())->toBeTrue();
});

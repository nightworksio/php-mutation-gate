<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Php\Hierarchy;
use NightWorksIO\MutationGate\Core\Php\Names;
use NightWorksIO\MutationGate\Core\Php\Shadowing;
use NightWorksIO\MutationGate\Tests\Support\Php;

$hierarchy = static fn(): Hierarchy => Hierarchy::of(
    Php::source(<<<'PHP'
        <?php
        namespace App;
        interface Rated { const LEVEL = 1; }
        class Base implements Rated { const RATE = 2; }
        class Money extends Base { const RATE = 3; }
        class Cash extends Money { }
        class Loop extends Loop { }
        PHP),
    Php::source('<?php namespace App; $a = new class extends Base { };', 'src/B.php'),
);

it('reaches an owner from itself, and through what a class extends and implements', function () use ($hierarchy): void {
    expect($hierarchy()->reaches('app\base', 'app\base', Shadowing::byConstant('RATE')))->toBeTrue()
        ->and($hierarchy()->reaches('app\cash', 'app\rated', Shadowing::byConstant('LEVEL')))->toBeTrue()
        ->and($hierarchy()->reaches('app\base', 'app\money', Shadowing::none()))->toBeFalse()
        ->and($hierarchy()->reaches('other\base', 'app\base', Shadowing::none()))->toBeFalse()
        ->and($hierarchy()->reaches('app\loop', 'app\base', Shadowing::none()))->toBeFalse();
});

it('stops at a class that declares the constant anew', function () use ($hierarchy): void {
    expect($hierarchy()->reaches('app\cash', 'app\base', Shadowing::byConstant('RATE')))->toBeFalse()
        ->and($hierarchy()->reaches('app\money', 'app\base', Shadowing::byConstant('RATE')))->toBeFalse()
        ->and($hierarchy()->reaches('app\cash', 'app\base', Shadowing::none()))->toBeTrue()
        ->and($hierarchy()->reaches('app\cash', 'app\money', Shadowing::byConstant('RATE')))->toBeTrue();
});

it('reaches an owner from any of several names', function () use ($hierarchy): void {
    expect($hierarchy()->anyReaches(Names::of('Other\Cash', 'App\Cash'), 'app\base', Shadowing::none()))->toBeTrue()
        ->and($hierarchy()->anyReaches(Names::of('Other\Cash'), 'app\base', Shadowing::none()))->toBeFalse()
        ->and($hierarchy()->anyReaches(Names::of(), 'app\base', Shadowing::none()))->toBeFalse();
});

it('says whether a class that reaches the owner declares its constant anew', function () use ($hierarchy): void {
    expect($hierarchy()->overrides('app\base', 'RATE'))->toBeTrue()
        ->and($hierarchy()->overrides('app\money', 'RATE'))->toBeFalse()
        ->and($hierarchy()->overrides('app\rated', 'LEVEL'))->toBeFalse();
});

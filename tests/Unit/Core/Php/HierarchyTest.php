<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Php\Hierarchy;
use NightWorksIO\MutationGate\Core\Php\Names;
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
    expect($hierarchy()->reaches('app\base', 'app\base', 'RATE'))->toBeTrue()
        ->and($hierarchy()->reaches('app\cash', 'app\rated', 'LEVEL'))->toBeTrue()
        ->and($hierarchy()->reaches('app\base', 'app\money', ''))->toBeFalse()
        ->and($hierarchy()->reaches('other\base', 'app\base', ''))->toBeFalse()
        ->and($hierarchy()->reaches('app\loop', 'app\base', ''))->toBeFalse();
});

it('stops at a class that declares the constant anew', function () use ($hierarchy): void {
    expect($hierarchy()->reaches('app\cash', 'app\base', 'RATE'))->toBeFalse()
        ->and($hierarchy()->reaches('app\money', 'app\base', 'RATE'))->toBeFalse()
        ->and($hierarchy()->reaches('app\cash', 'app\base', ''))->toBeTrue()
        ->and($hierarchy()->reaches('app\cash', 'app\money', 'RATE'))->toBeTrue();
});

it('reaches an owner from any of several names', function () use ($hierarchy): void {
    expect($hierarchy()->anyReaches(Names::of('Other\Cash', 'App\Cash'), 'app\base', ''))->toBeTrue()
        ->and($hierarchy()->anyReaches(Names::of('Other\Cash'), 'app\base', ''))->toBeFalse()
        ->and($hierarchy()->anyReaches(Names::of(), 'app\base', ''))->toBeFalse();
});

it('says whether a class that reaches the owner declares its constant anew', function () use ($hierarchy): void {
    expect($hierarchy()->overrides('app\base', 'RATE'))->toBeTrue()
        ->and($hierarchy()->overrides('app\money', 'RATE'))->toBeFalse()
        ->and($hierarchy()->overrides('app\rated', 'LEVEL'))->toBeFalse();
});

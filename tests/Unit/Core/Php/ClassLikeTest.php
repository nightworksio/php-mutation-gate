<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Php\ClassLike;
use NightWorksIO\MutationGate\Core\Php\Names;
use NightWorksIO\MutationGate\Tests\Support\Php;

it('reads a class\'s name, what it extends and implements, and the constants it declares', function (): void {
    $source = Php::source(<<<'PHP'
        <?php
        namespace App;
        use Other\Base as B;
        final class Money extends B implements \Countable, Rated
        {
            public const RATE = 21, LOW = 9;
            const array LIST = [1 => 2];
            case Paid = 'p';
            public int $cents = 100;
            public function f($a = 1): void { $b = 2; }
        }
        PHP);
    $class = $source->shape()->classes()[0];

    expect($class->name())->toBe('App\Money')
        ->and($class->key())->toBe('app\money')
        ->and($class->parents())->toEqual(Names::of('Other\Base', 'Countable', 'App\Rated', 'Rated'))
        ->and($class->declares('RATE'))->toBeTrue()
        ->and($class->declares('LOW'))->toBeTrue()
        ->and($class->declares('LIST'))->toBeTrue()
        ->and($class->declares('Paid'))->toBeFalse()
        ->and($class->declares('cents'))->toBeFalse()
        ->and($class->declares('rate'))->toBeFalse();
});

it('names nothing for an anonymous class, or where no class stands', function (): void {
    $source = Php::source('<?php $a = new class extends Base { const X = 1; };');
    $anonymous = $source->shape()->classes()[0];

    expect($anonymous->name())->toBe('')
        ->and($anonymous->parents())->toEqual(Names::of('Base'))
        ->and($anonymous->declares('X'))->toBeTrue()
        ->and(ClassLike::none()->key())->toBe('')
        ->and(ClassLike::none()->parents())->toEqual(Names::of());
});

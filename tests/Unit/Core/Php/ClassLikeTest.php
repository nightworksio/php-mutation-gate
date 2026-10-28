<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Php\ClassLike;
use NightWorksIO\MutationGate\Core\Php\Nameless;
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
        ->and($class->declares('rate'))->toBeFalse()
        ->and($class->isTrait())->toBeFalse();
});

it('reads the traits a class-like uses in its body, and no name a method or a use\'s adaptations hold', function (): void {
    $source = Php::source(<<<'PHP'
        <?php
        namespace App;
        use Other\Counting as C;
        final class Money extends Base
        {
            public Amount $cents;
            use \Shared\Logs;
            public Coin $coin;
            use C, Priced { Priced::rate insteadof C; C::rate as protected base; }
            public Wallet $wallet;
            public function f(Coin $coin): Rated { return function () use ($coin) { }; }
        }
        trait Priced { use Rounds, namespace\Sub\Ticks; }
        PHP);
    [$class, $trait] = $source->shape()->classes();

    expect($class->uses())->toEqual(Names::of('Shared\Logs', 'Other\Counting', 'App\Priced'))
        ->and($class->parents())->toEqual(Names::of('App\Base', 'Base'))
        ->and($trait->uses())->toEqual(Names::of('App\Rounds', 'App\Sub\Ticks'))
        ->and(ClassLike::none()->uses())->toEqual(Names::of());
});

it('tells a trait from the other class-likes', function (string $keyword, bool $trait): void {
    $source = Php::source(sprintf('<?php namespace App; %s Counts { }', $keyword));

    expect($source->shape()->classes()[0]->isTrait())->toBe($trait);
})->with([
    'a trait' => ['trait', true],
    'a class' => ['class', false],
    'an interface' => ['interface', false],
    'an enum' => ['enum', false],
]);

it('declares no constant for a case whose value opens the body, before any const', function (): void {
    $enum = Php::source("<?php enum Status: string { case Paid = 'p'; const LATE = 'l'; }")->shape()->classes()[0];

    expect($enum->declares('Paid'))->toBeFalse()
        ->and($enum->declares('LATE'))->toBeTrue();
});

it('names nothing for an anonymous class, or where no class stands', function (): void {
    $source = Php::source('<?php $a = new class extends Base { const X = 1; };');
    $anonymous = $source->shape()->classes()[0];

    expect($anonymous->name())->toEqual(Nameless::code())
        ->and($anonymous->names())->toEqual(Names::of())
        ->and($anonymous->parents())->toEqual(Names::of('Base'))
        ->and($anonymous->declares('X'))->toBeTrue()
        ->and(ClassLike::none()->key())->toEqual(Nameless::code())
        ->and(ClassLike::none()->isTrait())->toBeFalse()
        ->and(ClassLike::none()->parents())->toEqual(Names::of());
});

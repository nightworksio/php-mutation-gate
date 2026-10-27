<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Php\Codebase;
use NightWorksIO\MutationGate\Core\Php\Source;
use NightWorksIO\MutationGate\Core\Php\Symbol;
use NightWorksIO\MutationGate\Core\Php\SymbolKind;
use NightWorksIO\MutationGate\Core\Php\Unnamed;
use NightWorksIO\MutationGate\Tests\Support\Php;

$codebase = static fn(): Codebase => Codebase::of(
    Php::source(<<<'PHP'
        <?php
        namespace App;
        class Money {
            const RATE = 21;
            const DOUBLE = self::RATE * 2;
            const TRIPLE = self::DOUBLE + self::RATE;
            const QUAD = self::TRIPLE;
            const DEEP = self::QUAD;
            const LOOP = self::LOOPED;
            const LOOPED = self::LOOP;
            public $cents = self::RATE;
            #[Attr(self::RATE)]
            public function rate(): int { return self::RATE; }
            public function f($a = self::RATE) { }
        }
        PHP, 'src/Money.php'),
    Php::source(<<<'PHP'
        <?php
        use App\Money;
        class MoneyTest { const SEEN = Money::DOUBLE; }
        it('reads', fn () => expect(Money::DEEP)->toBe(1));
        PHP, 'tests/MoneyTest.php', test: true),
);

it('holds the files it was read from', function () use ($codebase): void {
    expect($codebase()->has(Path::of('src/Money.php')))->toBeTrue()
        ->and($codebase()->has(Path::of('src/Other.php')))->toBeFalse();
});

it('answers the scanned source of a file it holds, and none of one it does not', function () use ($codebase): void {
    $read = $codebase();
    $money = $read->source(Path::of('src/Money.php'));

    expect($money instanceof Source ? $money->path() : NotGiven::value())->toEqual(Path::of('src/Money.php'))
        ->and($read->source(Path::of('src/Money.php')))->toBe($money)
        ->and($read->source(Path::of('src/Other.php')))->toEqual(NotGiven::value());
});

it('follows a read inside another declaration to where that is read, three declarations deep', function () use (
    $codebase,
): void {
    $read = $codebase()->references(Symbol::constant('App\Money', 'TRIPLE'));

    expect(Php::sites($read))->toBe(['tests/MoneyTest.php:4 (test)'])
        ->and($read->isAmbiguous())->toBeFalse();
});

it('cannot follow a chain past three declarations, or a value no reference names', function () use ($codebase): void {
    $double = $codebase()->references(Symbol::constant('App\Money', 'DOUBLE'));
    $rate = $codebase()->references(Symbol::constant('App\Money', 'RATE'));

    expect(Php::sites($double))->toBe(['tests/MoneyTest.php:3 (test)'])
        ->and($double->isAmbiguous())->toBeTrue()
        ->and(Php::sites($rate))->toBe(['tests/MoneyTest.php:3 (test)', 'src/Money.php:13', 'src/Money.php:14'])
        ->and($rate->isAmbiguous())->toBeTrue();
});

it('follows a loop of declarations once round', function () use ($codebase): void {
    $loop = $codebase()->references(Symbol::constant('App\Money', 'LOOP'));

    expect(Php::sites($loop))->toBe([])
        ->and($loop->isAmbiguous())->toBeFalse();
});

it('follows a loop of three declarations once round, and one a chain runs into', function (): void {
    $loops = Codebase::of(Php::source(<<<'PHP'
        <?php
        namespace App;
        class Loops {
            const A = self::C;
            const B = self::A;
            const C = self::B;
            const D = 1;
            const E = self::D + self::F;
            const F = self::E;
        }
        PHP, 'src/Loops.php'));
    $round = $loops->references(Symbol::constant('App\Loops', 'A'));
    $into = $loops->references(Symbol::constant('App\Loops', 'D'));

    expect([Php::sites($round), $round->isAmbiguous(), Php::sites($into), $into->isAmbiguous()])->toBe([[], false, [], false]);
});

it('follows a read in a method\'s parameter default to the lines of the body that runs it, and not one in an arrow function\'s', function (): void {
    $defaults = Codebase::of(Php::source(<<<'PHP'
        <?php
        namespace App;
        class Feed {
            const LIMIT = 5;
            public function follow(
                int $limit = self::LIMIT,
            ): int {
                return $limit;
            }
            public function later(): \Closure
            {
                return static fn(int $limit = self::LIMIT): int
                    => $limit;
            }
        }
        PHP, 'src/Feed.php'));
    $read = $defaults->references(Symbol::constant('App\Feed', 'LIMIT'));

    expect(Php::sites($read))->toBe(['src/Feed.php:7-9'])
        ->and($read->isAmbiguous())->toBeTrue();
});

it('follows a read in a parameter default of a function that has a body, and not of one that has none', function (): void {
    $defaults = Codebase::of(Php::source(<<<'PHP'
        <?php
        namespace App;
        abstract class Feed {
            const LIMIT = 5;
            abstract public function follow(int $limit = self::LIMIT): int;
            public function run($a = [self::LIMIT]) {
                return $a;
            }
        }
        PHP, 'src/Feed.php'));
    $read = $defaults->references(Symbol::constant('App\Feed', 'LIMIT'));

    expect(Php::sites($read))->toBe(['src/Feed.php:6-8'])
        ->and($read->isAmbiguous())->toBeTrue();
});

it('cannot follow a value no reference names at all', function () use ($codebase): void {
    $read = $codebase()->references(Unnamed::of(SymbolKind::AttributeArgument));

    expect(Php::sites($read))->toBe([])
        ->and($read->isAmbiguous())->toBeTrue();
});

it('reads a constant and an enum case named with a semi-reserved word where they are declared and read', function (): void {
    $source = Php::source(<<<'PHP'
        <?php
        namespace App;
        enum Mode: string { case Default = 'd'; }
        final class Box {
            const array GLOBAL = [1];
            public function all(): array { return [self::GLOBAL, Mode::Default]; }
        }
        PHP, 'src/Box.php');
    $codebase = Codebase::of($source);

    expect($source->symbolAt(Php::indexOf($source, '1')))->toEqual(Symbol::constant('App\Box', 'GLOBAL'))
        ->and(Php::sites($codebase->references(Symbol::constant('App\Box', 'GLOBAL'))))->toBe(['src/Box.php:6'])
        ->and($source->symbolAt(Php::indexOf($source, "'d'")))->toEqual(Symbol::enumCase('App\Mode', 'Default'))
        ->and(Php::sites($codebase->references(Symbol::enumCase('App\Mode', 'Default'))))->toBe(['src/Box.php:6']);
});

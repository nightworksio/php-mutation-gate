<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Php\Codebase;
use NightWorksIO\MutationGate\Core\Php\Symbol;
use NightWorksIO\MutationGate\Core\Php\SymbolKind;
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
        ->and(Php::sites($rate))->toBe(['tests/MoneyTest.php:3 (test)', 'src/Money.php:13'])
        ->and($rate->isAmbiguous())->toBeTrue();
});

it('follows a loop of declarations once round', function () use ($codebase): void {
    $loop = $codebase()->references(Symbol::constant('App\Money', 'LOOP'));

    expect(Php::sites($loop))->toBe([])
        ->and($loop->isAmbiguous())->toBeFalse();
});

it('cannot follow a value no reference names at all', function () use ($codebase): void {
    $read = $codebase()->references(Symbol::unnamed(SymbolKind::AttributeArgument));

    expect(Php::sites($read))->toBe([])
        ->and($read->isAmbiguous())->toBeTrue();
});

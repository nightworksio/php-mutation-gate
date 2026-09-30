<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Php\Hierarchy;
use NightWorksIO\MutationGate\Core\Php\Reads;
use NightWorksIO\MutationGate\Core\Php\Symbol;
use NightWorksIO\MutationGate\Core\Php\SymbolKind;
use NightWorksIO\MutationGate\Tests\Support\Php;

it('reads each kind of value the way that kind is read, and no reference names the rest', function (): void {
    $source = Php::source(<<<'PHP'
        <?php
        namespace App;
        class Money { const RATE = 1; public static $count = 2; public $cents = 3; }
        enum Status { case Paid; }
        function tax($rate = 4) { }
        Money::RATE;
        Money::$count;
        new Money();
        Status::Paid;
        tax();
        PHP);
    $hierarchy = Hierarchy::of($source);
    $read = static fn(Symbol $symbol): array => Php::sites(Reads::of($symbol, $source, $hierarchy));

    expect($read(Symbol::constant('App\Money', 'RATE')))->toBe(['src/A.php:6'])
        ->and($read(Symbol::property('App\Money', 'count', static: true)))->toBe(['src/A.php:7'])
        ->and($read(Symbol::property('App\Money', 'cents', static: false)))->toBe(['src/A.php:8'])
        ->and($read(Symbol::enumCase('App\Status', 'Paid')))->toBe(['src/A.php:9'])
        ->and($read(Symbol::parameter('App\tax', 'rate')))->toBe(['src/A.php:10'])
        ->and(Reads::of(Symbol::unnamed(SymbolKind::MethodParameter), $source, $hierarchy)->isAmbiguous())->toBeTrue()
        ->and(Reads::of(Symbol::unnamed(SymbolKind::ClosureParameter), $source, $hierarchy)->isAmbiguous())->toBeTrue()
        ->and(Reads::of(Symbol::unnamed(SymbolKind::AttributeArgument), $source, $hierarchy)->isAmbiguous())->toBeTrue();
});

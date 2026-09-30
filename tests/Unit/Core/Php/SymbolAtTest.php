<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Php\Executable;
use NightWorksIO\MutationGate\Core\Php\Symbol;
use NightWorksIO\MutationGate\Core\Php\SymbolAt;
use NightWorksIO\MutationGate\Core\Php\SymbolKind;
use NightWorksIO\MutationGate\Core\Php\Unnamed;
use NightWorksIO\MutationGate\Tests\Support\Php;

/** What the nth token of this text in the code stands in. */
function symbolAtToken(string $code, string $text, int $nth = 0): Symbol|Unnamed|Executable
{
    $source = Php::source($code);

    return SymbolAt::in($source->tokens(), $source->shape())->token(Php::indexOf($source, $text, $nth));
}

const SYMBOL_AT_CLASS = <<<'PHP'
    <?php
    namespace App;
    final class Money
    {
        public const RATE = 21, LOW = 9;
        private const array LIST = [
            'a' => 1,
        ];
        public static int $count = 3;
        private ?int $cents = 100;
        public function __construct(private int $a = 7) {}
        public function add(int $b = 2): int
        {
            $f = function ($z = 4) { return 5; };
            return $this->a + $b + self::RATE + 6;
        }
    }
    PHP;

it('reads a class constant\'s value, each of several on a line, and inside an array', function (): void {
    expect(symbolAtToken(SYMBOL_AT_CLASS, '21'))->toEqual(Symbol::constant('App\Money', 'RATE'))
        ->and(symbolAtToken(SYMBOL_AT_CLASS, '9'))->toEqual(Symbol::constant('App\Money', 'LOW'))
        ->and(symbolAtToken(SYMBOL_AT_CLASS, "'a'"))->toEqual(Symbol::constant('App\Money', 'LIST'))
        ->and(symbolAtToken(SYMBOL_AT_CLASS, '1'))->toEqual(Symbol::constant('App\Money', 'LIST'));
});

it('reads a property\'s default, static or not', function (): void {
    expect(symbolAtToken(SYMBOL_AT_CLASS, '3'))->toEqual(Symbol::property('App\Money', 'count', static: true))
        ->and(symbolAtToken(SYMBOL_AT_CLASS, '100'))->toEqual(Symbol::property('App\Money', 'cents', static: false));
});

it('reads a method\'s and a closure\'s parameter default, and runs what a body holds', function (): void {
    expect(symbolAtToken(SYMBOL_AT_CLASS, '7'))->toEqual(Unnamed::of(SymbolKind::MethodParameter))
        ->and(symbolAtToken(SYMBOL_AT_CLASS, '2'))->toEqual(Unnamed::of(SymbolKind::MethodParameter))
        ->and(symbolAtToken(SYMBOL_AT_CLASS, '4'))->toEqual(Unnamed::of(SymbolKind::ClosureParameter))
        ->and(symbolAtToken(SYMBOL_AT_CLASS, '5'))->toEqual(Executable::line())
        ->and(symbolAtToken(SYMBOL_AT_CLASS, '6'))->toEqual(Executable::line())
        ->and(symbolAtToken(SYMBOL_AT_CLASS, 'int', 1))->toEqual(Executable::line());
});

it('reads a plain function\'s parameter default by the function, and not a parameter\'s type', function (): void {
    $code = '<?php namespace App; function tax(int $rate = 21, $list = [1, 2]) { return 3; }';

    expect(symbolAtToken($code, '21'))->toEqual(Symbol::parameter('App\tax', 'rate'))
        ->and(symbolAtToken($code, '2'))->toEqual(Symbol::parameter('App\tax', 'list'))
        ->and(symbolAtToken($code, 'int'))->toEqual(Executable::line())
        ->and(symbolAtToken($code, '3'))->toEqual(Executable::line());
});

it('reads an enum case\'s value, an interface constant and an attribute\'s argument', function (): void {
    $code = <<<'PHP'
        <?php
        namespace App;
        #[Attr(1)]
        enum Status: string { case Paid = 'paid'; case Due; }
        interface Rated { const LEVEL = 3; }
        function f(#[Marked(4)] $a) { }
        PHP;

    expect(symbolAtToken($code, "'paid'"))->toEqual(Symbol::enumCase('App\Status', 'Paid'))
        ->and(symbolAtToken($code, 'Due'))->toEqual(Executable::line())
        ->and(symbolAtToken($code, '3'))->toEqual(Symbol::constant('App\Rated', 'LEVEL'))
        ->and(symbolAtToken($code, '1'))->toEqual(Unnamed::of(SymbolKind::AttributeArgument))
        ->and(symbolAtToken($code, '4'))->toEqual(Unnamed::of(SymbolKind::AttributeArgument));
});

it('runs a global constant, a namespace block and an arrow function outside every class', function (): void {
    $code = '<?php namespace App { const ONE = 1; define(\'TWO\', 2); $f = fn ($q = 3) => $q; }';

    expect(symbolAtToken($code, '1'))->toEqual(Executable::line())
        ->and(symbolAtToken($code, '2'))->toEqual(Executable::line())
        ->and(symbolAtToken($code, '3'))->toEqual(Unnamed::of(SymbolKind::ClosureParameter));
});

it('reads nothing where no token stands', function (): void {
    $source = Php::source('<?php $a = 1;');
    $at = SymbolAt::in($source->tokens(), $source->shape());

    expect($at->token(-1))->toEqual(Executable::line())
        ->and($at->token($source->tokens()->count()))->toEqual(Executable::line());
});

it('reads a class member after a method\'s body, and nothing in a member without a value', function (): void {
    $code = '<?php class C { public function f() { } public $a; const B = 1; public function g($x) { } }';

    expect(symbolAtToken($code, '1'))->toEqual(Symbol::constant('C', 'B'))
        ->and(symbolAtToken($code, '$a'))->toEqual(Executable::line())
        ->and(symbolAtToken($code, '$x'))->toEqual(Executable::line());
});

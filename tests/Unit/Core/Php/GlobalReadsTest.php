<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Php\GlobalReads;
use NightWorksIO\MutationGate\Core\Php\Symbol;
use NightWorksIO\MutationGate\Tests\Support\Php;

it('reads a global constant wherever its name stands as a value, as the file may resolve it', function (): void {
    $read = GlobalReads::of(Symbol::globalConstant('App\LIMIT'), Php::source(<<<'PHP'
        <?php
        namespace App;
        use const Other\LIMIT as OTHER;
        echo LIMIT;
        echo \App\LIMIT + namespace\LIMIT;
        echo OTHER;
        LIMIT();
        LIMIT::class;
        $a->LIMIT;
        function f(LIMIT $x) { }
        new LIMIT();
        $x instanceof LIMIT;
        echo constant('App\LIMIT');
        echo constant('\App\LIMIT');
        echo constant('Other\LIMIT');
        PHP));

    expect(Php::sites($read))->toBe(['src/A.php:4', 'src/A.php:5', 'src/A.php:5', 'src/A.php:13', 'src/A.php:14'])
        ->and($read->isAmbiguous())->toBeFalse();
});

it('reads a global constant through its import, and the global one where a namespace falls back to it', function (): void {
    $imported = GlobalReads::of(Symbol::globalConstant('Other\LIMIT'), Php::source(<<<'PHP'
        <?php
        namespace App;
        use const Other\LIMIT as OTHER;
        echo OTHER;
        PHP));
    $fallback = GlobalReads::of(Symbol::globalConstant('LIMIT'), Php::source(<<<'PHP'
        <?php
        namespace App;
        echo LIMIT;
        PHP));

    expect(Php::sites($imported))->toBe(['src/A.php:4'])
        ->and(Php::sites($fallback))->toBe(['src/A.php:3']);
});

it('cannot follow a constant() of a name not written out', function (): void {
    $read = GlobalReads::of(Symbol::globalConstant('App\LIMIT'), Php::source(<<<'PHP'
        <?php
        namespace App;
        echo constant($name);
        echo constant('App\\' . $name);
        PHP));

    expect($read->isAmbiguous())->toBeTrue()
        ->and(Php::sites($read))->toBe([]);
});

it('reads no global constant where its name stands as no value', function (string $code): void {
    $read = GlobalReads::of(Symbol::globalConstant('LIMIT'), Php::source(sprintf("<?php\n%s\n", $code)));

    expect(Php::sites($read))->toBe([])
        ->and($read->isAmbiguous())->toBeFalse();
})->with([
    'a property' => ['$a->LIMIT;'],
    'a nullsafe property' => ['$a?->LIMIT;'],
    'a class constant' => ['Other::LIMIT;'],
    'a class made without brackets' => ['new LIMIT;'],
    'a class constant declared' => ['class A { const LIMIT = 1; }'],
    'an import' => ['use LIMIT;'],
    'an alias' => ['use Other as LIMIT;'],
    'a namespace' => ['namespace LIMIT;'],
    'a type checked' => ['$a instanceof LIMIT;'],
    'a parent class' => ['class A extends LIMIT {}'],
    'an interface implemented' => ['class A implements LIMIT {}'],
    'a class' => ['class LIMIT {}'],
    'an interface' => ['interface LIMIT {}'],
    'a trait' => ['trait LIMIT {}'],
    'an enum' => ['enum LIMIT {}'],
    'a goto' => ['goto LIMIT;'],
    'a function' => ['function LIMIT() {}'],
    'a method named as the lookup' => ["\$a->constant('LIMIT');"],
    'a static method named as the lookup' => ["Other::constant('LIMIT');"],
]);

it('reads a global constant through a constant() written fully qualified', function (): void {
    $read = GlobalReads::of(Symbol::globalConstant('LIMIT'), Php::source("<?php\necho \\constant('LIMIT');\n"));

    expect(Php::sites($read))->toBe(['src/A.php:2']);
});

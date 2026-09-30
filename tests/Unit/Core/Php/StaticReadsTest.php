<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Php\Hierarchy;
use NightWorksIO\MutationGate\Core\Php\References;
use NightWorksIO\MutationGate\Core\Php\StaticReads;
use NightWorksIO\MutationGate\Core\Php\Symbol;
use NightWorksIO\MutationGate\Tests\Support\Php;

const STATIC_READS_CLASSES = <<<'PHP'
    <?php
    namespace App;
    class Base { const RATE = 1; const OWN = 2; public static int $count = 3; }
    class Money extends Base { const OWN = 4; }
    class Other { const RATE = 5; }
    PHP;

/** Where one file reads a symbol of the classes above. */
function staticReadsIn(Symbol $symbol, string $code): References
{
    $source = Php::source($code, 'src/Reader.php');

    return StaticReads::of($symbol, $source, Hierarchy::of(Php::source(STATIC_READS_CLASSES), $source));
}

it('reads a constant through its owner, an alias, and a class that reaches it', function (): void {
    $read = staticReadsIn(Symbol::constant('App\Base', 'RATE'), <<<'PHP'
        <?php
        namespace App;
        use App\Base as B;
        B::RATE;
        Base::RATE;
        \App\Money::RATE;
        Other::RATE;
        Base::rate;
        Base::OWN;
        PHP);

    expect(Php::sites($read))->toBe(['src/Reader.php:4', 'src/Reader.php:5', 'src/Reader.php:6'])
        ->and($read->isAmbiguous())->toBeFalse();
});

it('reads through self, static and parent where the class reaches the owner, but not past a shadow', function (): void {
    $code = <<<'PHP'
        <?php
        namespace App;
        class Cash extends Base {
            function a() { return self::RATE; }
            function b() { return parent::RATE; }
            function c() { return static::OWN; }
        }
        class Coin extends Money { function d() { return self::OWN; } }
        self::RATE;
        PHP;

    expect(Php::sites(staticReadsIn(Symbol::constant('App\Base', 'RATE'), $code)))
        ->toBe(['src/Reader.php:4', 'src/Reader.php:5'])
        ->and(Php::sites(staticReadsIn(Symbol::constant('App\Base', 'OWN'), $code)))->toBe(['src/Reader.php:6']);
});

it('cannot follow static:: where a subclass declares the constant anew, or a variable class', function (): void {
    $overridden = staticReadsIn(
        Symbol::constant('App\Base', 'OWN'),
        '<?php namespace App; class Cash extends Base { function a() { return static::OWN; } }',
    );
    $variable = staticReadsIn(Symbol::constant('App\Base', 'RATE'), '<?php $class::RATE; $class::OTHER;');

    expect(Php::sites($overridden))->toBe(['src/Reader.php:1'])
        ->and($overridden->isAmbiguous())->toBeTrue()
        ->and(Php::sites($variable))->toBe([])
        ->and($variable->isAmbiguous())->toBeTrue()
        ->and(staticReadsIn(Symbol::constant('App\Base', 'RATE'), '<?php $class::OTHER;')->isAmbiguous())->toBeFalse();
});

it('reads constant() with the name written out, and cannot follow any other', function (): void {
    $written = staticReadsIn(Symbol::constant('App\Base', 'RATE'), <<<'PHP'
        <?php
        constant('App\Money::RATE');
        constant("\\App\\Base::RATE");
        constant('App\Base::OWN');
        constant('GLOBAL_ONE');
        $a->constant('x');
        PHP);
    $unwritten = staticReadsIn(Symbol::constant('App\Base', 'RATE'), '<?php constant($name);');

    expect(Php::sites($written))->toBe(['src/Reader.php:2', 'src/Reader.php:3'])
        ->and($written->isAmbiguous())->toBeFalse()
        ->and($unwritten->isAmbiguous())->toBeTrue()
        ->and(staticReadsIn(Symbol::constant('App\Base', 'RATE'), "<?php constant('A' . 'B');")->isAmbiguous())
        ->toBeTrue();
});

it('cannot follow reflection on the owner, but a file that reflects on something else reads nothing', function (): void {
    expect(staticReadsIn(
        Symbol::constant('App\Base', 'RATE'),
        '<?php namespace App; new \ReflectionClass(Base::class);',
    )->isAmbiguous())->toBeTrue()
        ->and(staticReadsIn(
            Symbol::constant('App\Base', 'RATE'),
            '<?php namespace App; new \ReflectionClass(Other::class);',
        )->isAmbiguous())->toBeFalse();
});

it('reads a static property through the owner and a class that reaches it, and nothing else', function (): void {
    $read = staticReadsIn(Symbol::property('App\Base', 'count', static: true), <<<'PHP'
        <?php
        namespace App;
        Money::$count;
        Base::count;
        Other::$count;
        constant($name);
        PHP);

    expect(Php::sites($read))->toBe(['src/Reader.php:3'])
        ->and($read->isAmbiguous())->toBeFalse();
});

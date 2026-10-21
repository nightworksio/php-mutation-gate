<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Php\ValueDeclaration;
use NightWorksIO\MutationGate\Core\Php\ValueDeclarations;
use NightWorksIO\MutationGate\Tests\Support\Php;

/**
 * Each value a file declares, as its symbol describes it, with its first and last line.
 *
 * @return list<string>
 */
function declaredValues(string $code): array
{
    return array_map(
        static fn(ValueDeclaration $declared): string => sprintf(
            '%s %d-%d',
            $declared->symbol()->described(),
            $declared->first()->number(),
            $declared->last()->number(),
        ),
        ValueDeclarations::in(Php::source($code)),
    );
}

it('declares each constant of a named class-like and each backed case, from its name to the end of its value', function (): void {
    expect(declaredValues(<<<'PHP'
        <?php
        namespace App;
        final class Money
        {
            public const int RATE = 2;
            private const A = 1, B = [
                self::A,
            ];
            public function f(int $x): int { switch ($x) { case 1: return 1; } return self::RATE; }
        }
        enum Status: string
        {
            case Open = 'o';
            case Closed;
            const DEFAULT = self::Open;
        }
        $anonymous = new class { const Z = 9; };
        PHP))->toBe([
        'constant App\Money::RATE 5-5',
        'constant App\Money::A 6-6',
        'constant App\Money::B 6-8',
        'enum case App\Status::Open 13-13',
        'constant App\Status::DEFAULT 15-15',
    ]);
});

it('declares each global constant a const statement or a define() with its name written out names, and no import', function (): void {
    expect(declaredValues(<<<'PHP'
        <?php
        namespace App;
        use const Other\X;
        const LIMIT = 3, FLOOR = [
            1,
        ];
        define('App\RATE', 0.5);
        define($name, 1);
        $a->define('B', 2);
        \define('\C', 3);
        PHP))->toBe([
        'global constant App\LIMIT 4-4',
        'global constant App\FLOOR 4-6',
        'global constant App\RATE 7-7',
        'global constant C 10-10',
    ]);
});

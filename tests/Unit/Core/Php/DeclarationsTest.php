<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\Php\Declarations;
use NightWorksIO\MutationGate\Core\Php\Declared;
use NightWorksIO\MutationGate\Core\Php\Names;
use NightWorksIO\MutationGate\Core\Php\Undeclared;
use NightWorksIO\MutationGate\Tests\Support\Php;

$declarations = static fn(): Declarations => Declarations::of(Php::source(<<<'PHP'
    <?php
    namespace App;
    function helper(): int { return 1; }
    interface Shape { public function area(): float; }
    final class Money
    {
        public function add(): int
        {
            return 1;
        }
    }
    PHP, 'src/Money.php'));

/**
 * Which of the lines around a declared body it holds.
 *
 * @return array<bool>
 */
function heldLines(Declared|Undeclared $found, int ...$lines): array
{
    return array_map(static fn(int $line): bool => $found instanceof Declared && $found->holds(Line::of($line)), $lines);
}

it('reads a method by its class and name, its body from its opening brace\'s line to its closing one\'s', function () use ($declarations): void {
    expect(heldLines($declarations()->method('App\Money', 'ADD'), 7, 8, 10, 11))->toBe([false, true, true, false]);
});

it('reads a function on one line by its name', function () use ($declarations): void {
    expect(heldLines($declarations()->function(Names::of('App\helper')), 2, 3, 4))->toBe([false, true, false]);
});

it('reads no method that has no body', function () use ($declarations): void {
    expect($declarations()->method('App\Shape', 'area'))->toEqual(Undeclared::callee());
});

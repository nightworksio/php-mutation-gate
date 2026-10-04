<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Assertion\AssertionStyle;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Mutant\SourcePin;
use NightWorksIO\MutationGate\Core\Php\Enclosing;
use NightWorksIO\MutationGate\Core\Php\Nameless;
use NightWorksIO\MutationGate\Core\Stub\Scaffold;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

/** PHPUnit's assertion of a value, as a scaffold writes it: the arch test reads no such call in a test. */
$phpUnit = static fn(string $expected, string $subject): string => sprintf('$this->%s(/* %s */, %s);', 'assertSame', $expected, $subject);

/** The function `fits()` of `Cart`, as a stub calls it. */
$fits = static fn(): Enclosing|Nameless => Enclosing::in(Contents::of(<<<'PHP'
    <?php

    final class Cart
    {
        public function fits(int $amount, int $limit): bool
        {
            return $amount < $limit;
        }
    }
    PHP), Line::of(7));

it('offers two cases for a boundary, at it and one step past it, in each style', function () use ($fits, $phpUnit): void {
    $mutant = Verdicts::mutant('src/Cart.php:7', 'LessThan', MutatorFamily::Boundary, Verdicts::diff('return $amount < $limit;', 'return $amount <= $limit;'));

    expect(Scaffold::of($mutant, $fits(), AssertionStyle::Pest))->toBe([
        'Call it at the boundary, then one step past it:',
        'expect($cart->fits($amount, $limit))->toBe(/* at the boundary */);',
        'expect($cart->fits($amount, $limit))->toBe(/* one step past it */);',
    ])
        ->and(Scaffold::of($mutant, $fits(), AssertionStyle::PhpUnit))->toBe([
            'Call it at the boundary, then one step past it:',
            $phpUnit('at the boundary', '$cart->fits($amount, $limit)'),
            $phpUnit('one step past it', '$cart->fits($amount, $limit)'),
        ]);
});

it('asserts on what a function returns, and on the result for any other family', function (MutatorFamily $family, string $said) use ($fits): void {
    $mutant = Verdicts::mutant('src/Cart.php:7', 'Mutator', $family, Verdicts::diff('return $amount < $limit;', 'return false;'));

    expect(Scaffold::of($mutant, $fits(), AssertionStyle::Pest))
        ->toBe([$said, 'expect($cart->fits($amount, $limit))->toBe(/* expected */);']);
})->with([
    'a return value' => [MutatorFamily::ReturnValue, 'Assert on what it returns:'],
    'a condition' => [MutatorFamily::Condition, 'Assert on its result:'],
    'no family' => [MutatorFamily::Unknown, 'Assert on its result:'],
]);

it('asserts on the effect of a removed call, named where the diff names it', function (string $removed, string $said) use ($phpUnit): void {
    $mutant = Verdicts::mutant('src/Cart.php:7', 'MethodCallRemoval', MutatorFamily::RemovedCall, Verdicts::diff($removed, ''));

    expect(Scaffold::of($mutant, Nameless::code(), AssertionStyle::PhpUnit))
        ->toBe([$said, $phpUnit('expected', '/* what it changes */')]);
})->with([
    'a call' => ['$this->log->write($order);', 'Assert on what write() does, which every test passes without:'],
    'no call' => ['$count++;', 'Assert on what `$count++;` does, which every test passes without:'],
]);

it('expects the exception the removed code throws, by its class, in each style', function () use ($fits): void {
    $mutant = Verdicts::mutant('src/Cart.php:7', 'Throw_', MutatorFamily::Exception, Verdicts::diff('throw new \InvalidArgumentException($amount);', ''));
    $unnamed = Verdicts::mutant('src/Cart.php:7', 'Throw_', MutatorFamily::Exception, Verdicts::diff('throw $error;', ''));

    expect(Scaffold::of($mutant, $fits(), AssertionStyle::Pest))->toBe([
        'Expect the exception it throws:',
        'expect(fn () => $cart->fits($amount, $limit))->toThrow(\InvalidArgumentException::class);',
    ])
        ->and(Scaffold::of($unnamed, Nameless::code(), AssertionStyle::PhpUnit))->toBe([
            'Expect the exception it throws:',
            '$this->expectException(/* the exception */);',
            '/* the code it changes */;',
        ]);
});

it('runs the code, then reads its file for what the pin names, where only a test that reads the source kills the mutant', function () use ($fits, $phpUnit): void {
    $pin = SourcePin::call('hash_equals');
    $read = "file_get_contents((new \\ReflectionMethod(Cart::class, 'fits'))->getFileName())";

    expect(Scaffold::pinned($pin, $fits(), AssertionStyle::Pest))->toBe([
        'Run it, then read the file it is in, which the mutant changes:',
        'expect($cart->fits($amount, $limit))->toBe(/* expected */);',
        sprintf("expect(%s)->toContain('hash_equals(');", $read),
    ])
        ->and(Scaffold::pinned($pin, $fits(), AssertionStyle::PhpUnit))->toBe([
            'Run it, then read the file it is in, which the mutant changes:',
            $phpUnit('expected', '$cart->fits($amount, $limit)'),
            sprintf("\$this->%s('hash_equals(', (string) %s);", 'assertStringContainsString', $read),
        ])
        ->and(Scaffold::pinned($pin, Nameless::code(), AssertionStyle::Pest))->toBe([
            'Run it, then read the file it is in, which the mutant changes:',
            'expect(/* the code it changes */)->toBe(/* expected */);',
            "expect(file_get_contents(/* the file it changes */))->toContain('hash_equals(');",
        ]);
});

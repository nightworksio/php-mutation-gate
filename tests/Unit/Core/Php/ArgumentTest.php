<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Php\Argument;

/** An argument, by how it is written. */
function writtenArgument(string $written): Argument
{
    $tokens = [];

    foreach (PhpToken::tokenize(sprintf('<?php %s;', $written)) as $token) {
        if (! $token->isIgnorable()) {
            $tokens[] = $token;
        }
    }

    return Argument::of(array_slice($tokens, 0, -1));
}

it('reads an expression that runs nothing as it is evaluated', function (string $written): void {
    expect(writtenArgument($written)->runsNothing())->toBeTrue();
})->with([
    'a string' => ["'adds'"],
    'a number, negative or not' => ['-1.5 + 2'],
    'a constant, true, false and null' => ['PHP_EOL . true . false . null'],
    'a class name and a class constant' => ['Library\\Money::class . \\Library\\Money::LIMIT'],
    'an array of these, keyed or not' => ["[[1 => 'a', 'b' => Money::class], array(2, 3)]"],
    'comparisons and logic, grouped' => ["(PHP_OS_FAMILY === 'Windows' || ! true) && 1 <=> 2 ?: 3 ?? 4"],
    'the file, its directory and its namespace' => ["__DIR__ . __FILE__ . __LINE__ . __NAMESPACE__"],
    'arithmetic' => ['2 * 3 / 4 % 5 ** 6'],
    'bitwise operators' => ['~1 & 2 | 3 ^ 4 << 5 >> 6'],
    'strict and loose comparisons' => ['1 < 2 == 3 > 4 != 5 !== 6 <= 7 >= 8'],
    'spelt logic' => ['true and false or true xor false'],
    'an array spread' => ['[...[1, 2]]'],
    'a nowdoc' => ["<<<'TEXT'\nadds\nTEXT"],
    'closures, which run only when called' => ["[fn () => putenv('A=1'), static function () use (\$x): void { \$GLOBALS['a'] = 1; }]"],
    'a named argument' => ["name: 'adds'"],
]);

it('reads an expression that runs code as it is evaluated', function (string $written): void {
    expect(writtenArgument($written)->runsNothing())->toBeFalse();
})->with([
    'a function call' => ["sprintf('%d', 1)"],
    'a static call' => ['Money::make()'],
    'a call of a string' => ["'strlen'('x')"],
    'a closure called in place' => ['(fn () => 1)()'],
    'a variable' => ['$amount'],
    'an assignment' => ["\$GLOBALS['a'] = 1"],
    'an increment' => ['++$count'],
    'new' => ['new Money()'],
    'an include' => ["require 'helpers.php'"],
    'a string with a variable in it' => ['"adds {$name}"'],
    'a static property' => ['Money::$limit'],
    'a match' => ['match (true) { default => 1 }'],
]);

it('reads a closure written in place, with or without attributes, static or by reference, as a closure', function (string $written): void {
    expect(writtenArgument($written)->isClosure())->toBeTrue();
})->with([
    'a function' => ['function (int $a) use ($b): void { echo $a; }'],
    'an arrow function' => ['fn (array $a = [1 => 2]): int => count($a)'],
    'a static one with an attribute' => ["#[Holds('src/Money.php')] static fn () => 1"],
    'one returning by reference' => ['function &() { static $a; return $a; }'],
    'one with a union return type' => ['fn (): (A&B)|null => null'],
]);

it('reads anything else as no closure', function (string $written): void {
    expect(writtenArgument($written)->isClosure())->toBeFalse();
})->with([
    'a string' => ["'adds'"],
    'a closure called in place' => ['(fn () => 1)()'],
    'a closure joined to more' => ['function () {} . 1'],
    'an array of closures' => ['[fn () => 1]'],
]);

it('reads a closure that only returns or yields what runs nothing as only giving', function (string $written): void {
    expect(writtenArgument($written)->onlyGives())->toBeTrue();
})->with([
    'an arrow function' => ['fn (): array => [[1], [2]]'],
    'a return' => ['function (): array { return [[1], [2]]; }'],
    'yields, keyed or not' => ["function (): iterable { yield 'one' => [1]; yield [2]; yield from [[3]]; }"],
    'rows that are closures, which run as the test runs' => ['fn () => [fn () => new Money()]'],
]);

it('reads any other argument as not only giving', function (string $written): void {
    expect(writtenArgument($written)->onlyGives())->toBeFalse();
})->with([
    'an array' => ['[[1]]'],
    'a closure that runs more' => ["function (): array { putenv('A=1'); return [[1]]; }"],
    'an arrow function that returns what runs code' => ["fn () => [getenv('A')]"],
    'a function that returns what runs code' => ["function () { return [getenv('A')]; }"],
    'a closure that returns nothing' => ['function () { return; }'],
    'a closure that declares a function' => ['function () { function shared() {} return [[1]]; }'],
    'a closure that loops' => ['function () { foreach ([1] as $a) { yield [$a]; } }'],
]);

it('reads a closure\'s body as its statements, and an arrow function\'s expression as one', function (): void {
    $spelt = static fn(string $written): array => array_map(
        static fn(array $statement): string => implode(' ', array_map(static fn(PhpToken $token): string => $token->text, $statement)),
        writtenArgument($written)->body()->running(),
    );

    expect($spelt("function () { it('a'); it('b'); }"))->toBe(["it ( 'a' ) ;", "it ( 'b' ) ;"])
        ->and($spelt("fn () => it('a')"))->toBe(["it ( 'a' ) ;"])
        ->and($spelt("'adds'"))->toBe([]);
});

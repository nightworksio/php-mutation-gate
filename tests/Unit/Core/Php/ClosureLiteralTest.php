<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Php\ClosureLiteral;
use NightWorksIO\MutationGate\Core\Php\Tokens;

/** What is written, as the tokens a closure is read from, with nothing PHP ignores. */
function closureLiteralTokens(string $written): Tokens
{
    $tokens = [];

    foreach (PhpToken::tokenize(sprintf('<?php %s', $written)) as $token) {
        if (! $token->isIgnorable()) {
            $tokens[] = $token;
        }
    }

    return Tokens::of($tokens);
}

/** The closure that starts at an index of what is written, as `<opens>..<end>`, arrow or not; `none` where none starts. */
function closureLiteralAt(string $written, int $at): string
{
    $closure = ClosureLiteral::at(closureLiteralTokens($written), $at);

    return $closure->isOne()
        ? sprintf('%d..%d%s', $closure->opens(), $closure->end(), $closure->isArrow() ? ' arrow' : '')
        : 'none';
}

it('reads where a closure\'s body opens and where it ends', function (string $written, int $at, string $read): void {
    expect(closureLiteralAt($written, $at))->toBe($read);
})->with([
    'a function' => ['function ($a) use ($b): int { return 1; }', 0, '10..15'],
    'an arrow function, ended by a comma' => ['[fn () => [1], 2]', 1, '4..8 arrow'],
    'an arrow function, ended by its bracket' => ['(fn () => 1)', 1, '4..6 arrow'],
    'one with an attribute, static and by reference' => ["#[A('x')] static function &() {}", 0, '11..13'],
    'one whose return type is grouped' => ['fn (): (A&B)|null => null', 0, '11..13 arrow'],
    'one with an attribute alone' => ['#[A] function () {}', 0, '6..8'],
    'one with two attributes' => ['#[A] #[B] fn () => 1', 0, '9..11 arrow'],
    'one by reference alone' => ['function &() {}', 0, '4..6'],
    'one inside an array' => ['[1, function () {}]', 3, '6..8'],
    'a function whose body holds an arrow function' => ['function () { return fn () => 1; }', 0, '3..12'],
    'an arrow function whose expression is another' => ['fn () => fn () => 1, 2', 0, '3..9 arrow'],
    'an arrow function ended by nothing' => ['fn () => 1', 0, '3..5 arrow'],
    'no closure' => ['foo(1)', 0, 'none'],
    'a function with no parameters written' => ['function {}', 0, 'none'],
    'a closure never opened' => ['fn ()', 0, 'none'],
    'a function never opened, before another item' => ["[function (), 'a' => 1]", 1, 'none'],
]);

it('is no arrow function where no closure starts, though its keyword is `fn`', function (): void {
    expect(ClosureLiteral::at(closureLiteralTokens('fn ()'), 0)->isArrow())->toBeFalse();
});

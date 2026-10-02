<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Php\ClosureLiteral;
use NightWorksIO\MutationGate\Core\Php\Tokens;

/** The closure that starts at an index of what is written, as `<opens>..<end>`, arrow or not; `none` where none starts. */
function closureLiteralAt(string $written, int $at): string
{
    $tokens = [];

    foreach (PhpToken::tokenize(sprintf('<?php %s', $written)) as $token) {
        if (! $token->isIgnorable()) {
            $tokens[] = $token;
        }
    }

    $closure = ClosureLiteral::at(Tokens::of($tokens), $at);

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
    'no closure' => ['foo(1)', 0, 'none'],
    'a function with no parameters written' => ['function {}', 0, 'none'],
    'a closure never opened' => ['fn ()', 0, 'none'],
]);

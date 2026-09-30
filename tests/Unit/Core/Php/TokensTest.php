<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Php\Tokens;

$tokens = static fn(string $code): Tokens => Tokens::of(array_values(array_filter(
    PhpToken::tokenize($code),
    static fn(PhpToken $token): bool => ! $token->isIgnorable(),
)));

it('finds each token of some kinds, in order', function () use ($tokens): void {
    $read = $tokens('<?php f(a, [b], $c);');

    expect($read->indicesOf(T_STRING))->toBe([0, 2, 5])
        ->and($read->indicesOf('(', '['))->toBe([1, 4])
        ->and($read->indicesOf(T_ATTRIBUTE))->toBe([]);
});

it('reads a token by its index, and nothing past either end', function () use ($tokens): void {
    $read = $tokens("<?php\n\nf(\n  a\n);");

    expect($read->is(0, T_STRING))->toBeTrue()
        ->and($read->is(0, '(', T_STRING))->toBeTrue()
        ->and($read->is(0, '('))->toBeFalse()
        ->and($read->is(-1, T_STRING))->toBeFalse()
        ->and($read->is(5, ';'))->toBeFalse()
        ->and($read->text(2))->toBe('a')
        ->and($read->line(0))->toBe(3)
        ->and($read->line(2))->toBe(4);
});

it('pairs every kind of bracket, and says which one each token stands inside', function () use ($tokens): void {
    $read = $tokens('<?php #[A] f($a[0], "{$b}", "${c}", fn () => { return 1; });');

    expect($read->enclosing(0))->toBe(-1)
        ->and($read->closing(0))->toBe(2)
        ->and($read->enclosing(1))->toBe(0)
        ->and($read->enclosing(2))->toBe(0)
        ->and($read->enclosing(3))->toBe(-1)
        ->and($read->closing(4))->toBe(31)
        ->and($read->enclosing(5))->toBe(4)
        ->and($read->closing(6))->toBe(8)
        ->and($read->enclosing(7))->toBe(6)
        ->and($read->closing(11))->toBe(13)
        ->and($read->enclosing(12))->toBe(11)
        ->and($read->closing(17))->toBe(19)
        ->and($read->enclosing(18))->toBe(17)
        ->and($read->closing(23))->toBe(24)
        ->and($read->closing(26))->toBe(30)
        ->and($read->enclosing(28))->toBe(26)
        ->and($read->enclosing(31))->toBe(4)
        ->and($read->enclosing(32))->toBe(-1);
});

it('finds the tokens of some kinds directly inside a bracket, and none deeper', function () use ($tokens): void {
    $read = $tokens('<?php f(a, g(b, c), d);');

    expect($read->inside(1, T_STRING))->toBe([2, 4, 11])
        ->and($read->inside(1, ','))->toBe([3, 10])
        ->and($read->inside(5, T_STRING, ','))->toBe([6, 7, 8])
        ->and($read->inside(-1, T_STRING, ';'))->toBe([0, 13]);
});

it('closes a bracket that never closes past the last token, and ignores one that closes nothing', function () use ($tokens): void {
    $read = $tokens('<?php ) f(a, [b');

    expect($read->enclosing(1))->toBe(-1)
        ->and($read->closing(2))->toBe(7)
        ->and($read->closing(5))->toBe(7)
        ->and($read->enclosing(6))->toBe(5);
});

it('spells tokens as they are written, with whatever stands between two of them read as one space', function () use ($tokens): void {
    $read = $tokens("<?php f(self::KERNEL, Paths::ROOT  /* the root */ .\n '/ü'.X);");

    expect($read->spelt(2, 5))->toBe('self::KERNEL')
        ->and($read->spelt(6, 13))->toBe("Paths::ROOT . '/ü'.X")
        ->and($read->spelt(6, 6))->toBe('');
});

it('counts its tokens, and says whether a bracket encloses one', function () use ($tokens): void {
    $read = $tokens('<?php f(a);');

    expect($read->count())->toBe(5)
        ->and(count($read))->toBe(5)
        ->and($read->isEnclosed(0))->toBeFalse()
        ->and($read->isEnclosed(2))->toBeTrue()
        ->and($read->isEnclosed(3))->toBeTrue()
        ->and($read->isEnclosed(4))->toBeFalse();
});

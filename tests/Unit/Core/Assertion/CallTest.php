<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Assertion\Call;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\Php\Tokens;

/**
 * The call a file's first name makes, read from its tokens, as its arguments, its first and its second, and
 * whether its first is keyed.
 *
 * @return array{int, string, string, bool}
 */
function callRead(string $code): array
{
    $tokens = Tokens::in(Contents::of(sprintf("<?php\n%s\n", $code)));
    $call = Call::at($tokens, $tokens->indicesOf(T_STRING)[0] ?? Tokens::NONE);

    return [$call->arguments(), $call->first(), $call->second(), $call->isFirstKeyed()];
}

it('reads a call\'s arguments as written, a trailing comma adding none, and nothing past them', function (): void {
    expect([callRead('assertSame(1, $x,);'), callRead('assertTrue();'), callRead('assertSame(\Null, $x);')])->toBe([
        [2, '1', '$x', false],
        [0, '', '', false],
        [2, 'null', '$x', false],
    ]);
});

it('reads a first argument as keyed where it is an array that holds a key of its own', function (): void {
    expect([
        callRead("toHaveKeys(['total' => 3]);")[3],
        callRead('toHaveKeys([1, 2]);')[3],
        callRead("toHaveKeys([[1 => 2]]);")[3],
        callRead("toBe(1, ['total' => 3]);")[3],
    ])->toBe([true, false, false, false]);
});

<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\Php\Body;
use NightWorksIO\MutationGate\Core\Php\Tokens;

it('finds the brace that opens a declaration\'s body, and none where a semicolon ends it first or nothing follows', function (): void {
    $tokens = Tokens::in(Contents::of("<?php\n\ninterface Priced\n{\n    public function price(): int;\n}\n\nfunction total(): int\n{\n    return 1;\n}\n"));
    $functions = $tokens->indicesOf(T_FUNCTION);
    $total = Body::after($tokens, $functions[1]);

    expect(Body::after($tokens, $functions[0]))->toBe(Tokens::NONE)
        ->and($tokens->text($total))->toBe('{')
        ->and($tokens->text($total + 1))->toBe('return')
        ->and(Body::after($tokens, $tokens->count()))->toBe(Tokens::NONE);
});

<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Php\Identifiers;

/** @return list<string> the text of each token the code holds as a name, once each semi-reserved name is read as one */
function identifiersNamed(string $code): array
{
    $tokens = array_values(array_filter(PhpToken::tokenize($code), static fn(PhpToken $token): bool => ! $token->isIgnorable()));

    return array_values(array_map(
        static fn(PhpToken $token): string => $token->text,
        array_filter(Identifiers::of($tokens), static fn(PhpToken $token): bool => $token->is(T_STRING)),
    ));
}

it('reads a semi-reserved word as a name where a constant, an enum case or a method declares it, and after ::', function (): void {
    expect(identifiersNamed(<<<'PHP'
        <?php
        enum Mode: string { case Default = 'd'; case List; public function and(): self { return self::Default; } }
        final class K { const array GLOBAL = [1]; const PRINT = 2; public function &for() { return self::GLOBAL; } }
        PHP))->toBe(['Mode', 'string', 'Default', 'List', 'and', 'self', 'self', 'Default', 'K', 'GLOBAL', 'PRINT', 'for', 'self', 'GLOBAL']);
});

it('reads no keyword as a name elsewhere: ::class, a keyword the case of a switch reads, or a closure', function (): void {
    expect(identifiersNamed(<<<'PHP'
        <?php
        $c = K::class;
        switch ($v) { case static::LIMIT: break; }
        $f = function () use ($c) { return list($a) = [1]; };
        $g = function &() { static $s; return $s; };
        PHP))->toBe(['K', 'LIMIT']);
});

<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Test;

use function in_array;

use NightWorksIO\MutationGate\Core\Php\Body;
use NightWorksIO\MutationGate\Core\Php\Tokens;

/**
 * Each method with a body that a named class, trait or enum of a file
 * declares, by its name, from its comments and attributes to its body's end.
 *
 * @internal
 */
final readonly class MethodDeclarations
{
    /** @return list<Declared> */
    public static function in(Tokens $tokens): array
    {
        $bodies = self::namedClassBodies($tokens);
        $methods = [];

        foreach ($tokens->indicesOf(T_FUNCTION) as $keyword) {
            $name = $tokens->functionName($keyword);
            $body = $name === Tokens::NONE ? Tokens::NONE : Body::after($tokens, $name);

            if ($body !== Tokens::NONE && in_array($tokens->enclosing($keyword), $bodies, strict: true)) {
                $methods[] = Declared::between(
                    $tokens->text($name),
                    $tokens,
                    self::statementOf($tokens, $keyword),
                    $tokens->closing($body),
                );
            }
        }

        return $methods;
    }

    /**
     * Where the body of each class, trait or enum that has a name opens.
     *
     * @return list<int>
     */
    private static function namedClassBodies(Tokens $tokens): array
    {
        $bodies = [];

        foreach ($tokens->indicesOf(...Tokens::CLASS_LIKE) as $keyword) {
            $bodies = $tokens->is($keyword - 1, T_NEW) ? $bodies : [...$bodies, Body::after($tokens, $keyword)];
        }

        return $bodies;
    }

    /**
     * Where the declaration of a method whose `function` keyword is at an
     * index begins: past the class body's `{`, or the member before it.
     */
    private static function statementOf(Tokens $tokens, int $keyword): int
    {
        $first = $keyword;

        while (! $tokens->is($first - 1, ...Tokens::STATEMENT_BOUNDS)) {
            $first--;
        }

        return $first;
    }
}

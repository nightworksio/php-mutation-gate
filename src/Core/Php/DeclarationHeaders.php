<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Php;

use function array_any;
use function array_push;
use function count;
use function implode;

use NightWorksIO\MutationGate\Core\File\Contents;
use PhpToken;

use function sprintf;

/**
 * What a PHP file declares that other files read, read from its tokens
 * (ADR-0020): each class, interface, trait and enum's header up
 * to its body, and every statement of that body up to its own body, which
 * is a method's signature, a property, a constant, an enum case or a trait
 * use; and each function's signature, and each constant, a file or a
 * namespace declares. A change to any of these can break a file that uses
 * them; a change inside a function's or a method's body cannot.
 *
 * @internal
 */
final readonly class DeclarationHeaders
{
    /** What makes a statement outside a type declare something besides a type, or a namespace whose body declares. */
    private const array OUTSIDE_TYPES = [T_FUNCTION, T_CONST, T_NAMESPACE];

    /** @param list<string> $headers each header, as written, in the order the file declares them */
    private function __construct(private array $headers)
    {
    }

    /** The headers of what a file declares. */
    public static function in(Contents $file): self
    {
        return new self(self::within(Tokens::in($file), Tokens::NONE, members: false));
    }

    /** The headers, as PHP: what of the file other files can read, without one of its bodies. */
    public function asPhp(): Contents
    {
        return Contents::of(sprintf("<?php\n%s\n", implode(";\n", $this->headers)));
    }

    /** Whether two files declare the same things, with the same headers, in the same order. */
    public function same(self $other): bool
    {
        return $this->headers === $other->headers;
    }

    /**
     * The headers among the statements directly inside a bracket, or the
     * file where none: every statement's, inside a type's body; and outside
     * one, the statements that declare.
     *
     * @return list<string>
     */
    private static function within(Tokens $tokens, int $scope, bool $members): array
    {
        $headers = [];
        $from = $scope + 1;
        $end = $scope === Tokens::NONE ? count($tokens) : $tokens->closing($scope);

        for ($at = $from; $at < $end; $at++) {
            if ($tokens->enclosing($at) !== $scope || ! $tokens->is($at, ';', '{')) {
                continue;
            }

            array_push($headers, ...self::statement($tokens, $scope, $from, $at, $members));
            $at = $tokens->is($at, '{') ? $tokens->closing($at) : $at;
            $from = $at + 1;
        }

        return $headers;
    }

    /**
     * The headers of a statement directly inside a bracket, from one index
     * to the `;` or `{` that ends it: its own, where it declares, and those
     * inside its body, where it is a type or a namespace.
     *
     * @return list<string>
     */
    private static function statement(Tokens $tokens, int $scope, int $from, int $at, bool $members): array
    {
        $type = self::declares($tokens, $scope, $from, $at, Tokens::CLASS_LIKE);
        $declares = $members || $type || self::declares($tokens, $scope, $from, $at, self::OUTSIDE_TYPES);
        $nested = $tokens->is($at, '{') && ($type || $tokens->is($from, T_NAMESPACE));

        return [
            ...$declares ? [$tokens->spelt($from, $at)] : [],
            ...$nested ? self::within($tokens, $at, $type) : [],
        ];
    }

    /**
     * Whether a statement, from one index up to another, holds one of these
     * tokens directly inside the scope, not as the `class` of `Name::class`.
     *
     * @param list<int> $kinds
     */
    private static function declares(Tokens $tokens, int $scope, int $from, int $to, array $kinds): bool
    {
        return array_any(
            $tokens->between($from, $to),
            static fn(PhpToken $token, int $at): bool => $tokens->enclosing($at) === $scope
                && $token->is($kinds)
                && ! $tokens->is($at - 1, T_DOUBLE_COLON),
        );
    }
}

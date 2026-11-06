<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Hint;

use function array_slice;
use function array_values;
use function implode;
use function in_array;

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Php\Tokens;
use PhpToken;

use function sprintf;
use function trim;

/**
 * The two sides of an expression and the operator between them, outside
 * every bracket (ADR-0101): `$amount < 10` as `$amount`, `<` and `10`.
 *
 * @internal
 */
final readonly class Operands
{
    private function __construct(public string $left, public string $operator, public string $right)
    {
    }

    /**
     * The sides of an expression around the first of these operators outside every bracket; none where it has none.
     *
     * @param list<string> $operators
     */
    public static function of(string $expression, array $operators): self|NotGiven
    {
        $tokens = array_values(array_slice(PhpToken::tokenize(sprintf('%s%s', Change::OPENING, $expression)), 1));
        $found = self::operatorIn($tokens, $operators);

        if ($found instanceof NotGiven) {
            return $found;
        }

        [$at, $operator] = $found;

        return new self(
            self::text(array_slice($tokens, 0, $at)),
            $operator->text,
            self::text(array_slice($tokens, $at + 1)),
        );
    }

    /**
     * The first of these operators outside every bracket, and where it is; none where there is none.
     *
     * @param  list<PhpToken>             $tokens
     * @param  list<string>               $operators
     * @return array{int, PhpToken}|NotGiven
     */
    private static function operatorIn(array $tokens, array $operators): array|NotGiven
    {
        $depth = 0;

        foreach ($tokens as $at => $token) {
            $depth += $token->is(Tokens::OPENS) ? 1 : 0;
            $depth -= $token->is(Tokens::CLOSES) ? 1 : 0;

            if ($depth === 0 && in_array($token->text, $operators, strict: true)) {
                return [$at, $token];
            }
        }

        return NotGiven::value();
    }

    /** @param list<PhpToken> $tokens */
    private static function text(array $tokens): string
    {
        return trim(implode('', $tokens));
    }
}

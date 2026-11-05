<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Subsumption;

use function array_key_exists;
use function array_slice;
use function in_array;

use NightWorksIO\MutationGate\Core\NotGiven;

/**
 * The groups the brackets among a line's tokens open and close (ADR-0079):
 * a `(` closed by a `)`, and a `[` by a `]`.
 *
 * @internal
 */
final readonly class Brackets
{
    /** Each bracket that opens a group, with the one that closes it. */
    private const array GROUPS = ['(' => ')', '[' => ']'];

    /** Whether the token opens a group. */
    public static function opens(string $token): bool
    {
        return array_key_exists($token, self::GROUPS);
    }

    /**
     * Whether the tokens close every group they open, and close none they
     * did not open.
     *
     * @param list<string> $tokens
     */
    public static function areBalanced(array $tokens): bool
    {
        $depth = 0;

        foreach ($tokens as $token) {
            $depth = self::depthAfter($depth, $token);

            if ($depth < 0) {
                return false;
            }
        }

        return $depth === 0;
    }

    /**
     * The token that closes the group a bracket opens; none where the tokens end first.
     *
     * @param list<string> $tokens
     */
    public static function closing(array $tokens, int $opens): int|NotGiven
    {
        $depth = 0;

        foreach (array_slice($tokens, $opens, preserve_keys: true) as $at => $token) {
            $depth = self::depthAfter($depth, $token);

            if ($depth === 0) {
                return $at;
            }
        }

        return NotGiven::value();
    }

    /** How many groups are open past a token, this many open before it. */
    private static function depthAfter(int $depth, string $token): int
    {
        return match (true) {
            self::opens($token) => $depth + 1,
            in_array($token, self::GROUPS, strict: true) => $depth - 1,
            default => $depth,
        };
    }
}

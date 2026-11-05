<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Serve;

use function array_filter;

use const ARRAY_FILTER_USE_KEY;

use function in_array;

use NightWorksIO\MutationGate\Core\Push\PreCommitPush;

/**
 * The variables of a client's environment a daemon of `mutation-gate serve`
 * takes for the command it runs (ADR-0078): the ones the pre-commit
 * framework names a push with, and the terminal's size. A child runs in the
 * daemon's own environment otherwise, so a client never sends a token or a
 * secret it holds to whatever answers on the socket.
 *
 * @internal
 */
final readonly class ServedVariables
{
    /** The variable a daemon's child runs with, so the command it runs is never handed to a daemon again. */
    public const string SERVED = 'MUTATION_GATE_SERVED';

    /** The terminal's width, as a shell sets it. */
    private const string COLUMNS = 'COLUMNS';

    /** The terminal's height, as a shell sets it. */
    private const string LINES = 'LINES';

    /** @return list<string> the names a client sends, and a child takes or unsets */
    public static function names(): array
    {
        return [...PreCommitPush::variables(), self::COLUMNS, self::LINES];
    }

    /**
     * Of an environment, the variables a client sends.
     *
     * @param  array<string, string> $environment each variable, by its name
     * @return array<string, string>
     */
    public static function of(array $environment): array
    {
        return array_filter(
            $environment,
            static fn(string $name): bool => in_array($name, self::names(), strict: true),
            ARRAY_FILTER_USE_KEY,
        );
    }
}

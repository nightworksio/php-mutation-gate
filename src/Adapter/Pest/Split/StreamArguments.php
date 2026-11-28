<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function array_filter;
use function array_values;
use function is_string;
use function sprintf;
use function str_starts_with;

/**
 * The arguments a stream's process is started with beside its tests
 * (ADR-0117).
 *
 * @internal
 */
final readonly class StreamArguments
{
    /**
     * A mutant's own run's arguments without what Pest's own process alone
     * takes: its coverage, its mutation and its parallel options.
     *
     * @param  list<string> $arguments
     * @return list<string>
     */
    public static function ofRun(array $arguments): array
    {
        return array_values(array_filter(
            $arguments,
            static fn(string $argument): bool => ! str_starts_with($argument, '--coverage')
                && ! str_starts_with($argument, '--mutate')
                && ! str_starts_with($argument, '--parallel'),
        ));
    }

    /**
     * What loads this extension into a stream (ADR-0130); nothing where the
     * gate names none.
     *
     * @return list<string>
     */
    public static function loading(string|false $extension): array
    {
        return is_string($extension) && $extension !== '' ? ['-d', sprintf('extension=%s', $extension)] : [];
    }
}

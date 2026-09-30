<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use Closure;

use function getenv;
use function putenv;
use function sprintf;

/** Environment variables a test sets for as long as it runs something, put back as they were after. */
final class Environment
{
    /**
     * @template T of object
     *
     * @param  array<string, string|null> $variables each value, or null to unset it
     * @param  Closure(): T               $run
     * @return T
     */
    public static function during(array $variables, Closure $run): object
    {
        $saved = [];

        foreach ($variables as $name => $value) {
            $saved[$name] = getenv($name);
            putenv($value === null ? $name : sprintf('%s=%s', $name, $value));
        }

        try {
            return $run();
        } finally {
            foreach ($saved as $name => $value) {
                putenv($value === false ? $name : sprintf('%s=%s', $name, $value));
            }
        }
    }
}

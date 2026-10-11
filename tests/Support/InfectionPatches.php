<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function file_get_contents;
use function sprintf;

/** What the tests of the Infection patch share: the copy of Infection's vendor it patches. */
final readonly class InfectionPatches
{
    /** The copy's include-interceptor, as it is now. */
    public static function interceptor(string $vendor): string
    {
        return (string) file_get_contents(sprintf('%s/infection/include-interceptor/src/IncludeInterceptor.php', $vendor));
    }
}

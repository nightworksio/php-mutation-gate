<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Recording;

use function error_get_last;

/** The last error PHP raised in this process, as `error_get_last()` gives it. */
final readonly class LastError
{
    /** The file it stands in; empty where PHP has raised none. */
    public static function file(): string
    {
        $error = error_get_last();

        return $error === null ? '' : $error['file'];
    }
}

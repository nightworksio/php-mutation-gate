<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Native\Warm;

use function clearstatcache;
use function filesize;
use function is_file;

/** The size of a file as it stands now, past PHP's stat cache. */
final readonly class FileSize
{
    /** The file's size in bytes; none where there is no file. */
    public static function now(string $file): int
    {
        clearstatcache();
        $size = is_file($file) ? filesize($file) : false;

        return $size === false ? 0 : $size;
    }
}

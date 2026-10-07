<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function chmod;
use function is_readable;
use function sprintf;

/** Whether this process is held to a file's mode, as any user but root is. */
final readonly class FileModes
{
    /** Whether a file with no permission at all is unreadable to this process. */
    public static function areEnforced(): bool
    {
        $directory = Scratch::directory();
        Scratch::write($directory, 'unreadable', 'unreadable');
        $file = sprintf('%s/unreadable', $directory);
        chmod($file, 0o000);
        $enforced = ! is_readable($file);
        chmod($file, 0o600);

        return $enforced;
    }
}

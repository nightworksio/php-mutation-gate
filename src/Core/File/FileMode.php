<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\File;

/**
 * The bits of a file's mode, as `stat` gives it, that the gate reads or
 * makes a file with.
 *
 * @internal
 */
final readonly class FileMode
{
    /** The bits that say what the file is. */
    public const int TYPE = 0o170000;

    /** What those bits are for a socket. */
    public const int SOCKET = 0o140000;

    /** A directory its owner alone may read, write and enter. */
    public const int OWNER_ONLY = 0o700;

    /** The bits that let a file's group or others read, write or enter it. */
    public const int OTHERS = 0o077;
}

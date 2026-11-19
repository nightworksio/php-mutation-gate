<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\File;

/**
 * A process's file descriptors, as the system numbers and lists them.
 *
 * @internal
 */
final readonly class FileDescriptor
{
    /** Where the system lists the descriptors a process holds, by their numbers. */
    public const string LISTED = '/dev/fd';

    /** The first descriptor past standard input, output and error. */
    public const int FIRST = 3;
}

<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Process;

use function array_diff_key;
use function array_intersect_key;
use function array_key_exists;
use function fclose;
use function is_array;

use NightWorksIO\MutationGate\Core\File\FileDescriptor;
use NightWorksIO\MutationGate\Core\WholeNumber;

use function scandir;

/**
 * What a process the gate starts holds in the place of each descriptor the
 * gate's own process holds past the standard three: the read end of an empty
 * pipe, so no file the gate's process holds open for writing, nor one its
 * own parent handed it, is shared with the project's runner, whose split
 * streams count each writable file they hold (ADR-0117).
 *
 * @internal
 */
final readonly class Unshared
{
    /** The read end of a pipe whose write end is closed once the process starts. */
    private const array EMPTY = ['pipe', 'r'];

    /**
     * These descriptors, and an empty pipe for each other one this process holds.
     *
     * @param array<int, array{string, string}> $descriptors
     *
     * @return array<int, array{string, string}>
     */
    public static function over(array $descriptors): array
    {
        $held = scandir(FileDescriptor::LISTED);

        foreach (is_array($held) ? $held : [] as $descriptor) {
            $number = WholeNumber::isDigits($descriptor) ? (int) $descriptor : 0;

            if ($number >= FileDescriptor::FIRST && ! array_key_exists($number, $descriptors)) {
                $descriptors[$number] = self::EMPTY;
            }
        }

        return $descriptors;
    }

    /**
     * The pipes of these descriptors, each other one closed.
     *
     * @param array<int, resource>              $pipes
     * @param array<int, array{string, string}> $descriptors
     *
     * @return array<int, resource>
     */
    public static function kept(array $pipes, array $descriptors): array
    {
        foreach (array_diff_key($pipes, $descriptors) as $standIn) {
            fclose($standIn);
        }

        return array_intersect_key($pipes, $descriptors);
    }
}

<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function get_resources;
use function is_array;
use function is_dir;
use function is_link;
use function is_string;

use NightWorksIO\MutationGate\Core\Runner\SocketStreams;

use function readlink;
use function scandir;
use function sprintf;
use function str_starts_with;

/**
 * How many sockets this process holds open (ADR-0117): on Linux, its file
 * descriptors that are sockets, which counts a database client's own as
 * well; elsewhere, PHP's streams whose type says they are sockets.
 *
 * @internal
 */
final readonly class Sockets
{
    /** Where Linux lists a process's file descriptors. */
    private const string DESCRIPTORS = '/proc/self/fd';

    /** How Linux names a file descriptor that is a socket. */
    private const string SOCKET = 'socket:';

    public static function open(): int
    {
        return is_dir(self::DESCRIPTORS)
            ? self::descriptors()
            : SocketStreams::among(get_resources(SocketStreams::STREAM));
    }

    private static function descriptors(): int
    {
        $sockets = 0;
        $descriptors = scandir(self::DESCRIPTORS);

        foreach (is_array($descriptors) ? $descriptors : [] as $descriptor) {
            $path = sprintf('%s/%s', self::DESCRIPTORS, $descriptor);
            $target = is_link($path) ? readlink($path) : false;
            $sockets += is_string($target) && str_starts_with($target, self::SOCKET) ? 1 : 0;
        }

        return $sockets;
    }
}

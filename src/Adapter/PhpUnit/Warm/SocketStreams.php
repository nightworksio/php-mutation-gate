<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpUnit\Warm;

use function str_contains;
use function stream_get_meta_data;

/** The sockets among some of PHP's stream resources, told by the word their stream type holds. */
final readonly class SocketStreams
{
    /** The word in a stream's type that says it is a socket. */
    private const string SOCKET = 'socket';

    /**
     * How many of these streams are sockets.
     *
     * @param array<array-key, resource> $streams
     */
    public static function among(array $streams): int
    {
        $sockets = 0;

        foreach ($streams as $stream) {
            $sockets += str_contains(stream_get_meta_data($stream)['stream_type'], self::SOCKET) ? 1 : 0;
        }

        return $sockets;
    }
}

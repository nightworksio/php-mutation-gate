<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Serve;

use function array_keys;

use const E_WARNING;

use function fclose;
use function intdiv;
use function is_dir;
use function is_int;
use function is_resource;
use function mkdir;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\FileMode;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function restore_error_handler;
use function set_error_handler;
use function sprintf;
use function stream_select;
use function stream_socket_accept;
use function stream_socket_server;
use function umask;

/**
 * The daemon's end of the project's Unix socket (ADR-0078), readable and
 * writable by its owner alone, and the wait for a client or for word from
 * the clients of running children. PHP raises a socket it cannot bind, a
 * directory it cannot make, and a wait a signal cut short, as warnings as
 * well as answering false; they are kept from the output, and the answer is
 * read.
 *
 * @internal
 */
final readonly class Listener
{
    /** The key of the listener among what a wait reads; no child's process id is 0. */
    public const int KEY = 0;
    private const string UNBOUND = 'The daemon could not listen on %s: %s';

    /** @param resource $stream */
    private function __construct(private mixed $stream)
    {
    }

    /** Whether a directory, that of the socket, is there, made its owner's alone where it was not. */
    public static function directoryMade(string $directory): bool
    {
        set_error_handler(static fn(): bool => true, E_WARNING);

        try {
            return is_dir($directory) || mkdir($directory, FileMode::OWNER_ONLY, recursive: true);
        } finally {
            restore_error_handler();
        }
    }

    /** The socket at this path bound and listening; or why it is not. */
    public static function boundTo(string $path): self|CannotJudge
    {
        $mask = umask(FileMode::OTHERS);
        $code = 0;
        $message = '';
        set_error_handler(static fn(): bool => true, E_WARNING);

        try {
            $stream = stream_socket_server(sprintf(Connection::SCHEME, $path), $code, $message);
        } finally {
            restore_error_handler();
            umask($mask);
        }

        return is_resource($stream) ? new self($stream) : CannotJudge::because(sprintf(self::UNBOUND, $path, $message));
    }

    /**
     * Which of the listener, by its key, and the connections, by theirs,
     * have something to read once this many microseconds have passed at most.
     *
     * @param  array<int, Connection> $connections
     * @return list<int>
     */
    public static function ready(self|NotGiven $listener, array $connections, int $microseconds): array
    {
        $read = $listener instanceof self ? [self::KEY => $listener->stream] : [];

        foreach ($connections as $key => $connection) {
            $read[$key] = $connection->stream();
        }

        $write = null;
        $except = null;
        set_error_handler(static fn(): bool => true, E_WARNING);

        try {
            $ready = $read === [] ? false : stream_select(
                $read,
                $write,
                $except,
                intdiv($microseconds, Seconds::MICROSECONDS),
                $microseconds % Seconds::MICROSECONDS,
            );
        } finally {
            restore_error_handler();
        }

        return is_int($ready) ? array_keys($read) : [];
    }

    /**
     * The next client's connection, where one waits; none otherwise.
     *
     * @return list<Connection>
     */
    public function accepted(): array
    {
        set_error_handler(static fn(): bool => true, E_WARNING);

        try {
            $stream = stream_socket_accept($this->stream, 0);
        } finally {
            restore_error_handler();
        }

        return is_resource($stream) ? [Connection::over($stream)] : [];
    }

    public function closed(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
    }
}

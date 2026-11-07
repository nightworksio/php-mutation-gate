<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Serve;

use function array_map;
use function count;

use const E_WARNING;

use function fclose;
use function file_exists;
use function getmypid;
use function intdiv;
use function is_dir;
use function is_int;
use function is_resource;
use function mkdir;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\FileMode;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function rename;
use function restore_error_handler;
use function set_error_handler;
use function sprintf;
use function stream_select;
use function stream_socket_accept;
use function stream_socket_server;
use function umask;
use function unlink;

/**
 * The daemon's end of the project's Unix socket (ADR-0078), readable and
 * writable by its owner alone, and the wait for a client or for word from
 * the clients of running children. The socket is bound under a name of its
 * own and moved into place once it listens, so a client that finds the file
 * finds a daemon listening, never one that refuses it while it starts. PHP raises a socket it cannot bind, a
 * directory it cannot make, and a wait a signal cut short, as warnings as
 * well as answering false; they are kept from the output, and the answer is
 * read.
 *
 * @internal
 */
final readonly class Listener
{
    private const string UNBOUND = 'The daemon could not listen on %s: %s';

    /** Where the socket is bound before it is moved into place: its path and the daemon's process id. */
    private const string ASIDE = '%s.binding-%d';

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
        $aside = sprintf(self::ASIDE, $path, getmypid());
        $mask = umask(FileMode::OTHERS);
        $why = sprintf(self::UNBOUND, $path, '');
        set_error_handler(static function (int $level, string $text) use (&$why): bool {
            $why .= $text;

            return true;
        }, E_WARNING);

        try {
            $stream = stream_socket_server(sprintf(Connection::SCHEME, $aside));
            $placed = is_resource($stream) && rename($aside, $path) ? $stream : false;
        } finally {
            restore_error_handler();
            umask($mask);
        }

        return $placed === false ? self::unplaced($aside, $why) : new self($placed);
    }

    /**
     * Whether a client waits on the listener, and which of the connections,
     * by their keys, have something to read, once this many microseconds
     * have passed at most.
     *
     * @param  array<int, Connection>  $connections
     * @return array{bool, list<int>}
     */
    public static function ready(self|NotGiven $listener, array $connections, int $microseconds): array
    {
        $read = array_map(static fn(Connection $connection): mixed => $connection->stream(), $connections);
        $listening = $listener instanceof self ? $listener->stream : NotGiven::value();

        if ($listener instanceof self) {
            $read[] = $listener->stream;
        }

        if ($read === []) {
            return [false, []];
        }

        $write = null;
        $except = null;
        set_error_handler(static fn(): bool => true, E_WARNING);

        try {
            $ready = stream_select(
                $read,
                $write,
                $except,
                intdiv($microseconds, Seconds::MICROSECONDS),
                $microseconds % Seconds::MICROSECONDS,
            );
        } finally {
            restore_error_handler();
        }

        return is_int($ready) ? self::readied($read, $listening) : [false, []];
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

    /**
     * Whether the listening stream is among those a wait found ready, and
     * the keys of the others.
     *
     * @param  array<int, mixed>  $ready
     * @return array{bool, list<int>}
     */
    private static function readied(array $ready, mixed $listening): array
    {
        $connections = [];

        foreach ($ready as $key => $stream) {
            $connections = $stream === $listening ? $connections : [...$connections, $key];
        }

        return [count($connections) < count($ready), $connections];
    }

    /** Why the socket does not listen at its path, the file of the socket bound aside removed. */
    private static function unplaced(string $aside, string $why): CannotJudge
    {
        if (file_exists($aside)) {
            unlink($aside);
        }

        return CannotJudge::because($why);
    }
}

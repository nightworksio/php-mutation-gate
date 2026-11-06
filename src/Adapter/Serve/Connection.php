<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Serve;

use function array_key_exists;

use const E_NOTICE;
use const E_WARNING;

use function fclose;
use function feof;
use function fgetc;
use function fgets;
use function function_exists;
use function fwrite;
use function is_array;
use function is_int;
use function is_resource;
use function is_string;

use NightWorksIO\MutationGate\Core\NotGiven;

use const PHP_OS_FAMILY;

use function restore_error_handler;
use function set_error_handler;

use Socket;

use function socket_get_option;
use function socket_import_stream;
use function sprintf;
use function stream_set_timeout;
use function stream_socket_client;

/**
 * One connection between a client and a daemon of `mutation-gate serve`
 * (ADR-0078), over the project's Unix socket: lines of JSON each way. PHP
 * raises a socket nobody listens on and a stream whose peer it cannot tell
 * as warnings, and a write to a peer that has gone as a notice, as well as
 * answering false; they are kept from the output, and the answer is read.
 *
 * @internal
 */
final readonly class Connection
{
    /** How PHP names a Unix socket by its path. */
    public const string SCHEME = 'unix://%s';

    /**
     * The level and the option that tell the process at the other end, by
     * the platform: `SOL_SOCKET` and `SO_PEERCRED` on Linux, `SOL_LOCAL` and
     * `LOCAL_PEERPID` on macOS, which PHP's sockets do not name.
     */
    private const array PEER_PROCESS = ['Linux' => [1, 17], 'Darwin' => [0, 2]];

    /** @param resource $stream */
    private function __construct(private mixed $stream)
    {
    }

    /** @param resource $stream */
    public static function over(mixed $stream): self
    {
        return new self($stream);
    }

    /**
     * A connection to the daemon listening on the socket at this path; or
     * none, where none takes it: a Unix socket's connect never waits, as a
     * full backlog refuses it at once.
     */
    public static function to(string $path): self|NotGiven
    {
        set_error_handler(static fn(): bool => true, E_WARNING);

        try {
            $stream = stream_socket_client(sprintf(self::SCHEME, $path));
        } finally {
            restore_error_handler();
        }

        return is_resource($stream) ? new self($stream) : NotGiven::value();
    }

    /** The connection, each read giving up after this many seconds. */
    public function waitingAtMost(int $seconds): self
    {
        stream_set_timeout($this->stream, $seconds);

        return $this;
    }

    /** Whether the text was sent whole. */
    public function sent(string $text): bool
    {
        set_error_handler(static fn(): bool => true, E_WARNING | E_NOTICE);

        try {
            return fwrite($this->stream, $text) !== false;
        } finally {
            restore_error_handler();
        }
    }

    /** The next line, with its newline; or none, where the other end has closed it or gave none in time. */
    public function line(): string|NotGiven
    {
        $line = fgets($this->stream);

        return is_string($line) ? $line : NotGiven::value();
    }

    /** Whether the other end has closed it, once a look found something to read: nothing follows a request. */
    public function hasEnded(): bool
    {
        return fgetc($this->stream) === false && feof($this->stream);
    }

    /** Whether PHP can tell which process is at the other end of a connection on this platform. */
    public static function tellsPeers(): bool
    {
        return is_array(self::peerOption());
    }

    /**
     * The level and the option of a socket that tell the process at its
     * other end on a platform, as PHP names the platform's family; none
     * where it tells none.
     *
     * @return array{int, int}|NotGiven
     */
    public static function peerOptionOn(string $family): array|NotGiven
    {
        return array_key_exists($family, self::PEER_PROCESS) ? self::PEER_PROCESS[$family] : NotGiven::value();
    }

    /**
     * The id of the process at the other end, as the platform tells it:
     * `SO_PEERCRED` on Linux, whose first field PHP answers, and
     * `LOCAL_PEERPID` on macOS; none where it does not tell.
     */
    public function peer(): int|NotGiven
    {
        $option = self::peerOption();

        return is_array($option) ? $this->peerBy($option) : NotGiven::value();
    }

    /** @return resource what PHP waits on for it */
    public function stream(): mixed
    {
        return $this->stream;
    }

    public function closed(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
    }

    /**
     * The id of the process at the other end, read by this level and option.
     *
     * @param array{int, int} $option
     */
    private function peerBy(array $option): int|NotGiven
    {
        set_error_handler(static fn(): bool => true, E_WARNING);

        try {
            $socket = socket_import_stream($this->stream);
            $peer = $socket instanceof Socket ? socket_get_option($socket, $option[0], $option[1]) : NotGiven::value();
        } finally {
            restore_error_handler();
        }

        return is_int($peer) ? $peer : NotGiven::value();
    }

    /**
     * The level and the option of a socket that tell the process at its other
     * end here, where PHP can import a stream as a socket; none otherwise.
     *
     * @return array{int, int}|NotGiven
     */
    private static function peerOption(): array|NotGiven
    {
        return function_exists('socket_import_stream') ? self::peerOptionOn(PHP_OS_FAMILY) : NotGiven::value();
    }
}

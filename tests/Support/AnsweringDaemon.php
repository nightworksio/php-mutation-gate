<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function dirname;
use function file;
use function file_exists;
use function file_put_contents;
use function is_array;
use function is_string;

use NightWorksIO\MutationGate\Adapter\Pest\Split\OwnEnd;
use NightWorksIO\MutationGate\Adapter\Pest\Split\Stream;
use NightWorksIO\MutationGate\Adapter\Serve\Connection;
use NightWorksIO\MutationGate\Adapter\Serve\Listener;
use NightWorksIO\MutationGate\Core\NotGiven;

use function pcntl_fork;
use function pcntl_waitpid;

use RuntimeException;

use const SIGKILL;

use function sprintf;
use function unlink;

/**
 * A daemon on a Unix socket, forked from the test's process, that answers
 * each connection's one request line with the next of its lines, in turn,
 * closes a connection unanswered where its line is empty, and ends once it
 * gave every answer or none was asked for within a while.
 */
final readonly class AnsweringDaemon
{
    /** How many microseconds it waits for each connection before it ends. */
    private const int WAITS = 2_000_000;

    /** The file beside the socket each request line it read is written to. */
    private const string ASKED = '%s.asked';

    private function __construct(private int $process, private string $socket)
    {
    }

    /** One listening on this socket, its directory made the user's alone, answering so. */
    public static function on(string $socket, string ...$answers): self
    {
        return self::after(0, $socket, ...$answers);
    }

    /**
     * One that answers each request this many microseconds after it read it. No fork of a split stream judges the
     * test from here, as a child would share the daemon (ADR-0176).
     */
    public static function after(int $microseconds, string $socket, string ...$answers): self
    {
        Stream::unforkable();
        $listener = Listener::directoryMade(dirname($socket)) ? Listener::boundTo($socket) : null;

        if (! $listener instanceof Listener) {
            throw new RuntimeException(sprintf('No daemon can listen on %s.', $socket));
        }

        $process = pcntl_fork();

        if ($process === 0) {
            self::answered($listener, $microseconds, sprintf(self::ASKED, $socket), ...$answers);
            OwnEnd::by(SIGKILL);
        }

        $listener->closed();

        return new self($process, $socket);
    }

    /** The daemon ended and its socket removed. */
    public function stopped(): void
    {
        pcntl_waitpid($this->process, $status);

        foreach ([$this->socket, sprintf(self::ASKED, $this->socket)] as $left) {
            if (file_exists($left)) {
                unlink($left);
            }
        }
    }

    /**
     * Each request line it read, once it answered every one or stopped waiting.
     *
     * @return list<string>
     */
    public function asked(): array
    {
        pcntl_waitpid($this->process, $status);
        $asked = file(sprintf(self::ASKED, $this->socket), FILE_IGNORE_NEW_LINES);

        return is_array($asked) ? $asked : [];
    }

    /** Each connection the listener takes answered with the next of these lines. */
    private static function answered(Listener $listener, int $microseconds, string $asked, string ...$answers): void
    {
        foreach ($answers as $answer) {
            [$waiting] = Listener::ready($listener, [], self::WAITS);
            $connection = $waiting ? $listener->accepted() : [];

            if ($connection === []) {
                return;
            }

            self::answeredOn($connection[0], $listener, $microseconds, $asked, $answer);
        }
    }

    /**
     * One request read, written to the file of those asked, and answered so
     * once the listener took no other connection for this many microseconds.
     */
    private static function answeredOn(Connection $connection, Listener $listener, int $microseconds, string $asked, string $answer): void
    {
        $read = $connection->line();
        file_put_contents($asked, is_string($read) ? $read : "\n", FILE_APPEND);
        Listener::ready($microseconds > 0 ? $listener : NotGiven::value(), [], $microseconds);
        $connection->sent($answer === '' ? '' : sprintf("%s\n", $answer));
        $connection->closed();
    }
}

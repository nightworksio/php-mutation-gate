<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function array_first;
use function array_map;
use function chdir;
use function chmod;

use Closure;

use function file_put_contents;
use function getcwd;
use function is_dir;
use function is_string;
use function mkdir;

use NightWorksIO\MutationGate\Adapter\Filesystem\Waiting;
use NightWorksIO\MutationGate\Adapter\Serve\Connection;
use NightWorksIO\MutationGate\Adapter\Serve\Listener;
use NightWorksIO\MutationGate\Adapter\Serve\ServeSocket;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function pcntl_exec;
use function pcntl_fork;
use function pcntl_waitpid;
use function posix_geteuid;
use function random_bytes;
use function range;
use function register_shutdown_function;

use RuntimeException;

use function sodium_bin2hex;
use function sprintf;

/**
 * The projects the tests of `mutation-gate serve` run a daemon or a client
 * in: a scratch directory the test changes into, so the socket's name,
 * relative to it, stays short enough for a socket; and the processes that
 * stand in for a daemon.
 */
final class ServedProjects
{
    /** The project's socket, relative to it. */
    public const string SOCKET = '.mutation-gate/serve.sock';

    private static string $left = '';

    /** A scratch project, its gate's directory its owner's alone, changed into: its path. */
    public static function entered(): string
    {
        return self::enteredAt(Scratch::directory());
    }

    /** A project, its gate's directory made its owner's alone, changed into: its path. */
    public static function enteredAt(string $project): string
    {
        $directory = sprintf('%s/.mutation-gate', $project);

        if (! is_dir($directory)) {
            mkdir($directory, 0o700);
        }

        chmod($directory, 0o700);
        self::$left = self::$left === '' ? (string) getcwd() : self::$left;
        chdir($project);

        return $project;
    }

    /** The directory the tests ran in, changed back into. */
    public static function left(): void
    {
        if (self::$left !== '') {
            chdir(self::$left);
            self::$left = '';
        }
    }

    /** The project's socket as this user's own. */
    public static function socket(): ServeSocket
    {
        return ServeSocket::at(self::SOCKET, posix_geteuid());
    }

    /** Whether the condition holds within five seconds, looked at every 20 ms. */
    public static function until(Closure $holds): bool
    {
        $wait = Waiting::every(Seconds::of(0.02));

        foreach (range(1, 250) as $ignored) {
            if ($holds() === true) {
                return true;
            }

            $wait();
        }

        return $holds() === true;
    }

    /**
     * This run in a process of its own, which leaves nothing of the suite
     * behind it once it is done: its process id.
     *
     * @param Closure(): void $run
     */
    public static function aside(Closure $run): int
    {
        $child = pcntl_fork();

        if ($child === 0) {
            $run();
            pcntl_exec('/usr/bin/true');
        }

        return $child;
    }

    /** Waits for a process started aside. */
    public static function waitedFor(int $child): void
    {
        $status = 0;
        pcntl_waitpid($child, $status);
    }

    /**
     * A listener on the project's socket, a client connected to it, and the
     * daemon's end of that connection.
     *
     * @return array{Listener, Connection, Connection}
     */
    public static function connectedPair(): array
    {
        $listener = Listener::boundTo(self::SOCKET);
        $client = Connection::to(self::SOCKET);
        $daemon = $listener instanceof Listener ? array_first($listener->accepted()) : null;

        return $listener instanceof Listener && $client instanceof Connection && $daemon instanceof Connection
            ? [$listener, $client, $daemon]
            : throw new RuntimeException('No connection.');
    }

    /**
     * A daemon stood in for by a process of its own, which takes one request,
     * keeps it in a file, and answers with these lines: the file.
     */
    public static function answering(string ...$lines): string
    {
        self::socket()->removed();
        $listener = Listener::boundTo(self::SOCKET);
        $heard = sprintf('%s/heard-%s', (string) getcwd(), sodium_bin2hex(random_bytes(4)));
        $child = self::aside(static function () use ($listener, $heard, $lines): void {
            $connection = $listener instanceof Listener ? self::acceptedFrom($listener) : NotGiven::value();

            if ($connection instanceof Connection) {
                file_put_contents($heard, self::lineOf($connection->waitingAtMost(5)));
                array_map($connection->sent(...), $lines);
                $connection->closed();
            }
        });

        if ($listener instanceof Listener) {
            $listener->closed();
        }

        register_shutdown_function(static fn() => self::waitedFor($child));

        return $heard;
    }

    /** The connection's next line; nothing where none comes. */
    public static function lineOf(Connection $connection): string
    {
        $line = $connection->line();

        return is_string($line) ? $line : '';
    }

    /** The next client's connection, once one comes within five seconds; none otherwise. */
    public static function acceptedFrom(Listener $listener): Connection|NotGiven
    {
        return ! Listener::ready($listener, [], 5_000_000)[0]
            ? NotGiven::value()
            : array_first($listener->accepted()) ?? NotGiven::value();
    }
}

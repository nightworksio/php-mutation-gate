<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function array_any;
use function array_filter;
use function array_values;
use function file_get_contents;
use function file_put_contents;
use function getcwd;
use function is_array;
use function is_file;
use function is_string;
use function json_decode;
use function json_encode;

use NightWorksIO\MutationGate\Adapter\Filesystem\Waiting;
use NightWorksIO\MutationGate\Adapter\Serve\Connection;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Serve\ServeFrame;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function random_bytes;
use function register_shutdown_function;
use function rename;
use function sodium_bin2hex;
use function sprintf;
use function str_ends_with;
use function strlen;
use function substr;
use function touch;

/** The clients the tests of a daemon of `mutation-gate serve` send their requests from, in a process of their own. */
final readonly class ServedClients
{
    /** What ends a client's line that is sent and the connection closed at once, unread. */
    public const string HANG_UP = '<hang-up>';

    /** What ends a client's line that is sent and the connection held open, unread. */
    public const string HOLD = '<hold>';

    /**
     * Clients in a process of their own, started before this returns, each
     * sending its line in turn once the daemon listens and keeping its
     * answer's lines; a line ending in
     * HANG_UP is sent and its connection closed at once, unread, and one
     * ending in HOLD is sent and its connection held open, unread, until two
     * seconds after the answers are kept: the file they keep the answers in,
     * which is there only once they are all written.
     *
     * @param list<string> $lines
     */
    public static function clients(array $lines): string
    {
        $kept = sprintf('%s/answers-%s.json', (string) getcwd(), sodium_bin2hex(random_bytes(4)));
        $ready = sprintf('%s.ready', $kept);
        $child = ServedProjects::aside(static function () use ($lines, $kept, $ready): void {
            touch($ready);
            ServedProjects::until(static fn(): bool => ServedProjects::socket()->isThere());
            $held = [];
            $answers = [];

            foreach ($lines as $line) {
                [$answers[], $held[]] = self::asked($line);
            }

            file_put_contents(sprintf('%s.writing', $kept), (string) json_encode($answers));
            rename(sprintf('%s.writing', $kept), $kept);
            Waiting::between(Seconds::of(array_any($lines, static fn(string $line): bool => str_ends_with($line, self::HOLD)) ? 2.0 : 0.0));
        });
        register_shutdown_function(static fn() => ServedProjects::waitedFor($child));
        ServedProjects::until(static fn(): bool => is_file($ready));

        return $kept;
    }

    /**
     * The answers the clients kept, each a list of its frames as stream,
     * text and code, once they are all kept.
     *
     * @return list<list<array{string, string, int}|null>>
     */
    public static function answersIn(string $kept): array
    {
        ServedProjects::until(static fn(): bool => is_file($kept));
        $read = json_decode((string) file_get_contents($kept), associative: true);
        $answers = [];

        foreach (is_array($read) ? $read : [] as $answer) {
            $answers[] = self::frames(array_values(array_filter(is_array($answer) ? $answer : [], is_string(...))));
        }

        return $answers;
    }

    /**
     * A line sent, and the answer's lines read where it is to be read: the
     * lines, and the connection, held open where the line says to hold it.
     *
     * @return array{list<string>, Connection|NotGiven}
     */
    private static function asked(string $line): array
    {
        $connection = ServedProjects::socket()->connected();
        $then = match (true) {
            str_ends_with($line, self::HANG_UP) => self::HANG_UP,
            str_ends_with($line, self::HOLD) => self::HOLD,
            default => '',
        };
        $answer = [];

        if (! $connection instanceof Connection) {
            return [$answer, NotGiven::value()];
        }

        $connection->sent(substr($line, 0, strlen($line) - strlen($then)));

        while ($then === '' && is_string($read = $connection->waitingAtMost(5)->line())) {
            $answer[] = $read;
        }

        if ($then !== self::HOLD) {
            Waiting::between(Seconds::of(0.1));
            $connection->closed();
        }

        return [$answer, $connection];
    }

    /**
     * @param  list<string>                          $lines
     * @return list<array{string, string, int}|null>
     */
    private static function frames(array $lines): array
    {
        $frames = [];

        foreach ($lines as $line) {
            $frame = ServeFrame::readFrom($line);
            $frames[] = $frame instanceof ServeFrame ? [$frame->stream()->value, $frame->text(), $frame->code()] : null;
        }

        return $frames;
    }
}

<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Zstd;

use function fwrite;
use function is_int;
use function is_resource;
use function is_string;
use function max;
use function min;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Format\Bytes;
use NightWorksIO\MutationGate\Core\Format\Gzip;
use NightWorksIO\MutationGate\Core\Format\TooLarge;
use NightWorksIO\MutationGate\Core\Format\Zstandard;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Proof\LedgerCodec;

use function proc_close;
use function proc_open;
use function proc_terminate;
use function restore_error_handler;
use function rewind;
use function set_error_handler;
use function sprintf;
use function stream_get_contents;
use function tmpfile;
use function trim;

/**
 * Zstandard as the zstd program offers it, run once for each stream: the
 * stream is handed it as a file, and what it writes is read no further than
 * a limit, past which it is stopped.
 *
 * @internal
 */
final readonly class ZstdProgram implements Zstandard
{
    /** The program's name, as the path finds it. */
    public const string PROGRAM = 'zstd';

    /** The stream the program writes for no text, which a program that can unpack unpacks to none. */
    private const string NONE = "\x28\xb5\x2f\xfd\x24\x00\x01\x00\x00\x99\xe9\xd8\x51";

    private const string FAILED = '%s is not a whole zstd stream: %s';

    private const string ENDED = 'The zstd program ended with code %d: %s';

    private const string UNSTARTED = 'The zstd program could not be started.';

    private function __construct(private string $program)
    {
    }

    /** The program of this name, as the path finds it, or at this path. */
    public static function named(string $program): self
    {
        return new self($program);
    }

    public function pack(string $text, int $level): string|CannotJudge
    {
        $packed = $this->ran(
            $text,
            [$this->program, sprintf('-%d', max(1, min($level, LedgerCodec::MOST_LEVEL)))],
            self::PROGRAM,
            NotGiven::value(),
        );

        return $packed instanceof TooLarge ? CannotJudge::because($packed->why()) : $packed;
    }

    public function unpackAtMost(string $bytes, string $named, int $most): string|CannotJudge|TooLarge
    {
        $unpacked = $this->ran($bytes, [$this->program, '-d'], $named, $most);

        return $unpacked instanceof CannotJudge
            ? CannotJudge::because(sprintf(self::FAILED, $named, $unpacked->why()))
            : $unpacked;
    }

    public function isHere(): bool
    {
        return is_string($this->unpackAtMost(self::NONE, self::PROGRAM, Gzip::STEP));
    }

    /**
     * What the program writes, given these bytes, read no further than a step past a limit where there is one; or
     * why it did not end well, as what it wrote to its error stream says.
     *
     * @param list<string> $command
     */
    private function ran(string $input, array $command, string $named, int|NotGiven $most): string|CannotJudge|TooLarge
    {
        $given = tmpfile();
        $started = $given === false ? CannotJudge::because(self::UNSTARTED) : $this->started($command, $given, $input);

        if ($started instanceof CannotJudge) {
            return $started;
        }

        [$process, $output, $errors] = $started;
        $written = (string) stream_get_contents($output, is_int($most) ? $most + Gzip::STEP : null);
        $past = is_int($most) && Bytes::length($written) > $most
            ? TooLarge::because(sprintf(Gzip::PAST, $named, $most))
            : NotGiven::value();

        if ($past instanceof TooLarge) {
            proc_terminate($process);
        }

        $said = (string) stream_get_contents($errors);
        $code = proc_close($process);

        return match (true) {
            $past instanceof TooLarge => $past,
            $code !== 0 => CannotJudge::because(sprintf(self::ENDED, $code, trim($said))),
            default => $written,
        };
    }

    /**
     * The program, started with these bytes in a file for its input, with what it writes to its output and to its
     * error stream; or why it could not be, which proc_open also raises as a warning. The answer says so, so the
     * warning is not raised.
     *
     * @param  list<string>                              $command
     * @param  resource                                  $given
     * @return array{resource, resource, resource}|CannotJudge
     */
    private function started(array $command, mixed $given, string $input): array|CannotJudge
    {
        fwrite($given, $input);
        rewind($given);
        $pipes = [];
        set_error_handler(static fn(): bool => true, E_WARNING);

        try {
            $process = proc_open($command, [0 => $given, 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        } finally {
            restore_error_handler();
        }

        return is_resource($process) ? [$process, $pipes[1], $pipes[2]] : CannotJudge::because(self::UNSTARTED);
    }
}

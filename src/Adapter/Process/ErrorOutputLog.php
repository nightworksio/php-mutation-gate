<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Process;

use function file_put_contents;

use NightWorksIO\MutationGate\Core\Runner\ProcessLog;

use function sprintf;

/** A log the gate says its lines on, each a line of its own: its error output, which CI keeps as the job's log. */
final readonly class ErrorOutputLog implements ProcessLog
{
    /** The gate's own error output. */
    private const string ERRORS = 'php://stderr';

    private function __construct(private string $stream)
    {
    }

    /** Saying on the gate's own error output. */
    public static function onErrorOutput(): self
    {
        return new self(self::ERRORS);
    }

    /** Saying at the end of a file, or on a stream PHP opens by its name. */
    public static function to(string $stream): self
    {
        return new self($stream);
    }

    public function say(string $line): void
    {
        file_put_contents($this->stream, sprintf("%s\n", $line), FILE_APPEND);
    }
}

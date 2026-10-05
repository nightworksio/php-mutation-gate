<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Console;

use Symfony\Component\Console\Output\StreamOutput;

/** One stream of the console whose every line starts no command in a CI runner's log (Inert). */
final class InertStream extends StreamOutput
{
    /** The line this stream has open, which the next write continues. */
    private ?InertLine $line = null;

    /** The same stream, verbosity, decoration and formatter as this one, made inert. */
    public static function over(StreamOutput $stream): self
    {
        return new self($stream->getStream(), $stream->getVerbosity(), $stream->isDecorated(), $stream->getFormatter());
    }

    /**
     * Each message printable before it is formatted, so the only escape sequences written are its own colours.
     *
     * @param string|iterable<mixed> $messages
     */
    public function write(string|iterable $messages, bool $newline = false, int $options = self::OUTPUT_NORMAL): void
    {
        parent::write(Messages::printable($messages), newline: $newline, options: $options);
    }

    protected function doWrite(string $message, bool $newline): void
    {
        $this->line ??= new InertLine();
        parent::doWrite($this->line->written($message, $newline), $newline);
    }
}

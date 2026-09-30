<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Console;

use NightWorksIO\MutationGate\Core\Format\Inert;
use Symfony\Component\Console\Output\StreamOutput;

/** One stream of the console whose every line starts no command in a CI runner's log (Inert). */
final class InertStream extends StreamOutput
{
    /** The same stream, verbosity, decoration and formatter as this one, made inert. */
    public static function over(StreamOutput $stream): self
    {
        return new self($stream->getStream(), $stream->getVerbosity(), $stream->isDecorated(), $stream->getFormatter());
    }

    protected function doWrite(string $message, bool $newline): void
    {
        parent::doWrite(Inert::text($message), $newline);
    }
}

<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Console;

use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;

/**
 * The console, standard output and standard error both, as a CI runner's log
 * reads it: every line the gate writes starts no command there, whatever
 * text from outside it carries and however many writes make it (Inert,
 * InertLine), and that text reaches it with no control character
 * (Printable), so the only escape sequences it writes are the colours it
 * adds. Text from outside is written raw, so the formatter reads no markup
 * in it. The gate writes its own annotations elsewhere, with their encoding.
 */
final class InertOutput extends ConsoleOutput
{
    /** The line this stream has open, which the next write continues. */
    private ?InertLine $line = null;

    public function __construct()
    {
        parent::__construct();
        $this->setErrorOutput(parent::getErrorOutput());
    }

    /**
     * Standard error, made inert as standard output is; one that is no stream cannot be, and is refused, so no
     * error is ever written past Inert.
     */
    public function setErrorOutput(OutputInterface $error): void
    {
        if (! $error instanceof StreamOutput) {
            throw NotInert::handed();
        }

        parent::setErrorOutput(InertStream::over($error));
    }

    /**
     * No section: one writes past this output's own lines, so it could print
     * a line that is not inert. The gate draws no section.
     */
    public function section(): ConsoleSectionOutput
    {
        throw NoSection::drawn();
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

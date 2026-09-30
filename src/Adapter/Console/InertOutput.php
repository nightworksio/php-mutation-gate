<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Console;

use NightWorksIO\MutationGate\Core\Format\Inert;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\StreamOutput;

/**
 * The console, standard output and standard error both, as a CI runner's log
 * reads it: every line the gate writes starts no command there, whatever
 * text from outside it carries (Inert). The gate writes its own annotations
 * elsewhere, with their encoding.
 */
final class InertOutput extends ConsoleOutput
{
    public function __construct()
    {
        parent::__construct();
        $errors = parent::getErrorOutput();

        if ($errors instanceof StreamOutput) {
            $this->setErrorOutput(InertStream::over($errors));
        }
    }

    /**
     * No section: one writes past this output's own lines, so it could print
     * a line that is not inert. The gate draws no section.
     */
    public function section(): ConsoleSectionOutput
    {
        throw NoSection::drawn();
    }

    protected function doWrite(string $message, bool $newline): void
    {
        parent::doWrite(Inert::text($message), $newline);
    }
}

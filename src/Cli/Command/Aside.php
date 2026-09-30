<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Where a command says what is not its output: standard error, where the
 * console has one. Standard output carries only what the command produces,
 * such as the document a CI reads from a pipe.
 */
final readonly class Aside
{
    public static function of(OutputInterface $output): OutputInterface
    {
        return $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
    }
}

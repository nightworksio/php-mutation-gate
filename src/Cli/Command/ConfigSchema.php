<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

use NightWorksIO\MutationGate\Cli\ExitCode;
use NightWorksIO\MutationGate\Core\Config\Definition;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `config:schema`: the JSON Schema of the config (ADR-0002), written from
 * the definition every layer of config is read through. The package ships
 * the same text at `resources/mutation-gate.schema.json`.
 */
final readonly class ConfigSchema
{
    public static function command(): Command
    {
        return new Command('config:schema')
            ->setDescription('Print the JSON Schema of the config')
            ->setCode(static function (OutputInterface $output): int {
                $output->writeln(Definition::schema(), OutputInterface::OUTPUT_RAW);

                return ExitCode::Passed->value;
            });
    }
}

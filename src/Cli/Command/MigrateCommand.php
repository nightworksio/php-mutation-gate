<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

use function file_put_contents;
use function max;

use NightWorksIO\MutationGate\Cli\CommandLine;
use NightWorksIO\MutationGate\Cli\Config\MigrationFiles;
use NightWorksIO\MutationGate\Cli\Config\NoConfigFile;
use NightWorksIO\MutationGate\Cli\ExitCode;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Migration\Migrated;
use NightWorksIO\MutationGate\Core\Migration\Migrations;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Written;

use function sprintf;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `migrate [--config=<path>] [--write]` (ADR-0026, decision 5): the diff of
 * every file it would move forward, the config and the baseline, written
 * only with `--write`. Exit 0 where every file is current or written, 1
 * where a migration is pending without `--write` or a hand edit is left,
 * and 2 where a file cannot be read.
 */
final readonly class MigrateCommand
{
    private const string CURRENT = '%s is current.';

    private const string LEFT = '%s, %s: %s';

    public static function command(MigrationFiles $files, Migrations $config, Migrations $baseline): Command
    {
        return new Command('migrate')
            ->setDescription('Show, or write, the config and the baseline moved to the current release')
            ->addOption(FlowOptions::WRITE, mode: InputOption::VALUE_NONE, description: 'Write every migrated file')
            ->setCode(static function (InputInterface $input, OutputInterface $output) use (
                $files,
                $config,
                $baseline,
            ): int {
                $given = CommandLine::from($input);
                $writing = $input->getOption(FlowOptions::WRITE) === true;
                $codes = [
                    self::said($output, $files, $files->config($given, $config), $writing),
                    self::said($output, $files, $files->baseline($given, $baseline), $writing),
                ];

                return max($codes);
            });
    }

    /** What `migrate` says of one file, and the exit code it leaves; a file it would change written with `--write`. */
    private static function said(
        OutputInterface $output,
        MigrationFiles $files,
        Migrated|NoConfigFile|NotGiven|CannotJudge $migrated,
        bool $writing,
    ): int {
        if (! $migrated instanceof Migrated) {
            return $migrated instanceof CannotJudge ? Failed::because($output, $migrated) : ExitCode::Passed->value;
        }

        $changed = $migrated->changes() ? self::changed($output, $files, $migrated, $writing) : ExitCode::Passed->value;
        $left = self::left($output, $migrated);

        if (! $migrated->changes() && $left === ExitCode::Passed->value) {
            $output->writeln(sprintf(self::CURRENT, $migrated->file()), OutputInterface::OUTPUT_RAW);
        }

        return max($changed, $left);
    }

    private static function changed(
        OutputInterface $output,
        MigrationFiles $files,
        Migrated $migrated,
        bool $writing,
    ): int {
        $output->write($migrated->diff(), options: OutputInterface::OUTPUT_RAW);

        if ($migrated->note() !== '') {
            $output->writeln($migrated->note(), OutputInterface::OUTPUT_RAW);
        }

        if (! $writing) {
            return ExitCode::Failed->value;
        }

        $wrote = file_put_contents($files->onDisk($migrated), $migrated->after());
        $written = Written::attempted($migrated->file(), $wrote);

        if ($written instanceof CannotJudge) {
            return Failed::because($output, $written);
        }

        $output->writeln($written->said(), OutputInterface::OUTPUT_RAW);

        return ExitCode::Passed->value;
    }

    /** Each change left for a hand edit, and the exit code it leaves. */
    private static function left(OutputInterface $output, Migrated $migrated): int
    {
        $left = $migrated->left();

        foreach ($left instanceof Invalid ? $left : [] as $problem) {
            $output->writeln(
                sprintf(self::LEFT, $migrated->file(), $problem->path(), $problem->message()),
                OutputInterface::OUTPUT_RAW,
            );
        }

        return $left instanceof Invalid ? ExitCode::Failed->value : ExitCode::Passed->value;
    }
}

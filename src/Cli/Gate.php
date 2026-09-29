<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli;

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Cli\Discovery\Discovery;
use NightWorksIO\MutationGate\Core\CannotJudge;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The composition root: finds the extensions, then hands the command line to
 * the console. An extension that cannot be loaded stops the run before any
 * command.
 */
final readonly class Gate
{
    public function __construct(private Discovery $discovery)
    {
    }

    /** The gate for a project, with the Composer vendor directory it was installed into. */
    public static function in(string $project, string $vendor): self
    {
        return new self(new Discovery(Directory::at($project), Directory::at($vendor)));
    }

    public function run(InputInterface $input, OutputInterface $output, OutputInterface $errors): int
    {
        $firstPartyOnly = $input->hasParameterOption('--no-extensions', onlyParams: true);
        $extensions = $this->discovery->extensions(firstPartyOnly: $firstPartyOnly);

        if ($extensions instanceof CannotJudge) {
            $errors->writeln($extensions->why(), OutputInterface::OUTPUT_RAW);

            return ExitCode::CannotJudge->value;
        }

        return Console::application()->run($input, $output);
    }
}

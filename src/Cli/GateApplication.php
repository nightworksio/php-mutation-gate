<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli;

use function is_int;
use function is_string;

use NightWorksIO\MutationGate\Cli\Serve\Serving;
use NightWorksIO\MutationGate\Core\NotGiven;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * The console application, which ends every command it could not finish with
 * could-not-judge: a command line it cannot read, a command it does not know,
 * and a failure nobody anticipated never read as a failed gate.
 *
 * @internal
 */
final class GateApplication extends Application
{
    private Serving|NotGiven $serving;

    public function __construct(string $name, string $version)
    {
        parent::__construct($name, $version);
        $this->serving = NotGiven::value();
    }

    /** The application, handing `watch`, pre-commit and pre-push to a daemon that serves the project (ADR-0078). */
    public function servedBy(Serving $serving): void
    {
        $this->serving = $serving;
    }

    public function doRun(InputInterface $input, OutputInterface $output): int
    {
        try {
            return parent::doRun($input, $output);
        } catch (Throwable $failed) {
            throw NotFinished::by($failed);
        }
    }

    /** The command's exit code as a daemon ran it, where one serves it; as this process runs it otherwise. */
    protected function doRunCommand(Command $command, InputInterface $input, OutputInterface $output): int
    {
        $name = $command->getName();
        $forwarded = $this->serving instanceof Serving && is_string($name)
            ? $this->serving->forwarded($name, $input, $output)
            : NotGiven::value();

        return is_int($forwarded) ? $forwarded : parent::doRunCommand($command, $input, $output);
    }

    /** What stopped the command, rendered as Symfony renders it, rather than the exit code it was wrapped for. */
    protected function doRenderThrowable(Throwable $e, OutputInterface $output): void
    {
        parent::doRenderThrowable($e instanceof NotFinished ? $e->cause() : $e, $output);
    }
}

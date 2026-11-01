<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli;

use Symfony\Component\Console\Application;
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
    public function doRun(InputInterface $input, OutputInterface $output): int
    {
        try {
            return parent::doRun($input, $output);
        } catch (Throwable $failed) {
            throw NotFinished::by($failed);
        }
    }

    /** What stopped the command, rendered as Symfony renders it, rather than the exit code it was wrapped for. */
    protected function doRenderThrowable(Throwable $e, OutputInterface $output): void
    {
        parent::doRenderThrowable($e instanceof NotFinished ? $e->cause() : $e, $output);
    }
}

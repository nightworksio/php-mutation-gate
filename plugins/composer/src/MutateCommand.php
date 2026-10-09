<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGateComposer;

use Composer\Command\BaseCommand;
use Composer\Package\PackageInterface;
use Composer\Util\ProcessExecutor;

use function is_string;
use function preg_replace;
use function sprintf;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function trim;

/**
 * `composer mutate [args]`: runs `vendor/bin/mutation-gate [args]`, by the
 * bin directory Composer links commands into, with no time limit, and exits
 * as the gate does; where the project has not installed the gate, it says
 * how to, and exits 2 (ADR-0024, decision 11). Its arguments are handed on as
 * the command line spells them, as Composer hands a script's on.
 */
final class MutateCommand extends BaseCommand
{
    private const string NAME = 'mutate';

    private const string ARGUMENTS = 'args';

    /** The gate's package, which links the gate's command. */
    private const string PACKAGE = 'nightworksio/mutation-gate';

    /** Any version of a package. */
    private const string ANY = '*';

    private const string GATE = 'mutation-gate';

    private const string BIN_DIR = 'bin-dir';

    /** The command's own name, which the command line holds before what it hands on. */
    private const string OWN_NAME = '{^\S+ ?}';

    private const string CALL = '%s %s';

    private const string MISSING = <<<'SAID'
        <error>The gate is not installed. Install it: composer require --dev %s</error>
        SAID;

    /** The gate's exit code where it cannot judge, as this command exits where there is no gate to run. */
    private const int CANNOT_RUN = 2;

    protected function configure(): void
    {
        $this->setName(self::NAME)
            ->setDescription('Run the mutation gate with these arguments, with no time limit')
            ->addArgument(self::ARGUMENTS, InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'What the gate takes')
            ->ignoreValidationErrors();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $composer = $this->requireComposer();
        $bin = $composer->getConfig()->get(self::BIN_DIR);
        $installed = $composer->getRepositoryManager()->getLocalRepository()->findPackage(self::PACKAGE, self::ANY);
        $gate = match (true) {
            ! $installed instanceof PackageInterface, ! is_string($bin) => '',
            default => sprintf('%s/%s', $bin, self::GATE),
        };

        if ($gate === '') {
            $this->getIO()->writeError(sprintf(self::MISSING, self::PACKAGE));

            return self::CANNOT_RUN;
        }

        $handed = (string) preg_replace(self::OWN_NAME, '', (string) $input, 1);
        ProcessExecutor::setTimeout(0);

        return new ProcessExecutor($this->getIO())
            ->executeTty(trim(sprintf(self::CALL, ProcessExecutor::escape($gate), $handed)));
    }
}

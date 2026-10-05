<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Keyed;

use function getenv;
use function is_string;

use NightWorksIO\MutationGate\Adapter\Filesystem\LedgerDirectory;
use NightWorksIO\MutationGate\Cli\ExitCode;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Delivery\StoreLocation;
use NightWorksIO\MutationGate\Core\File\Workspace;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Proof\Unreadable;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Port\ProofStore;

use function sprintf;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `mutation-gate fetch [--to=<dir>]`: reads the default branch's ledger from the proof store its own environment
 * locates, with a key that only needs to read, and writes it into `.mutation-gate/ledger`, or the directory `--to`
 * names, where the directory store reads it, so the jobs that run the project's code hold no credential (ADR-0007
 * decision 5). It runs from the gate's own installation with no config read, as `deliver` does, reads no scope
 * but the default branch's, and writes nothing to the store. A ledger it cannot read costs a run, never a verdict,
 * so it says why and passes, as it does where no store is named; a store it cannot locate, a default branch nothing
 * names, or a ledger it cannot write where it was asked to, cannot be judged.
 */
final readonly class Fetch
{
    public const string COMMAND = 'fetch';

    private const string TO = 'to';

    private const string NOT_FETCHED = 'The default branch\'s ledger is not fetched: %s';

    private const string UNNAMED = '%s names no store, so fetch reads no ledger.';

    public function __construct(
        private Installation $installation,
        private Variables $environment,
        private LocatedStore $stores,
    ) {
    }

    /** `fetch` in this process, started from this installation, with the gate's own adapters alone. */
    public static function online(Installation $installation): self
    {
        return new self($installation, Variables::of(getenv()), LocatedStore::online());
    }

    /** Whether the command line asks for `fetch`. */
    public static function isAsked(InputInterface $input): bool
    {
        return $input->getFirstArgument() === self::COMMAND;
    }

    public function run(InputInterface $input, OutputInterface $output, OutputInterface $errors): int
    {
        $refused = $this->installation->refusing(self::COMMAND);
        $to = $refused instanceof CannotJudge
            ? $refused
            : DirectoryOption::in($input, self::COMMAND, self::TO, Workspace::ledger());
        $fetched = is_string($to) ? $this->fetched(LedgerDirectory::at($to)) : $to;

        if ($fetched instanceof CannotJudge) {
            $errors->writeln($fetched->why(), OutputInterface::OUTPUT_RAW);

            return ExitCode::CannotJudge->value;
        }

        $output->writeln(
            $fetched instanceof Written ? $fetched->said() : $fetched->why(),
            OutputInterface::OUTPUT_RAW,
        );

        return ExitCode::Passed->value;
    }

    /**
     * The default branch's ledger, written into this directory; why it is not fetched, where the store cannot be
     * read; or why it cannot be judged.
     */
    private function fetched(LedgerDirectory $into): Written|NotWritten|CannotJudge
    {
        $default = DefaultBranch::scopeIn($this->environment);

        return match (true) {
            $this->environment->valueOf(StoreLocation::Store->value) === ''
                => NotWritten::because(sprintf(self::UNNAMED, StoreLocation::Store->value)),
            $default instanceof Scope => $this->copied($default, $into),
            default => $default,
        };
    }

    /** The scope's ledger, read from the store and written into the directory; or why not. */
    private function copied(Scope $scope, LedgerDirectory $into): Written|NotWritten|CannotJudge
    {
        $store = $this->stores->forDefaultBranch($scope);
        $read = $store instanceof ProofStore ? $store->read($scope) : $store;
        $written = $read instanceof Ledger ? $into->write($scope, $read) : $read;

        return match (true) {
            $written instanceof Written, $written instanceof CannotJudge => $written,
            $read instanceof Ledger => CannotJudge::because($written->why()),
            $written instanceof Unreadable => NotWritten::because($written->why()),
            default => NotWritten::because(sprintf(self::NOT_FETCHED, $written->why())),
        };
    }
}

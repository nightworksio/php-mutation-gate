<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Keyed;

use function getenv;
use function is_file;
use function is_string;

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Adapter\Filesystem\Resources;
use NightWorksIO\MutationGate\Adapter\Filesystem\Shipped;
use NightWorksIO\MutationGate\Adapter\Outbreak\Bundled;
use NightWorksIO\MutationGate\Adapter\Outbreak\CacheDelivery;
use NightWorksIO\MutationGate\Adapter\Outbreak\Outbreak;
use NightWorksIO\MutationGate\Cli\Command\FlowOptions;
use NightWorksIO\MutationGate\Cli\ExitCode;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Delivery\Delivery;
use NightWorksIO\MutationGate\Core\Delivery\DeliveryFile;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Workspace;
use NightWorksIO\MutationGate\Core\Format\TooLarge;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Proof\CacheRequest;
use NightWorksIO\MutationGate\Core\Proof\Companion;
use NightWorksIO\MutationGate\Core\Proof\CompanionRead;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\LedgerCodec;
use NightWorksIO\MutationGate\Core\Proof\LedgerFile;
use NightWorksIO\MutationGate\Core\Proof\LedgerLimits;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Written;

use function sprintf;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `mutation-gate deliver [--from=<dir>]`: sends what a run that ran the
 * project's code left in `.mutation-gate/delivery`, with credentials that run
 * never held (ADR-0007). It runs from the gate's own installation,
 * before any extension is found and with no config read, so it loads none of
 * the project's code. It decides which scope it may write from its own run
 * before it reads the delivery, which it reads as JSON within the ledger's
 * byte limits.
 *
 * @internal
 */
final readonly class Deliver
{
    public const string COMMAND = 'deliver';

    private const string NOTHING = 'There is no delivery at %s, so deliver sends nothing.';

    /** @param array<string, string> $environment */
    public function __construct(
        private Installation $installation,
        private array $environment,
        private Sending $sending,
        private string $shipped,
    ) {
    }

    /** `deliver` in this process, started from this installation, with the gate's own adapters alone. */
    public static function online(Installation $installation): self
    {
        return new self($installation, getenv(), Sending::online(), Resources::at(Shipped::Outbreak));
    }

    /** Whether the command line asks for `deliver`. */
    public static function isAsked(InputInterface $input): bool
    {
        return $input->getFirstArgument() === self::COMMAND;
    }

    public function run(InputInterface $input, OutputInterface $output, OutputInterface $errors): int
    {
        $refused = $this->installation->refusing(self::COMMAND);
        $from = $refused instanceof CannotJudge
            ? $refused
            : DirectoryOption::in($input, self::COMMAND, FlowOptions::FROM, Workspace::delivery());
        $sent = is_string($from) ? $this->sentFrom(Directory::at($from)) : $from;

        if ($sent instanceof CannotJudge) {
            $errors->writeln($sent->why(), OutputInterface::OUTPUT_RAW);

            return ExitCode::CannotJudge->value;
        }

        foreach ($sent->said() as $line) {
            $output->writeln($line, OutputInterface::OUTPUT_RAW);
        }

        $pushed = $this->cachePushed($from);

        if (! $pushed instanceof NotGiven) {
            $output->writeln(
                $pushed instanceof Written ? $pushed->said() : $pushed->why(),
                OutputInterface::OUTPUT_RAW,
            );
        }

        return $sent->exit()->value;
    }

    /** What the delivery in this directory came to, the scope this run may write decided before it is read. */
    private function sentFrom(Directory $from): Sent|CannotJudge
    {
        $trusted = TrustedRun::scopeIn(Variables::of($this->environment));
        $delivery = $this->deliveryIn($from);

        return $delivery instanceof CannotJudge ? $delivery : $this->sending->sent(
            $delivery,
            $trusted,
            static fn(): Ledger|CannotJudge|TooLarge => self::ledgerIn($from),
            static fn(Companion $companion): Contents|CannotJudge|TooLarge => self::keptIn($from, $companion),
        );
    }

    private function deliveryIn(Directory $from): Delivery|CannotJudge
    {
        $read = $from->readAtMost(Path::of(DeliveryFile::NAME), LedgerLimits::standard()->packed());

        return match (true) {
            $read instanceof Contents => DeliveryFile::decode($read->text()),
            $read instanceof CannotJudge => $read,
            $read instanceof TooLarge => CannotJudge::because($read->why()),
            default => CannotJudge::because(sprintf(self::NOTHING, $from->root()->value())),
        };
    }

    /** An object to keep beside the ledger, read from beside the delivery within the limits a store reads it to. */
    private static function keptIn(Directory $from, Companion $companion): Contents|CannotJudge|TooLarge
    {
        $read = $from->readAtMost(
            Path::of($companion->value),
            CompanionRead::limitsOf($companion, LedgerLimits::standard())->packed(),
        );

        return $read instanceof Missing ? CannotJudge::because(sprintf(Sending::MISSING, $companion->value)) : $read;
    }

    private static function ledgerIn(Directory $from): Ledger|CannotJudge|TooLarge
    {
        $limits = LedgerLimits::standard();
        $read = $from->readAtMost(Path::of(LedgerFile::NAME), $limits->packed());

        return match (true) {
            $read instanceof Contents => LedgerFile::read($read->text(), $limits, LedgerCodec::gzipOnly()),
            $read instanceof CannotJudge, $read instanceof TooLarge => $read,
            default => CannotJudge::because(sprintf(Sending::MISSING, LedgerFile::NAME)),
        };
    }

    /**
     * The entries of the proof cache the delivery holds pushed into their
     * pack by the binary the gate ships (ADR-0131), with the token the job
     * hands it; or why they were not; nothing where the delivery holds none.
     */
    private function cachePushed(string $from): Written|NotWritten|NotGiven
    {
        $binary = Bundled::in($this->shipped);
        $trusted = TrustedRun::scopeIn(Variables::of($this->environment)) instanceof Scope;

        return match (true) {
            ! is_file(sprintf('%s/%s', $from, CacheRequest::DELIVERED)) => NotGiven::value(),
            is_string($binary) => CacheDelivery::pushed(
                Outbreak::at($binary, $from),
                $from,
                $trusted,
                Variables::of($this->environment),
            ),
            default => NotWritten::because(
                'No Outbreak binary ships for this machine, so deliver pushes no proof pack.',
            ),
        };
    }
}

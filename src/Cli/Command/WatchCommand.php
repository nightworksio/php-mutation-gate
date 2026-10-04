<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

use Closure;

use function count;

use NightWorksIO\MutationGate\Cli\CommandLine;
use NightWorksIO\MutationGate\Cli\ExitCode;
use NightWorksIO\MutationGate\Cli\Flow\Composed;
use NightWorksIO\MutationGate\Cli\Flow\Composition;
use NightWorksIO\MutationGate\Cli\Flow\Interruption;
use NightWorksIO\MutationGate\Cli\Flow\Inventory;
use NightWorksIO\MutationGate\Cli\Flow\KeptCoverage;
use NightWorksIO\MutationGate\Cli\Flow\Mode;
use NightWorksIO\MutationGate\Cli\Flow\Planning;
use NightWorksIO\MutationGate\Cli\Flow\Running;
use NightWorksIO\MutationGate\Cli\Flow\Workspace;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Runner\CoverageRead;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Verdict\HeldTo;
use NightWorksIO\MutationGate\Core\Watch\Watched;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `mutation-gate watch` judges what each save reaches (ADR-0010, decision
 * 1). It polls the files of the trees and the test directories by their
 * digests, once each wait, and on a change runs a round: change-scoped since
 * `HEAD`, judged on the new-code floor over everything changed since then
 * and showing each tree against its floor without holding it, under
 * `local.watchBudget`, with proofs in the local ledger leaving out what
 * is judged already. A change that arrives mid-round stops it before its next
 * batch; what it judged is kept, and the next round starts at once. The
 * coverage map is built in the first round and kept: a change to tests or
 * test support measures the tests it touched again, and a change that
 * reaches everything builds it again. It never writes the baseline, and it
 * runs until it is stopped.
 */
final readonly class WatchCommand
{
    private const string NAME = 'watch';

    private const string WATCHING = 'Watching the trees and the tests for changes. Ctrl-C stops.';

    private const string ARRIVED = 'A change arrived, so the round stops and the gate judges again.';

    /** @param Closure(): bool $waited waits once between looks, and says whether to go on watching */
    private function __construct(
        private Composed $composed,
        private Printing $printing,
        private OutputInterface $output,
        private Closure $waited,
    ) {
    }

    /** @param Closure(): bool $waited waits once between looks, and says whether to go on watching */
    public static function command(Composition $composition, Closure $waited): Command
    {
        return FlowOptions::editing(new Command(self::NAME))
            ->setDescription('Re-judge what each save reaches')
            ->setCode(static function (InputInterface $input, OutputInterface $output) use (
                $composition,
                $waited,
            ): int {
                $printing = FlowOptions::printing($input);
                $composed = $composition->compose($input);
                $composed = $composed instanceof Composed ? self::budgeted($composed, $input) : $composed;

                return match (true) {
                    $printing instanceof CannotJudge => Failed::because($output, $printing),
                    ! $composed instanceof Composed => Failed::because($output, $composed),
                    default => new self($composed, $printing, $output, $waited)->watched(),
                };
            });
    }

    /** The composition with `local.watchBudget` as each round's budget, unless `--budget` sets one. */
    private static function budgeted(Composed $composed, InputInterface $input): Composed|Invalid
    {
        return CommandLine::from($input)->budget instanceof NotGiven
            ? $composed->budgeted($composed->settings->local()->watchBudget())
            : $composed;
    }

    private function watched(): int
    {
        $inventory = Inventory::of($this->composed->adapters, $this->composed->settings);

        if ($inventory instanceof CannotJudge) {
            return Failed::because($this->output, $inventory);
        }

        $this->printing->note(self::WATCHING, $this->output);
        $watched = Watched::in($inventory->trees, ...$inventory->suite->directories())->at($inventory->files);

        return $this->rounds($watched);
    }

    /**
     * A round from what is on disk, then one after each change, until the
     * wait says to stop. Each round after the first reads the map the last
     * one left, brought up to date with the tests the change touched, and
     * builds it afresh where the last round planned nothing and so left none.
     */
    private function rounds(Watched $seen): int
    {
        $kept = new KeptCoverage($this->composed->adapters, $this->composed->settings);
        $coverage = KeptCoverage::built();

        while (true) {
            $planned = $this->round($seen, $coverage);
            $now = $this->after($seen);

            if (! $now instanceof Watched) {
                return $now instanceof CannotJudge ? Failed::because($this->output, $now) : ExitCode::Passed->value;
            }

            $coverage = $planned ? $kept->after($now->changesSince($seen)) : KeptCoverage::built();
            $seen = $now;
        }
    }

    /** The files once a round is over: as a change during it left them, or as the next change leaves them. */
    private function after(Watched $seen): Watched|CannotJudge|NotGiven
    {
        $now = $this->look($seen);

        return $now instanceof Watched && count($now->changesSince($seen)) === 0 ? $this->nextChange($seen) : $now;
    }

    /**
     * One round from the files as they were seen, stopped before its next
     * batch by a change; its verdict is printed unless a change arrived.
     * Whether it planned, and so left a coverage map for the next round.
     */
    private function round(Watched $seen, CoverageRun|CoverageRead $coverage): bool
    {
        $composed = $this->composed;
        $arrived = Interruption::when(fn(): bool => $this->hasChanged($seen));
        $running = new Running($composed->adapters, $composed->settings, $composed->setup)->interrupted($arrived);
        $this->printing->begin($this->output, $composed->adapters->project);
        $plan = new Planning($composed->adapters, $composed->settings, $composed->setup)
            ->plan(
                Mode::since(Revision::head()->name()),
                $coverage,
                FlowOptions::configuredCut($composed->settings),
                MatrixKind::FirstKiller,
            );
        $results = Workspace::results();
        $ran = $plan instanceof Plan ? $running->runAllBy($plan, $results, $running->deadline()) : $plan;
        $judged = match (true) {
            $ran instanceof CannotJudge => $ran,
            $plan instanceof Plan => VerdictCommand::judgedOf($composed, $plan, $results, HeldTo::NewCode),
        };

        if ($arrived->arrived()) {
            $this->printing->note(self::ARRIVED, $this->output);
        }

        if (! $arrived->arrived()) {
            VerdictCommand::printed($judged, $this->output, $this->printing, $composed->adapters->project);
        }

        return $plan instanceof Plan;
    }

    /** The files as they are once one changes, or nothing where the wait says to stop first. */
    private function nextChange(Watched $seen): Watched|CannotJudge|NotGiven
    {
        while (($this->waited)() === true) {
            $now = $this->look($seen);

            if (! $now instanceof Watched || count($now->changesSince($seen)) > 0) {
                return $now;
            }
        }

        return NotGiven::value();
    }

    private function hasChanged(Watched $seen): bool
    {
        $now = $this->look($seen);

        return $now instanceof Watched && count($now->changesSince($seen)) > 0;
    }

    /** The watched files as they are on disk now. */
    private function look(Watched $seen): Watched|CannotJudge
    {
        $files = $this->composed->adapters->changes->fingerprints();

        return $files instanceof CannotTell ? CannotJudge::because($files->why()) : $seen->at($files);
    }
}

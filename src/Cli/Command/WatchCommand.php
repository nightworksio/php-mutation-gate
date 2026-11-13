<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

use Closure;
use NightWorksIO\MutationGate\Adapter\Outbreak\HeldLedgers;
use NightWorksIO\MutationGate\Cli\ExitCode;
use NightWorksIO\MutationGate\Cli\Flow\BatchEnds;
use NightWorksIO\MutationGate\Cli\Flow\CarriedMap;
use NightWorksIO\MutationGate\Cli\Flow\Composed;
use NightWorksIO\MutationGate\Cli\Flow\Composition;
use NightWorksIO\MutationGate\Cli\Flow\Interruption;
use NightWorksIO\MutationGate\Cli\Flow\Inventory;
use NightWorksIO\MutationGate\Cli\Flow\Judged;
use NightWorksIO\MutationGate\Cli\Flow\KeptCoverage;
use NightWorksIO\MutationGate\Cli\Flow\Mode;
use NightWorksIO\MutationGate\Cli\Flow\PlanMade;
use NightWorksIO\MutationGate\Cli\Flow\Planning;
use NightWorksIO\MutationGate\Cli\Flow\QuickRound;
use NightWorksIO\MutationGate\Cli\Flow\Running;
use NightWorksIO\MutationGate\Cli\RoundOptions;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Local;
use NightWorksIO\MutationGate\Core\Local\LocalSwitch;
use NightWorksIO\MutationGate\Core\Local\Power;
use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Verb;
use NightWorksIO\MutationGate\Core\Verdict\HeldTo;
use NightWorksIO\MutationGate\Core\Watch\Watched;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `mutation-gate watch` judges what each save reaches (ADR-0010). It looks
 * at the files of the trees and the test directories by their digests,
 * hashing only what version control says may have changed (ADR-0120), once
 * `outbreak serve` tells of a change it watched settle (ADR-0133), and once
 * each `local.watchPoll` where it does not (see WatchWaits); on a change it
 * runs a round: change-scoped since `HEAD`, judged on the new-code floor
 * over everything changed since then and showing each tree against its floor
 * without holding it, under `local.watchBudget`, with proofs in the local
 * ledger leaving out what is judged already. A change that arrives mid-round
 * drops the work of the units it moved and halts a batch running one, under
 * `local.cancelByUnit`, or stops the round before its next batch, looked for
 * once the daemon tells of one, or at most once each wait; what it judged is
 * kept, and the next round starts once the round ends. What each batch
 * judged is printed as it ends, one block per unit, or one line of JSON per
 * mutant with `--output=events` (ADR-0120, ADR-0146); the whole report ends
 * a round only with `--report`, as problems, or where batches are not
 * printed.
 * The coverage map is built in the first round and kept: a change to
 * tests or test support measures the tests it touched again, and a change
 * that reaches everything builds it again. Under `local.quick` each round
 * is quick (ADR-0146): it measures again only the test files that changed,
 * covers each changed source file by the tests the kept map and the static
 * graph show running it, streams its mutants by their covering test files
 * with no static check, says it is no verdict, and keys its proofs apart.
 * Where `outbreak serve` keeps proofs, the rounds hold the ledgers between
 * them, the daemon journals the proofs each round gains, and the ledgers are
 * written once the watch ends (ADR-0133). It never writes the baseline, and
 * it runs until it is stopped.
 *
 * @internal
 */
final readonly class WatchCommand
{
    private const string NAME = Verb::Watch->value;

    private const string WATCHING = 'Watching the trees and the tests for changes. Ctrl-C stops.';

    private const string ARRIVED = 'A change arrived, so the round stops and the gate judges again.';

    private const string MOVED = 'A change arrived: the units it moved wait for the next round, which judges again.';

    private const string REPORT = 'report';

    private const string REPORT_SAID = 'Print the whole report after each round';

    /** @param Closure(Seconds): bool $waited waits this long between looks, and says whether to go on watching */
    private function __construct(
        private Composed $composed,
        private Printing $printing,
        private OutputInterface $output,
        private Closure $waited,
        private bool $report,
    ) {
    }

    /** @param Closure(Seconds): bool $waited waits once between looks, and says whether to go on watching */
    public static function command(Composition $composition, Closure $waited): Command
    {
        return RoundOptions::on(FlowOptions::editing(new Command(self::NAME)))
            ->setDescription('Re-judge what each save reaches')
            ->addOption(self::REPORT, mode: InputOption::VALUE_NONE, description: self::REPORT_SAID)
            ->setCode(static function (InputInterface $input, OutputInterface $output) use (
                $composition,
                $waited,
            ): int {
                $printing = FlowOptions::printing($input, events: true);
                $composed = WatchComposition::of($composition, $input);

                return match (true) {
                    $printing instanceof CannotJudge => Failed::because($output, $printing),
                    ! $composed instanceof Composed => Failed::because($output, $composed),
                    default => new self(
                        $composed,
                        $composed->settings->local()->quick() ? $printing->quickly() : $printing,
                        $output,
                        $waited,
                        $input->getOption(self::REPORT) === true,
                    )->watched(),
                };
            });
    }

    private function watched(): int
    {
        $inventory = Inventory::of($this->composed->adapters, $this->composed->settings);

        if ($inventory instanceof CannotJudge) {
            return Failed::because($this->output, $inventory);
        }

        $this->printing->note(self::WATCHING, $this->output);
        $watched = Watched::in($inventory->trees, ...$inventory->suite->directories())->at($inventory->files);
        $waits = WatchWaits::armed(
            $this->waited,
            $this->changes()->notices(),
            $watched->directories(),
            $this->composed->settings->local()->watchPoll(),
            fn(string $said) => $this->printing->note($said, $this->output),
        );

        $ended = $this->rounds($watched, $waits);
        $held = WatchComposition::held($this->composed);
        $released = $held instanceof HeldLedgers ? $held->released() : NotGiven::value();

        if ($released instanceof NotWritten) {
            $this->printing->note($released->why(), $this->output);
        }

        return $ended;
    }

    /**
     * A round from what is on disk, then one after each change, until the
     * wait says to stop or `local.rounds` rounds ran. Each round after the
     * first measures again only the test files whose entries moved against
     * the map the last one left, and measures every test where the last
     * round planned nothing and so left none; the first measures so against
     * the map `local.fromMap` carries, where it names one.
     */
    private function rounds(Watched $seen, WatchWaits $waits): int
    {
        $local = $this->composed->settings->local();
        $carried = new CarriedMap($this->composed->adapters, $this->composed->settings)->first(
            $local->rounds()->fromMap(),
            fn(string $said) => $this->printing->note($said, $this->output),
        );
        $ownMap = $carried instanceof QuickRound;
        $ran = 0;
        $before = $seen;
        $load = WatchLoad::of($local->work(), $this->changes()->notices(), $local->watchPoll());

        while (true) {
            $power = $load->powered(
                fn(): bool => ($this->waited)($local->watchPoll()) === true,
                fn(string $said) => $this->printing->note($said, $this->output),
            );

            if (! $power instanceof Power) {
                return ExitCode::Passed->value;
            }

            $looks = new WatchLooks(
                $this->changes()->look(...),
                $waits->told(...),
                $this->composed->setup->clock,
                $local->watchPoll(),
                $seen,
            );
            $quick = $this->quick($ran, $carried, $ownMap, $seen, $before);
            $ended = $this->round($load->fitted($this->composed, $power, $seen), $ownMap, $quick, $looks);
            ++$ran;

            if ($ran === $local->rounds()->rounds()) {
                return $ended->exitCode($this->output);
            }

            $now = $this->changes()->after($seen, $looks, $waits);

            if (! $now instanceof Watched) {
                return $this->exited($now);
            }

            $ownMap = $ended->planned();
            $before = $seen;
            $seen = $now;
        }
    }

    /**
     * One round from the files as they were seen, cancelled by a change unit
     * by unit where `local.cancelByUnit` is on, stopped before its next batch
     * otherwise; its verdict is printed unless a change arrived.
     * Whether it planned, and so left a coverage map for the next round.
     */
    private function round(Composed $composed, bool $ownMap, QuickRound|NotGiven $quick, WatchLooks $looks): RoundEnd
    {
        $local = $composed->settings->local();
        $arrived = $local->cancelsByUnit()
            ? Interruption::byUnit($looks->moved(...), $composed->adapters->project, $local->watchPoll())
            : Interruption::when($looks->arrived(...));
        $running = Running::composed($composed)->interrupted($arrived)->telling($this->told($local));
        $this->printing->begin($this->output, $composed->adapters->project);
        $made = new Planning($composed->adapters, $composed->settings, $composed->setup)
            ->plan(
                Mode::since(Revision::head()->name()),
                KeptCoverage::built(),
                FlowOptions::configuredCut($composed->settings),
                MatrixKind::FirstKiller,
                ownMap: $ownMap,
                quick: $quick,
            );
        $plan = $made instanceof PlanMade ? $made->plan() : $made;
        $judged = $plan instanceof Plan
            ? VerdictCommand::ranAndJudged($running, $composed, $plan, $running->deadline(), HeldTo::NewCode)
            : $plan;

        $this->ended($looks->arrived(), $local, $judged, $composed);

        return new RoundEnd($plan instanceof Plan, $judged);
    }

    /**
     * What a round says as it ends: that a change arrived, where one did;
     * its verdict otherwise, where the round's report ends it.
     */
    private function ended(bool $stopped, Local $local, Judged|Invalid|CannotJudge $judged, Composed $composed): void
    {
        if ($stopped) {
            $this->printing->note($local->cancelsByUnit() ? self::MOVED : self::ARRIVED, $this->output);

            return;
        }

        if ($this->printing->endsRounds($this->report || ! $local->work()->isOn(LocalSwitch::StreamBatches))) {
            VerdictCommand::printed($judged, $this->output, $this->printing, $composed->adapters->project);
        }
    }

    /** What each batch of a round tells as it ends: one line of JSON per mutant with events, its blocks otherwise. */
    private function told(Local $local): BatchEnds
    {
        return $this->printing->events()
            ? BatchLines::events(
                fn(string $line) => $this->output->writeln($line, OutputInterface::OUTPUT_RAW),
                $local->quick(),
            )
            : BatchLines::streamed(
                $local->work(),
                fn(string $line) => $this->printing->note($line, $this->output),
                $local->quick(),
            );
    }

    /**
     * What a round takes as quick, where rounds are quick: the first, what
     * the carried map gives it; a later one, where the last round left a map,
     * the changes since.
     */
    private function quick(
        int $ran,
        QuickRound|NotGiven $carried,
        bool $ownMap,
        Watched $seen,
        Watched $before,
    ): QuickRound|NotGiven {
        return match (true) {
            ! $this->composed->settings->local()->quick() => NotGiven::value(),
            $ran === 0 => $carried,
            $ownMap => QuickRound::after($seen->changesSince($before)),
            default => NotGiven::value(),
        };
    }

    /** What the watch exits with once no change is left to look for: failed where the files cannot be looked at. */
    private function exited(CannotJudge|NotGiven $why): int
    {
        return $why instanceof CannotJudge ? Failed::because($this->output, $why) : ExitCode::Passed->value;
    }

    /** How the watch finds a change to the files it watches. */
    private function changes(): WatchedChanges
    {
        return new WatchedChanges($this->composed);
    }
}

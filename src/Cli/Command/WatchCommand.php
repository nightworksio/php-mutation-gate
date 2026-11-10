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
use NightWorksIO\MutationGate\Cli\Flow\PlanMade;
use NightWorksIO\MutationGate\Cli\Flow\Planning;
use NightWorksIO\MutationGate\Cli\Flow\QuickRound;
use NightWorksIO\MutationGate\Cli\Flow\Running;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Looked;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\File\Fingerprints;
use NightWorksIO\MutationGate\Core\Local\LocalSwitch;
use NightWorksIO\MutationGate\Core\Local\Power;
use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Verb;
use NightWorksIO\MutationGate\Core\Verdict\HeldTo;
use NightWorksIO\MutationGate\Core\Watch\Watched;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `mutation-gate watch` judges what each save reaches (ADR-0010). It
 * polls the files of the trees and the test directories by their digests,
 * once each wait, hashing only what version control says may have changed
 * (ADR-0120), and on a change runs a round: change-scoped since
 * `HEAD`, judged on the new-code floor over everything changed since then
 * and showing each tree against its floor without holding it, under
 * `local.watchBudget`, with proofs in the local ledger leaving out what
 * is judged already. A change that arrives mid-round stops it before its
 * next batch, looked for at most once each wait; what it judged is kept,
 * and the next round starts at once. What each batch judged is printed as
 * it ends (ADR-0120).
 * The coverage map is built in the first round and kept: a change to
 * tests or test support measures the tests it touched again, and a change
 * that reaches everything builds it again. Under `local.quick` each round
 * is quick (ADR-0146): it measures again only the test files that changed,
 * covers each changed source file by the tests the kept map and the static
 * graph show running it, streams its mutants by their covering test files
 * with no static check, says it is no verdict, and keys its proofs apart.
 * It never writes the baseline, and it runs until it is stopped.
 *
 * @internal
 */
final readonly class WatchCommand
{
    private const string NAME = Verb::Watch->value;

    private const string WATCHING = 'Watching the trees and the tests for changes. Ctrl-C stops.';

    private const string ARRIVED = 'A change arrived, so the round stops and the gate judges again.';

    private const string MOVED = 'A change arrived: the units it moved wait for the next round, which judges again.';

    /** @param Closure(Seconds): bool $waited waits this long between looks, and says whether to go on watching */
    private function __construct(
        private Composed $composed,
        private Printing $printing,
        private OutputInterface $output,
        private Closure $waited,
    ) {
    }

    /** @param Closure(Seconds): bool $waited waits once between looks, and says whether to go on watching */
    public static function command(Composition $composition, Closure $waited): Command
    {
        return FlowOptions::editing(new Command(self::NAME))
            ->setDescription('Re-judge what each save reaches')
            ->setCode(static function (InputInterface $input, OutputInterface $output) use (
                $composition,
                $waited,
            ): int {
                $printing = FlowOptions::printing($input);
                $composed = $composition->composeForWatch($input);
                $composed = $composed instanceof Composed ? self::budgeted($composed, $input) : $composed;

                return match (true) {
                    $printing instanceof CannotJudge => Failed::because($output, $printing),
                    ! $composed instanceof Composed => Failed::because($output, $composed),
                    default => new self(
                        $composed,
                        $composed->settings->local()->quick() ? $printing->quickly() : $printing,
                        $output,
                        $waited,
                    )->watched(),
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
     * wait says to stop. Each round after the first measures again only the
     * test files whose entries moved against the map the last one left, and
     * measures every test where the last round planned nothing and so left
     * none.
     */
    private function rounds(Watched $seen): int
    {
        $ownMap = false;
        $before = $seen;
        $load = WatchLoad::of($this->composed->settings->local()->work());

        while (true) {
            $power = $load->powered(
                fn(): bool => ($this->waited)($this->composed->settings->local()->watchPoll()) === true,
                fn(string $said) => $this->printing->note($said, $this->output),
            );

            if (! $power instanceof Power) {
                return ExitCode::Passed->value;
            }

            $looks = new WatchLooks(
                $this->look(...),
                $this->composed->setup->clock,
                $this->composed->settings->local()->watchPoll(),
                $seen,
            );
            $quick = $ownMap && $this->composed->settings->local()->quick()
                ? QuickRound::after($seen->changesSince($before))
                : NotGiven::value();
            $planned = $this->round($load->fitted($this->composed, $power, $seen), $ownMap, $quick, $looks);
            $now = $this->after($seen, $looks);

            if (! $now instanceof Watched) {
                return $now instanceof CannotJudge ? Failed::because($this->output, $now) : ExitCode::Passed->value;
            }

            $ownMap = $planned;
            $before = $seen;
            $seen = $now;
        }
    }

    /** The files once a round is over: as a change during it left them, or as the next change leaves them. */
    private function after(Watched $seen, WatchLooks $looks): Watched|CannotJudge|NotGiven
    {
        $now = $looks->after();

        return $now instanceof Watched && count($now->changesSince($seen)) === 0 ? $this->nextChange($now) : $now;
    }

    /**
     * One round from the files as they were seen, cancelled by a change unit
     * by unit where `local.cancelByUnit` is on, stopped before its next batch
     * otherwise; its verdict is printed unless a change arrived.
     * Whether it planned, and so left a coverage map for the next round.
     */
    private function round(Composed $composed, bool $ownMap, QuickRound|NotGiven $quick, WatchLooks $looks): bool
    {
        $local = $composed->settings->local();
        $arrived = $local->cancelsByUnit()
            ? Interruption::byUnit($looks->moved(...), $composed->adapters->project, $local->watchPoll())
            : Interruption::when($looks->arrived(...));
        $running = Running::composed($composed)->interrupted($arrived)->telling(BatchLines::streamed(
            $local->work(),
            fn(string $line) => $this->printing->note($line, $this->output),
            $local->quick(),
        ));
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

        $stopped = $looks->arrived();

        if ($stopped) {
            $this->printing->note($local->cancelsByUnit() ? self::MOVED : self::ARRIVED, $this->output);
        }

        if (! $stopped) {
            VerdictCommand::printed($judged, $this->output, $this->printing, $composed->adapters->project);
        }

        return $plan instanceof Plan;
    }

    /** The files as they are once one changes, or nothing where the wait says to stop first. */
    private function nextChange(Watched $seen): Watched|CannotJudge|NotGiven
    {
        while (($this->waited)($this->composed->settings->local()->watchPoll()) === true) {
            $now = $this->look($seen);

            if (! $now instanceof Watched || count($now->changesSince($seen)) > 0) {
                return $now;
            }
        }

        return NotGiven::value();
    }

    /**
     * The watched files as they are on disk now, looked at through version
     * control's status where `local.watchStatus` lets it, every file hashed
     * where it does not.
     */
    private function look(Watched $seen): Watched|CannotJudge
    {
        $changes = $this->composed->adapters->changes;
        $files = $this->composed->settings->local()->work()->isOn(LocalSwitch::WatchStatus)
            ? $changes->lookedSince($seen->looked())
            : $changes->fingerprints();
        $looked = $files instanceof Fingerprints ? Looked::whole($files) : $files;

        return $looked instanceof CannotTell ? CannotJudge::because($looked->why()) : $seen->after($looked);
    }
}

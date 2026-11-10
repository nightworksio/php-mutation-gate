<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

use Closure;
use NightWorksIO\MutationGate\Adapter\Local\OwnPriority;
use NightWorksIO\MutationGate\Adapter\Local\PowerSource;
use NightWorksIO\MutationGate\Cli\Flow\Composed;
use NightWorksIO\MutationGate\Cli\Flow\Cores;
use NightWorksIO\MutationGate\Core\Config\LocalWork;
use NightWorksIO\MutationGate\Core\Local\LocalLoop;
use NightWorksIO\MutationGate\Core\Local\LocalSwitch;
use NightWorksIO\MutationGate\Core\Local\OnBattery;
use NightWorksIO\MutationGate\Core\Local\Power;
use NightWorksIO\MutationGate\Core\Local\Priority;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\ChildVariable;
use NightWorksIO\MutationGate\Core\Runner\ProcessCount;
use NightWorksIO\MutationGate\Core\Watch\Watched;

use function putenv;
use function sprintf;

/**
 * How much of the machine each round of `watch` takes (ADR-0120): the
 * workers `local.workers` or `local.cpu` give, at the priority they give,
 * this process lowered to it so every process a round starts inherits it;
 * one worker at background priority on battery or in Low Power Mode where
 * `local.onBattery` reduces, and no round until the machine runs on power
 * again where it pauses. The power source is read at the start of each
 * round, and at each wait while a round is held.
 *
 * @internal
 */
final readonly class WatchLoad
{
    private const string HELD = 'On battery or saving power, so watch holds its next round until power returns.';

    private function __construct(
        private LocalWork $work,
        private PowerSource $power,
        private OwnPriority $priority,
        private ProcessCount $cores,
    ) {
    }

    /** This machine's, as these settings say, a warm worker and its client told how long they wait for a wake. */
    public static function of(LocalWork $work): self
    {
        putenv(sprintf('%s=%F', ChildVariable::WarmWait->value, $work->childWait()->seconds()));

        return new self($work, PowerSource::here(), OwnPriority::here(), Cores::counted());
    }

    /** One as these say, of a machine with this many cores, reading this source and lowering this process. */
    public static function on(LocalWork $work, PowerSource $power, OwnPriority $priority, ProcessCount $cores): self
    {
        return new self($work, $power, $priority, $cores);
    }

    /**
     * Where the machine draws its power from once a round may run: at once,
     * or, where `local.onBattery` pauses while it saves power, once it runs
     * on power again, read at each wait and said once; nothing where the wait
     * says to stop first.
     *
     * @param Closure(): bool       $waited waits once, and says whether to go on watching
     * @param Closure(string): void $said   says this
     */
    public function powered(Closure $waited, Closure $said): Power|NotGiven
    {
        $power = $this->power();

        if ($this->holds($power)) {
            $said(self::HELD);
        }

        while ($this->holds($power)) {
            if (! $waited()) {
                return NotGiven::value();
            }

            $power = $this->power();
        }

        return $power;
    }

    /**
     * The composition a round of these files runs with, this process lowered
     * to its priority, its runner told the digest of the test files to list
     * them by, where `local.listOnce` reuses a listing, and its proofs keyed
     * apart where `local.quick` makes the round quick (ADR-0146).
     */
    public function fitted(Composed $composed, Power $power, Watched $seen): Composed
    {
        putenv(sprintf(
            '%s=%s',
            ChildVariable::TestsKey->value,
            $this->work->isOn(LocalSwitch::ListOnce) ? $seen->testsDigest() : '',
        ));
        $reduced = $power === Power::Saving && $this->work->onBattery() === OnBattery::Reduce;
        $background = Priority::Background;
        $work = $this->work;
        $reduced
            ? $this->priority->lowered($background->macos(), $background->nice(), $background->windows())
            : $this->priority->lowered($work->macosClass(), $work->linuxNice(), $work->windowsClass());
        $workers = $reduced ? ProcessCount::single() : $this->work->workers($this->cores);
        $loop = LocalLoop::of($this->work);

        return new Composed(
            $composed->settings,
            $composed->adapters->looping($workers, $composed->settings->local()->quick() ? $loop->quickly() : $loop),
            $composed->setup,
            $composed->reporting,
            $composed->ruling,
        );
    }

    /** Where the machine draws its power from now; mains where `local.onBattery` runs as on power. */
    private function power(): Power
    {
        return $this->work->onBattery() === OnBattery::Run ? Power::Mains : $this->power->now();
    }

    /** Whether a round waits for power: the machine saves power and `local.onBattery` pauses. */
    private function holds(Power $power): bool
    {
        return $power === Power::Saving && $this->work->onBattery() === OnBattery::Pause;
    }
}

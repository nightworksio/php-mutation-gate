<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function getenv;
use function is_int;
use function is_numeric;
use function is_string;
use function max;

use NightWorksIO\MutationGate\Adapter\Pest\GateVariable;
use NightWorksIO\MutationGate\Core\Config\SlowdownCarry;
use NightWorksIO\MutationGate\Core\Config\StreamChildren;
use NightWorksIO\MutationGate\Core\Config\StreamJudging;
use NightWorksIO\MutationGate\Core\Config\StreamWork;
use NightWorksIO\MutationGate\Core\Test\TestPart;

use function sprintf;

/**
 * What `splitStream` asks of a patched Pest run (ADR-0117), as the gate
 * tells Pest's own process: whether it splits its streams, the most children
 * a stream holds at once, whether a stream checks a pure expression's mutant
 * changes its value before it forks, whether it forks first at each
 * mutant's likely killer, whether a first-pass stream runs only those
 * killers (ADR-0136), whether a stream of covering tests runs them alone
 * (ADR-0181), and the part of the test files whose tests judge its mutants,
 * every test file unless a run cuts them across its shards.
 *
 * @internal
 */
final readonly class StreamSettings
{
    private const string ON = '1';

    private const string OFF = '0';

    private function __construct(
        private bool $enabled,
        private int|StreamChildren $children,
        private bool $changeCheck,
        private bool $killersFirst,
        private TestPart $part,
        private bool $killerStreams = false,
        private string $recording = '',
        private bool $bootOnce = false,
        private string $lines = '',
        private bool $reconnect = true,
        private StreamJudging $judging = StreamJudging::Whole,
        private bool $fallbackOwnRun = true,
        private bool $coveringOnly = true,
        private StreamDeadline $deadline = new StreamDeadline(on: true),
    ) {
    }

    public static function off(): self
    {
        return new self(enabled: false, children: 1, changeCheck: true, killersFirst: true, part: TestPart::whole());
    }

    /**
     * Splitting so where enabled; otherwise splitting nothing, as {@see off()} does, whatever the rest says: a
     * first-pass stream running only the killers of its mutants (ADR-0136), or booting the application once and
     * forking each test from it, and a test holding a connection of the application's own to a database forking,
     * each child on its own copy of it (ADR-0147), where these say.
     */
    public static function of(
        bool $enabled,
        int|StreamChildren $children,
        bool $changeCheck,
        bool $killersFirst,
        StreamJudging $judging = StreamJudging::Whole,
        bool $fallbackOwnRun = true,
        bool $coveringOnly = true,
        bool $killerStreams = false,
        bool $bootOnce = false,
        bool $reconnect = true,
        StreamDeadline $deadline = new StreamDeadline(on: true),
    ): self {
        if (! $enabled) {
            return self::off();
        }

        return new self(
            $enabled,
            is_int($children) ? max(1, $children) : $children,
            $changeCheck,
            $killersFirst,
            TestPart::whole(),
            killerStreams: $killerStreams,
            bootOnce: $bootOnce,
            reconnect: $reconnect,
            judging: $judging,
            fallbackOwnRun: $fallbackOwnRun,
            coveringOnly: $coveringOnly,
            deadline: $deadline,
        );
    }

    /** What the gate told this process. */
    public static function fromEnvironment(): self
    {
        $children = getenv(GateVariable::SplitChildren->value);
        $recording = getenv(GateVariable::SplitRecording->value);
        $lines = getenv(GateVariable::SplitLines->value);
        $backoff = getenv(GateVariable::SplitDeadlineBackoff->value);
        $carry = getenv(GateVariable::SplitSlowdownCarry->value);

        return new self(
            getenv(GateVariable::Split->value) === self::ON,
            match (true) {
                is_numeric($children) => max(1, (int) $children),
                $children === StreamChildren::Cores->value => StreamChildren::Cores,
                default => 1,
            },
            getenv(GateVariable::SplitChangeCheck->value) !== self::OFF,
            getenv(GateVariable::SplitKillersFirst->value) !== self::OFF,
            TestPartVariable::read(getenv(GateVariable::SplitPart->value)),
            getenv(GateVariable::SplitKillerStreams->value) === self::ON,
            is_string($recording) ? $recording : '',
            getenv(GateVariable::SplitBootOnce->value) === self::ON,
            is_string($lines) ? $lines : '',
            getenv(GateVariable::SplitReconnect->value) === self::ON,
            StreamJudging::tryFrom((string) getenv(GateVariable::SplitJudging->value)) ?? StreamJudging::Whole,
            getenv(GateVariable::SplitFallbackOwnRun->value) !== self::OFF,
            getenv(GateVariable::SplitCoveringTestsOnly->value) !== self::OFF,
            new StreamDeadline(
                getenv(GateVariable::SplitParentDeadline->value) !== self::OFF,
                is_numeric($backoff) ? (float) $backoff : StreamWork::STANDARD_BACKOFF,
                SlowdownCarry::tryFrom(is_string($carry) ? $carry : '') ?? new StreamWork()->slowdownCarry(),
            ),
        );
    }

    /** Where a run that cuts its test files across its shards judges each mutant (ADR-0174). */
    public function judging(): StreamJudging
    {
        return $this->judging;
    }

    /**
     * Whether a mutant whose likely killer the first pass could not fork it
     * in skips the second pass for its own run (ADR-0179).
     */
    public function fallsBackToOwnRuns(): bool
    {
        return $this->fallbackOwnRun;
    }

    /**
     * Whether a stream whose parent runs a test past its deadline is stopped
     * and started again on its other tests (ADR-0182), and how much slower
     * the stream started in its place starts (ADR-0184).
     */
    public function deadline(): StreamDeadline
    {
        return $this->deadline;
    }

    /** Whether a stream of covering tests runs only those tests, by their ids, not their whole files (ADR-0181). */
    public function selectsCoveringTests(): bool
    {
        return $this->coveringOnly;
    }

    /** Whether a stream's child reconnects to its own copy of each database a test holds open (ADR-0147). */
    public function reconnects(): bool
    {
        return $this->reconnect;
    }

    /**
     * Whether a first-pass stream boots the application once and forks each
     * test from it: never where the streams record each test's lines, which
     * would miss what the boot executed.
     */
    public function bootsOnce(): bool
    {
        return $this->bootOnce && $this->recording === '';
    }

    /** Whether a first-pass stream runs only the tests the ledger names as killers of its mutants (ADR-0136). */
    public function streamsKillers(): bool
    {
        return $this->killersFirst && $this->killerStreams;
    }

    /**
     * The directory each stream's parent records the lines its tests
     * executed in, where the run plans without a fresh map (ADR-0135); none
     * where it records nothing.
     */
    public function recording(): string
    {
        return $this->recording;
    }

    /**
     * The directory each stream's parent records the lines its tests
     * executed in, for a quick round's kept map (ADR-0147); none where it
     * records nothing.
     */
    public function lines(): string
    {
        return $this->lines;
    }

    public function isOn(): bool
    {
        return $this->enabled;
    }

    /** The most children a stream holds at once, or the cores shared among the streams still running (ADR-0173). */
    public function children(): int|StreamChildren
    {
        return $this->children;
    }

    public function checksChanges(): bool
    {
        return $this->changeCheck;
    }

    public function killersFirst(): bool
    {
        return $this->killersFirst;
    }

    /** The part of the test files whose tests judge the run's mutants: every test file, unless it was told one. */
    public function part(): TestPart
    {
        return $this->part;
    }

    /**
     * What a patched run is started with to split its streams so; nothing
     * where it does not.
     *
     * @return array<string, string>
     */
    public function variables(): array
    {
        $told = static fn(bool $on): string => $on ? self::ON : self::OFF;

        return $this->enabled
            ? [
                GateVariable::Split->value => self::ON,
                GateVariable::SplitChildren->value => is_int($this->children)
                    ? sprintf('%d', $this->children)
                    : $this->children->value,
                GateVariable::SplitChangeCheck->value => $told($this->changeCheck),
                GateVariable::SplitKillersFirst->value => $told($this->killersFirst),
                GateVariable::SplitKillerStreams->value => $told($this->killerStreams),
                GateVariable::SplitBootOnce->value => $told($this->bootOnce),
                GateVariable::SplitReconnect->value => $told($this->reconnect),
                GateVariable::SplitJudging->value => $this->judging->value,
                GateVariable::SplitFallbackOwnRun->value => $told($this->fallbackOwnRun),
                GateVariable::SplitCoveringTestsOnly->value => $told($this->coveringOnly),
                GateVariable::SplitParentDeadline->value => $told($this->deadline->on),
                GateVariable::SplitDeadlineBackoff->value => (string) $this->deadline->backoff,
                GateVariable::SplitSlowdownCarry->value => $this->deadline->carry->value,
            ]
            : [];
    }
}

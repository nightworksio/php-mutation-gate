<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function getenv;
use function is_numeric;
use function is_string;
use function max;

use NightWorksIO\MutationGate\Adapter\Pest\GateVariable;
use NightWorksIO\MutationGate\Core\Runner\LineRecording;
use NightWorksIO\MutationGate\Core\Test\TestPart;

use function sprintf;

/**
 * What `splitStream` asks of a patched Pest run (ADR-0117), as the gate
 * tells Pest's own process: whether it splits its streams, the most children
 * a stream holds at once, whether a stream checks a pure expression's mutant
 * changes its value before it forks, whether it forks first at each
 * mutant's likely killer, whether a first-pass stream runs only those
 * killers (ADR-0136), and the part of the test files whose tests judge its
 * mutants, every test file unless a run cuts them across its shards.
 *
 * @internal
 */
final readonly class StreamSettings
{
    private const string ON = '1';

    private const string OFF = '0';

    private function __construct(
        private bool $enabled,
        private int $children,
        private bool $changeCheck,
        private bool $killersFirst,
        private TestPart $part,
        private bool $killerStreams = false,
        private string $recording = '',
        private bool $bootOnce = false,
        private string $lines = '',
        private bool $reconnect = false,
    ) {
    }

    public static function off(): self
    {
        return new self(enabled: false, children: 1, changeCheck: true, killersFirst: true, part: TestPart::whole());
    }

    public static function of(bool $enabled, int $children, bool $changeCheck, bool $killersFirst): self
    {
        return new self($enabled, max(1, $children), $changeCheck, $killersFirst, TestPart::whole());
    }

    /** What the gate told this process. */
    public static function fromEnvironment(): self
    {
        $children = getenv(GateVariable::SplitChildren->value);
        $recording = getenv(GateVariable::SplitRecording->value);
        $lines = getenv(GateVariable::SplitLines->value);

        return new self(
            getenv(GateVariable::Split->value) === self::ON,
            is_numeric($children) ? max(1, (int) $children) : 1,
            getenv(GateVariable::SplitChangeCheck->value) !== self::OFF,
            getenv(GateVariable::SplitKillersFirst->value) !== self::OFF,
            TestPartVariable::read(getenv(GateVariable::SplitPart->value)),
            getenv(GateVariable::SplitKillerStreams->value) === self::ON,
            is_string($recording) ? $recording : '',
            getenv(GateVariable::SplitBootOnce->value) === self::ON,
            is_string($lines) ? $lines : '',
            getenv(GateVariable::SplitReconnect->value) === self::ON,
        );
    }

    /** These, a first-pass stream running only the killers of its mutants where this says (ADR-0136). */
    public function streamingKillers(bool $killerStreams): self
    {
        return new self(
            $this->enabled,
            $this->children,
            $this->changeCheck,
            $this->killersFirst,
            $this->part,
            $killerStreams,
            $this->recording,
            $this->bootOnce,
            $this->lines,
            $this->reconnect,
        );
    }

    /** These, a first-pass stream booting the application once and forking each test from it where this says. */
    public function bootingOnce(bool $bootOnce): self
    {
        return new self(
            $this->enabled,
            $this->children,
            $this->changeCheck,
            $this->killersFirst,
            $this->part,
            $this->killerStreams,
            $this->recording,
            $bootOnce,
            $this->lines,
            $this->reconnect,
        );
    }

    /**
     * These, a test holding a connection of the application's own to a
     * database forking, each child on its own copy of it, where this says
     * (ADR-0147).
     */
    public function reconnecting(bool $reconnect): self
    {
        return new self(
            $this->enabled,
            $this->children,
            $this->changeCheck,
            $this->killersFirst,
            $this->part,
            $this->killerStreams,
            $this->recording,
            $this->bootOnce,
            $this->lines,
            $reconnect,
        );
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

    /**
     * What a run whose streams record tells Pest's own process: the directory
     * they record each test's lines in, as a stand-in for the suite's
     * coverage report or for the kept map.
     *
     * @return array<string, string>
     */
    public static function recordingInto(LineRecording $recording): array
    {
        return match (true) {
            $recording->standsIn() => [GateVariable::SplitRecording->value => $recording->directory()],
            $recording->records() => [GateVariable::SplitLines->value => $recording->directory()],
            default => [],
        };
    }

    public function isOn(): bool
    {
        return $this->enabled;
    }

    public function children(): int
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
        return $this->enabled
            ? [
                GateVariable::Split->value => self::ON,
                GateVariable::SplitChildren->value => sprintf('%d', $this->children),
                GateVariable::SplitChangeCheck->value => $this->told($this->changeCheck),
                GateVariable::SplitKillersFirst->value => $this->told($this->killersFirst),
                GateVariable::SplitKillerStreams->value => $this->told($this->killerStreams),
                GateVariable::SplitBootOnce->value => $this->told($this->bootOnce),
                GateVariable::SplitReconnect->value => $this->told($this->reconnect),
            ]
            : [];
    }

    /** A setting turned on or off, as the gate tells it. */
    private function told(bool $on): string
    {
        return $on ? self::ON : self::OFF;
    }
}

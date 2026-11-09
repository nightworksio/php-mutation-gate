<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function getenv;
use function is_array;
use function is_int;
use function is_numeric;
use function is_string;
use function max;

use NightWorksIO\MutationGate\Adapter\Pest\GateVariable;
use NightWorksIO\MutationGate\Core\Test\TestPart;

use function sprintf;
use function sscanf;

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

    /** A part of the test files, as the gate tells it: its number, then how many parts there are. */
    private const string PART = '%d/%d';

    private function __construct(
        private bool $enabled,
        private int $children,
        private bool $changeCheck,
        private bool $killersFirst,
        private TestPart $part,
        private bool $killerStreams = false,
        private string $recording = '',
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

    /**
     * What a run tells Pest's own process of the part of the test files whose
     * tests judge its mutants: nothing of every test file.
     *
     * @return array<string, string>
     */
    public static function parting(TestPart $part): array
    {
        return $part->isWhole()
            ? []
            : [GateVariable::SplitPart->value => sprintf(self::PART, $part->number(), $part->parts())];
    }

    /** What the gate told this process. */
    public static function fromEnvironment(): self
    {
        $children = getenv(GateVariable::SplitChildren->value);

        return new self(
            getenv(GateVariable::Split->value) === self::ON,
            is_numeric($children) ? max(1, (int) $children) : 1,
            getenv(GateVariable::SplitChangeCheck->value) !== self::OFF,
            getenv(GateVariable::SplitKillersFirst->value) !== self::OFF,
            self::partIn(getenv(GateVariable::SplitPart->value)),
            getenv(GateVariable::SplitKillerStreams->value) === self::ON,
            self::recordingIn(getenv(GateVariable::SplitRecording->value)),
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
        );
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
     * What a run that plans without a fresh map tells Pest's own process: the
     * directory its streams record each test's lines in.
     *
     * @return array<string, string>
     */
    public static function recordingInto(string $directory): array
    {
        return $directory === '' ? [] : [GateVariable::SplitRecording->value => $directory];
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
                GateVariable::SplitChangeCheck->value => $this->changeCheck ? self::ON : self::OFF,
                GateVariable::SplitKillersFirst->value => $this->killersFirst ? self::ON : self::OFF,
                GateVariable::SplitKillerStreams->value => $this->killerStreams ? self::ON : self::OFF,
            ]
            : [];
    }

    private static function recordingIn(string|false $told): string
    {
        return is_string($told) ? $told : '';
    }

    /** The part a variable names, as the gate writes it; every test file where it names none there is. */
    private static function partIn(string|false $told): TestPart
    {
        $read = is_string($told) ? sscanf($told, self::PART) : null;
        [$number, $parts] = is_array($read) ? $read + [0, 0] : [0, 0];

        return is_int($number) && is_int($parts) && $number >= 1 && $number <= $parts
            ? TestPart::of($number, $parts)
            : TestPart::whole();
    }
}

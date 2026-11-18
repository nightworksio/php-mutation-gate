<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use NightWorksIO\MutationGate\Core\Test\TestPart;

/**
 * What a run is narrowed to within its shard: the part of the test files
 * whose tests judge its mutants (ADR-0117), where its split streams record
 * the lines each test executes (ADR-0135, ADR-0147), and which of its
 * mutants it makes (ADR-0190).
 */
final readonly class PartNarrowing
{
    private function __construct(private TestPart $part, private LineRecording $recording, private MadeMutants $made)
    {
    }

    /** Every test file, nothing recorded, every mutant made. */
    public static function whole(): self
    {
        return new self(TestPart::whole(), LineRecording::none(), MadeMutants::every());
    }

    /** This narrowing, its mutants judged by the tests of this part of the test files alone (ADR-0117). */
    public function inTestPart(TestPart $part): self
    {
        return new self($part, $this->recording, $this->made);
    }

    /**
     * This narrowing, its split streams recording the lines each test
     * executes as this says, for a run planned without a fresh map
     * (ADR-0135) or a quick round's kept map (ADR-0147).
     */
    public function recordingInto(LineRecording $recording): self
    {
        return new self($this->part, $recording, $this->made);
    }

    /** This narrowing, making these of its mutants (ADR-0190). */
    public function making(MadeMutants $made): self
    {
        return new self($this->part, $this->recording, $made);
    }

    /** The part of the test files whose tests judge its mutants: every test file, unless it was told one. */
    public function testPart(): TestPart
    {
        return $this->part;
    }

    /** Where its split streams record each test's lines, and what for. */
    public function recording(): LineRecording
    {
        return $this->recording;
    }

    public function made(): MadeMutants
    {
        return $this->made;
    }
}

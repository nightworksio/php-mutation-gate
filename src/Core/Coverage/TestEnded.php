<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Coverage;

use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;

/**
 * How a test ended in a stream's parent, as a run planned without a fresh
 * map records it (ADR-0135): passed or not, whether it raised an issue,
 * whether it ran in a process of its own, and in how many seconds.
 *
 * @internal
 */
final readonly class TestEnded
{
    private const string PASSED = 'passed';

    private const string TROUBLED = 'troubled';

    private const string OWN = 'own';

    private const string SECONDS = 'seconds';

    public function __construct(
        public bool $passed,
        public bool $troubled,
        public bool $own,
        public float $seconds,
    ) {
    }

    /**
     * The end a suite's file records.
     *
     * @throws NotInShape
     */
    public static function read(Node $ended): self
    {
        return new self(
            passed: $ended->field(self::PASSED)->boolean(),
            troubled: $ended->field(self::TROUBLED)->boolean(),
            own: $ended->field(self::OWN)->boolean(),
            seconds: $ended->field(self::SECONDS)->number(),
        );
    }

    /** This and another shard's end of the same test, as one run's: troubled or on its own where either was. */
    public function and(self $other): self
    {
        return new self(
            passed: $this->passed && $other->passed,
            troubled: $this->troubled || $other->troubled,
            own: $this->own || $other->own,
            seconds: $this->seconds + $other->seconds,
        );
    }

    /** Whether it passed and raised no issue in its stream's own process, whose lines the parent collects. */
    public function standsIn(): bool
    {
        return $this->passed && ! $this->troubled && ! $this->own;
    }

    /** @return array{passed: bool, troubled: bool, own: bool, seconds: float} as a suite's file records it */
    public function record(): array
    {
        return [
            self::PASSED => $this->passed,
            self::TROUBLED => $this->troubled,
            self::OWN => $this->own,
            self::SECONDS => $this->seconds,
        ];
    }
}

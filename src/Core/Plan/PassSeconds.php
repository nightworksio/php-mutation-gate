<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * The wall seconds of a run's first and second pass of split streams
 * (ADR-0195): as a cut predicted them, or as a run took them, so a run's
 * second pass can be priced by what earlier ones measured.
 *
 * @internal
 */
final readonly class PassSeconds
{
    private const string FIRST = 'first';

    private const string SECOND = 'second';

    private function __construct(private Seconds $first, private Seconds $second)
    {
    }

    public static function of(Seconds $first, Seconds $second): self
    {
        return new self($first, $second);
    }

    /** No pass at all. */
    public static function none(): self
    {
        return new self(Seconds::none(), Seconds::none());
    }

    public function first(): Seconds
    {
        return $this->first;
    }

    public function second(): Seconds
    {
        return $this->second;
    }

    /** @return array{first: float, second: float} */
    public function record(): array
    {
        return [self::FIRST => $this->first->seconds(), self::SECOND => $this->second->seconds()];
    }

    /**
     * The seconds a record holds; none where it holds none.
     *
     * @throws NotInShape
     */
    public static function read(Node $record): self
    {
        return $record->isPresent()
            ? new self(
                Seconds::of($record->field(self::FIRST)->number()),
                Seconds::of($record->field(self::SECOND)->number()),
            )
            : self::none();
    }
}

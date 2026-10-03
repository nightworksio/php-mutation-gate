<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Order;

use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Php\Nameless;

/** A mutant a run judged, and the function it is in, which a kill history learns from. */
final readonly class Lesson
{
    private function __construct(private Mutant $mutant, private Enclosing|Nameless $in)
    {
    }

    public static function of(Mutant $mutant, Enclosing|Nameless $in): self
    {
        return new self($mutant, $in);
    }

    public function mutant(): Mutant
    {
        return $this->mutant;
    }

    /** The named function the mutant is in; nameless code where it is in none. */
    public function in(): Enclosing|Nameless
    {
        return $this->in;
    }
}

<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Php;

use NightWorksIO\MutationGate\Core\File\Line;

/** A value a file declares by name, and the lines from its name to the end of its value. */
final readonly class ValueDeclaration
{
    private function __construct(private Symbol $symbol, private Line $first, private Line $last)
    {
    }

    public static function of(Symbol $symbol, Line $first, Line $last): self
    {
        return new self($symbol, $first, $last);
    }

    public function symbol(): Symbol
    {
        return $this->symbol;
    }

    /** The line its name stands on. */
    public function first(): Line
    {
        return $this->first;
    }

    /** The line its value ends on. */
    public function last(): Line
    {
        return $this->last;
    }
}

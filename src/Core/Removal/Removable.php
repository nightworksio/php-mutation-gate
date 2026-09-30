<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Removal;

use NightWorksIO\MutationGate\Core\Php\Declared;

/**
 * What a surviving removal says where its callee is pseudo-tested and its
 * tests assert things, only not this: the callee, which may be deleted if
 * nothing outside the tests needs it (ADR-0025, decisions 11 and 12).
 */
final readonly class Removable
{
    private function __construct(private Declared $callee)
    {
    }

    public static function callee(Declared $callee): self
    {
        return new self($callee);
    }

    /** The name of the function or method the removed call calls, as its file declares it. */
    public function name(): string
    {
        return $this->callee->name();
    }
}

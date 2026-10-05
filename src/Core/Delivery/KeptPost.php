<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Delivery;

use NightWorksIO\MutationGate\Core\Proof\Companion;
use NightWorksIO\MutationGate\Core\Proof\Scope;

/**
 * An object a run leaves for `deliver` to keep beside a ledger, such as the coverage map, by the scope the run kept
 * it for, which `deliver` keeps only where it is the one its own trusted run of the default branch writes. The
 * object itself is a file of its own beside the delivery, named as the store names it.
 */
final readonly class KeptPost
{
    private function __construct(private Companion $companion, private Scope $scope)
    {
    }

    public static function of(Companion $companion, Scope $scope): self
    {
        return new self($companion, $scope);
    }

    public function companion(): Companion
    {
        return $this->companion;
    }

    public function scope(): Scope
    {
        return $this->scope;
    }
}

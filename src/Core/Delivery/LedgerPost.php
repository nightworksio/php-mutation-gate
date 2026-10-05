<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Delivery;

use NightWorksIO\MutationGate\Core\Proof\Scope;

/**
 * The ledger a run leaves for `deliver` to write, by the scope the run wrote it for, which `deliver` writes only
 * where it is the one its own trusted run of the default branch writes. Where the store is, `deliver` takes from
 * its own environment. The ledger itself is a file of its own beside the delivery.
 */
final readonly class LedgerPost
{
    private function __construct(private Scope $scope)
    {
    }

    public static function to(Scope $scope): self
    {
        return new self($scope);
    }

    public function scope(): Scope
    {
        return $this->scope;
    }
}

<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Cluster;

/** The cluster a survivor is in, and the rule that put it there. */
final readonly class Membership
{
    private function __construct(private ClusterId $id, private ClusterKind $kind)
    {
    }

    public static function of(ClusterId $id, ClusterKind $kind): self
    {
        return new self($id, $kind);
    }

    public function id(): ClusterId
    {
        return $this->id;
    }

    public function kind(): ClusterKind
    {
        return $this->kind;
    }
}

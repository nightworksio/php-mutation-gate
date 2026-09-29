<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use NightWorksIO\MutationGate\Core\Time\Instant;

/**
 * The run that established a proof, as its CI names it, such as
 * `github:<run id>/<attempt>`, and when it did.
 */
final readonly class Run
{
    private function __construct(private string $id, private Instant $at)
    {
    }

    public static function of(string $id, Instant $at): self
    {
        return new self($id, $at);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function at(): Instant
    {
        return $this->at;
    }
}

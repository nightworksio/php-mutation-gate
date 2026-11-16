<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Fakes;

use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Reach\GraphQuestion;
use NightWorksIO\MutationGate\Core\Reach\ReachGraph;
use NightWorksIO\MutationGate\Core\Serve\KeepsProofs;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Watch\NoticesChanges;
use NightWorksIO\MutationGate\Core\Written;

/**
 * The state `outbreak serve` keeps for a project, as an engine hands it on:
 * its proofs kept as these proofs are, and no change, power or graph to
 * tell of.
 */
final readonly class ServedStateFake implements KeepsProofs, NoticesChanges, ReachGraph
{
    private const string UNASKED = 'This state tells of no change, power or graph.';

    public function __construct(private KeepsProofs $proofs)
    {
    }

    public function proofs(string ...$keys): array|NotWritten
    {
        return $this->proofs->proofs(...$keys);
    }

    public function kept(array $proofs): Written|NotWritten
    {
        return $this->proofs->kept($proofs);
    }

    public function forgotten(string ...$keys): Written|NotWritten
    {
        return $this->proofs->forgotten(...$keys);
    }

    public function changed(array $directories, int $since, Seconds $wait): NotWritten
    {
        return NotWritten::because(self::UNASKED);
    }

    public function powered(int $since, Seconds $wait): NotWritten
    {
        return NotWritten::because(self::UNASKED);
    }

    public function answered(GraphQuestion $question): NotWritten
    {
        return NotWritten::because(self::UNASKED);
    }
}

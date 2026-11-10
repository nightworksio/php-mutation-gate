<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Fakes;

use function array_flip;
use function array_intersect_key;
use function count;

use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Serve\ServesState;
use NightWorksIO\MutationGate\Core\Written;

use function sprintf;

/**
 * The proofs `outbreak serve` holds, in memory, as the contract suite asks
 * the daemon: each kept proof answered by its key; or, with no daemon, no
 * answer at all.
 */
final class ServesStateFake implements ServesState
{
    /** @var array<string, string> */
    private array $proofs = [];

    private function __construct(private bool $running)
    {
    }

    public static function running(): self
    {
        return new self(running: true);
    }

    public static function none(): self
    {
        return new self(running: false);
    }

    public function proofs(string ...$keys): array|NotWritten
    {
        return $this->running
            ? array_intersect_key($this->proofs, array_flip($keys))
            : NotWritten::because('No outbreak serve answers.');
    }

    public function kept(array $proofs): Written|NotWritten
    {
        if (! $this->running) {
            return NotWritten::because('No outbreak serve answers.');
        }

        $this->proofs = [...$this->proofs, ...$proofs];

        return Written::noting('state', sprintf('outbreak serve kept %d proofs.', count($proofs)));
    }
}

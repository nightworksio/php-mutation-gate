<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function array_key_exists;

/**
 * The sites a split stream's boot of the application ran
 * (`splitStream.bootOnce`): no test forked from the booted application can
 * reach them, so each one's mutant is left to a stream that boots in every
 * test.
 *
 * @internal
 */
final class BootedSites
{
    /** Whether the stream boots the application it hands each test's own process. */
    private bool $booting = false;

    /** @var array<string, true> the mutants whose site ran while the stream booted its application, by id */
    private array $booted = [];

    public function __construct(private readonly StreamPlan $plan)
    {
    }

    /** The stream starts or stops booting. */
    public function booting(bool $booting): void
    {
        $this->booting = $booting;
    }

    public function areBooting(): bool
    {
        return $this->booting;
    }

    /** A site of the plan ran while the stream booted: its mutant is left, written once. */
    public function ran(string $file, int $id): void
    {
        $sites = $this->plan->sites;
        $mutant = array_key_exists($file, $sites) && array_key_exists($id, $sites[$file]) ? $sites[$file][$id][0] : '';

        if ($mutant !== '' && ! $this->holds($mutant)) {
            $this->booted[$mutant] = true;
            $this->plan->recorded(StreamLine::booted($mutant));
        }
    }

    /** Whether this mutant's site ran while the stream booted. */
    public function holds(string $mutant): bool
    {
        return array_key_exists($mutant, $this->booted);
    }
}

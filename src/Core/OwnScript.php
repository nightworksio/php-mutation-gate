<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core;

/**
 * A script of the gate's own that a runner starts in a PHP process of its
 * own, by its name in the gate's `bin`.
 */
enum OwnScript: string
{
    /** The PHPUnit runner's warm worker (ADR-0023). */
    case Worker = 'mutation-gate-worker';

    /** The PhpSpec runner's launcher. */
    case PhpSpec = 'mutation-gate-phpspec';

    /** The Pest runner's warm worker. */
    case PestWorker = 'mutation-gate-pest-worker';

    /** What a mutant's Pest process becomes, to be run by the Pest runner's warm worker. */
    case PestClient = 'mutation-gate-pest-client';

    /**
     * How PHP starts it, after the PHP binary, from wherever this process
     * runs the gate from (see startedFrom).
     *
     * @return non-empty-list<string>
     */
    public function started(): array
    {
        return $this->startedFrom(OwnArchive::running());
    }

    /**
     * How PHP starts it, after the PHP binary: by its path, wherever Composer
     * installed the gate; or, from the gate's PHAR, as the archive with its
     * name first (see OwnArchive).
     *
     * @return non-empty-list<string>
     */
    public function startedFrom(OwnArchive|NotGiven $archive): array
    {
        return $archive instanceof OwnArchive ? [$archive->file(), $this->value] : [ThisPackage::script($this->value)];
    }
}

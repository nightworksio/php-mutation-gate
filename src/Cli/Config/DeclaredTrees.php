<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Config;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\DeclaredTree;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Port\TreeSource;

/**
 * The trees a run judges: those the chosen tree source finds, with the
 * config's `trees` laid over them, so a declared floor, reason and exclude
 * win over a manifest's, and a declared path the source does not list is a
 * tree of its own (ADR-0003).
 */
final readonly class DeclaredTrees implements TreeSource
{
    /** @param Listed<DeclaredTree>|Absent $declared */
    public function __construct(private TreeSource $found, private Listed|Absent $declared)
    {
    }

    public function trees(): Trees|CannotJudge
    {
        $trees = $this->found->trees();

        return $trees instanceof Trees && $this->declared instanceof Listed
            ? $trees->declaring(...$this->declared)
            : $trees;
    }
}

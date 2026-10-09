<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

use NightWorksIO\MutationGate\Cli\Config\Chosen;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Choice;
use NightWorksIO\MutationGate\Core\Config\DeclaredTree;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Config\Setup;
use NightWorksIO\MutationGate\Core\Score\Exempt;
use NightWorksIO\MutationGate\Core\Score\Undeclared;
use NightWorksIO\MutationGate\Core\Tree\FoundTrees;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Extension\Extensions;

/**
 * What zero-config found, as `init` writes it (ADR-0017, decision 2): the
 * preset and the runner, which stay fixed once written, and the trees,
 * which `init` lists rather than writes, so a run finds them again.
 */
final readonly class ZeroConfig
{
    private function __construct(private Layer $layer, private Trees $trees)
    {
    }

    /** What zero-config found, or why there is nothing to write: no tree can be found. */
    public static function found(Extensions $extensions, Settings $settings): self|Invalid|CannotJudge
    {
        $source = new Chosen($extensions)->treeSource($settings->treeSource());
        $trees = $source instanceof Invalid || $source instanceof CannotJudge ? $source : $source->trees();

        return $trees instanceof Trees
            ? new self(
                Layer::of(Setup::of(
                    presets: $settings->presets(),
                    runner: Choice::of($settings->runner()->choice()->use()->value(), Options::none()),
                )),
                $trees,
            )
            : $trees;
    }

    /** The preset and the runner, as a config. */
    public function layer(): Layer
    {
        return $this->layer;
    }

    /** The trees, as `init` lists them. */
    public function trees(): FoundTrees
    {
        return FoundTrees::of($this->trees);
    }

    /**
     * The trees as `trees` would list them, each exempt one with its floor
     * of 0, for an imported floor or exclude to apply to.
     *
     * @return Listed<DeclaredTree>
     */
    public function declared(): Listed
    {
        $declared = [];

        foreach ($this->trees as $tree) {
            $exempt = $tree->declared();
            $declared[] = DeclaredTree::of(
                $tree->path(),
                $exempt instanceof Exempt ? $exempt : Undeclared::floor(),
                Listed::of(),
            );
        }

        return Listed::of(...$declared);
    }
}

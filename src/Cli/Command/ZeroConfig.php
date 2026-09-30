<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

use NightWorksIO\MutationGate\Cli\Config\Chosen;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Choice;
use NightWorksIO\MutationGate\Core\Config\DeclaredTree;
use NightWorksIO\MutationGate\Core\Config\Floors;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Config\Setup;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Score\Exempt;
use NightWorksIO\MutationGate\Core\Score\Undeclared;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Extension\Extensions;

/** What zero-config found, as the layer `init` writes: the preset, the runner and the trees. */
final readonly class ZeroConfig
{
    /** The preset, the runner and the trees zero-config found, as a config, or why there is none to write. */
    public static function layer(
        Extensions $extensions,
        Settings|Invalid|CannotJudge $settings,
    ): Layer|Invalid|CannotJudge {
        if (! $settings instanceof Settings) {
            return $settings;
        }

        $source = new Chosen($extensions)->treeSource($settings->treeSource());
        $trees = $source instanceof Invalid || $source instanceof CannotJudge ? $source : $source->trees();

        return $trees instanceof Trees ? self::found($settings, $trees) : $trees;
    }

    private static function found(Settings $settings, Trees $trees): Layer
    {
        $declared = [];

        foreach ($trees as $tree) {
            $declared[] = self::tree($tree);
        }

        return Layer::of(
            Setup::of(
                presets: $settings->presets(),
                runner: Choice::of($settings->runner()->choice()->use(), Json::object()),
            ),
            Floors::of(trees: Listed::of(...$declared)),
        );
    }

    /** A tree as `trees` lists it, with the floor of 0 an exclusion gives it. */
    private static function tree(Tree $tree): DeclaredTree
    {
        $declared = $tree->declared();

        return DeclaredTree::of(
            $tree->path(),
            $declared instanceof Exempt ? $declared : Undeclared::floor(),
            Listed::of(),
        );
    }
}

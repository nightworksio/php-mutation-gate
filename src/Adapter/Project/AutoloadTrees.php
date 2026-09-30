<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Project;

use function count;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Port\TreeSource;

/** The `composer` tree source (ADR-0005): one tree per `autoload` path of `composer.json`, not `autoload-dev`. */
final readonly class AutoloadTrees implements TreeSource
{
    private function __construct(private Manifests $manifests)
    {
    }

    public static function in(string $root): self
    {
        return new self(Manifests::in($root));
    }

    public function trees(): Trees|CannotJudge
    {
        $paths = $this->manifests->autoloaded();

        return match (true) {
            $paths instanceof CannotJudge => $paths,
            count($paths) === 0 => CannotJudge::because(
                'No tree found: composer.json autoloads no path; list trees in the config.',
            ),
            default => $this->manifests->trees($paths),
        };
    }
}

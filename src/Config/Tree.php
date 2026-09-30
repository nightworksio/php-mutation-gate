<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Score\Undeclared;

/**
 * A `trees` entry (ADR-0003): a path, the floor it declares, the reason a floor of 0 needs, and the globs of the
 * files it leaves out.
 */
final readonly class Tree
{
    private function __construct(private Json $json)
    {
    }

    /** @param list<string> $excluding globs from the repository root, each matching a file in the tree */
    public static function at(
        string $path,
        int|float|Undeclared $floor = new Undeclared(),
        string $because = '',
        array $excluding = [],
    ): self {
        $tree = Json::object()->with('path', $path);
        $tree = $floor instanceof Undeclared ? $tree : $tree->with('floor', $floor);
        $tree = $because === '' ? $tree : $tree->with('reason', $because);

        return new self($excluding === [] ? $tree : $tree->with('exclude', Json::items($excluding)));
    }

    public function written(): Json
    {
        return $this->json;
    }
}

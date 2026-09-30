<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use NightWorksIO\MutationGate\Core\File\Glob;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Score\Undeclared;

/**
 * A `trees` entry (ADR-0003): a path, the floor it declares, the reason a floor of 0 needs, and the files it leaves
 * out (ADR-0016).
 */
final readonly class Tree
{
    private function __construct(private Json $json)
    {
    }

    public static function at(string $path, int|float|Undeclared $floor = new Undeclared(), string $because = ''): self
    {
        $tree = Json::object()->with('path', $path);
        $tree = $floor instanceof Undeclared ? $tree : $tree->with('floor', $floor);

        return new self($because === '' ? $tree : $tree->with('reason', $because));
    }

    /** This tree, but for the files these globs from the repository root match: `trees[].exclude`. */
    public function excluding(Glob ...$globs): self
    {
        $excluded = [];

        foreach ($globs as $glob) {
            $excluded[] = $glob->value();
        }

        return new self($this->json->with('exclude', Json::items($excluded)));
    }

    public function written(): Json
    {
        return $this->json;
    }
}

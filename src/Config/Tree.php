<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use NightWorksIO\MutationGate\Core\Config\Definition\Json;
use NightWorksIO\MutationGate\Core\Score\Undeclared;

/** A `trees` entry (ADR-0003): a path, the floor it declares, and the reason a floor of 0 needs. */
final readonly class Tree
{
    private function __construct(private string $json)
    {
    }

    public static function at(string $path, int|float|Undeclared $floor = new Undeclared(), string $because = ''): self
    {
        $tree = ['path' => $path];

        if (! $floor instanceof Undeclared) {
            $tree['floor'] = $floor;
        }

        if ($because !== '') {
            $tree['reason'] = $because;
        }

        return new self(Json::encode($tree));
    }

    public function written(): string
    {
        return $this->json;
    }
}

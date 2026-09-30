<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Node;

/**
 * A key that is refused whatever it holds, with what to do instead: a
 * webhook URL, which is a credential, and belongs in the environment.
 *
 * @implements Shape<never>
 */
final readonly class Refused implements Shape
{
    private function __construct(private string $instead)
    {
    }

    public static function because(string $instead): self
    {
        return new self($instead);
    }

    public function read(Node $at): Reading
    {
        return Reading::refused(Problem::at($at->at(), $this->instead));
    }

    public function expected(): string
    {
        return $this->instead;
    }

    public function schema(): Json
    {
        return Json::object()->with('not', Json::object())->with('description', $this->instead);
    }

    public function effects(): array
    {
        return [];
    }
}

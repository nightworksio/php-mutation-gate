<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Format\Node;

/**
 * A key that may hold anything and changes nothing: the `$schema` an editor
 * checks a file by.
 *
 * @implements Shape<never>
 */
final readonly class Unchecked implements Shape
{
    private function __construct(private string $description)
    {
    }

    public static function describedAs(string $description): self
    {
        return new self($description);
    }

    public function read(Node $at): Reading
    {
        return Reading::nothing();
    }

    public function expected(): string
    {
        return 'anything';
    }

    public function schema(): Json
    {
        return Json::object(Member::of('description', $this->description));
    }

    public function effects(): array
    {
        return [];
    }
}

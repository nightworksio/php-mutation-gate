<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Kind;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;

/**
 * A mutant's id: twelve lowercase hex characters, which YAML and NEON read as
 * a number unless it is quoted.
 *
 * @implements Shape<MutantId>
 */
final readonly class Identifier implements Shape
{
    public static function mutant(): self
    {
        return new self();
    }

    public function read(Node $at): Reading
    {
        $id = $at->kind() === Kind::Text ? MutantId::parse($at->text()) : $at;

        return $id instanceof MutantId ? Reading::of($id) : Reading::refused($at->mismatch($this->expected()));
    }

    public function expected(): string
    {
        return 'a mutant id, twelve lowercase hex characters in quotes';
    }

    public function schema(): Json
    {
        return Json::object(Member::of('type', 'string'))->with(Member::of('pattern', '^[0-9a-f]{12}$'));
    }

    public function effects(): array
    {
        return [];
    }
}

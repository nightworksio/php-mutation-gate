<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Kind;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;

/**
 * An amount of memory written as PHP's `memory_limit` writes one: `512M`,
 * `1G`, a number of bytes, or `-1` for none.
 *
 * @implements Shape<MemoryCap>
 */
final readonly class MemoryAmount implements Shape
{
    public static function written(): self
    {
        return new self();
    }

    public function read(Node $at): Reading
    {
        $amount = $at->kind() === Kind::Text ? MemoryCap::parse($at->text()) : $at;

        return $amount instanceof MemoryCap
            ? Reading::of($amount)
            : Reading::refused($at->mismatch($this->expected()));
    }

    public function expected(): string
    {
        return 'an amount of memory such as 512M or 1G, or -1 for none';
    }

    public function schema(): Json
    {
        return Json::object()
            ->with(Member::of('type', 'string'))
            ->with(Member::of('pattern', '^(?:-1|[1-9][0-9]*[KkMmGg]?)$'));
    }

    public function effects(): array
    {
        return [];
    }
}

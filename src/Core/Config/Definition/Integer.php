<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Kind;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Format\Node;

use function sprintf;

/**
 * A whole number of at least some least one.
 *
 * @implements Shape<int>
 */
final readonly class Integer implements Shape
{
    private function __construct(private int $least)
    {
    }

    public static function atLeast(int $least): self
    {
        return new self($least);
    }

    public function read(Node $at): Reading
    {
        return $at->kind() === Kind::Integer && $at->integer() >= $this->least
            ? Reading::of($at->integer())
            : Reading::refused($at->mismatch($this->expected()));
    }

    public function expected(): string
    {
        return sprintf('an integer of at least %d', $this->least);
    }

    public function schema(): Json
    {
        return Json::object(Member::of('type', 'integer'))->with(Member::of('minimum', $this->least));
    }

    public function effects(): array
    {
        return [];
    }
}

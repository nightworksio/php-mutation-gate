<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Kind;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\NotGiven;

use function sprintf;

/**
 * A setting written as a whole number of at least some least, or `false`
 * where it is turned off.
 *
 * @internal
 *
 * @implements Shape<int|false>
 */
final readonly class MultipleOrOff implements Shape
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
        $value = match (true) {
            $at->kind() === Kind::Integer && $at->integer() >= $this->least => $at->integer(),
            $at->kind() === Kind::Boolean && ! $at->boolean() => false,
            default => NotGiven::value(),
        };

        return $value instanceof NotGiven ? Reading::refused($at->mismatch($this->expected())) : Reading::of($value);
    }

    public function expected(): string
    {
        return sprintf('an integer of at least %d, or false', $this->least);
    }

    public function schema(): Json
    {
        return Json::object(Member::of(
            'oneOf',
            Json::items(
                Json::object(Member::of('type', 'integer'), Member::of('minimum', $this->least)),
                Json::object(Member::of('const', value: false)),
            ),
        ));
    }

    public function effects(): array
    {
        return [];
    }
}

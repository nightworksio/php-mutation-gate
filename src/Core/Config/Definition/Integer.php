<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use function is_int;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Kind;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\NotGiven;

use function sprintf;

/**
 * A whole number of at least some least one, and at most some most one
 * where it has one.
 *
 * @internal
 *
 * @implements Shape<int>
 */
final readonly class Integer implements Shape
{
    private function __construct(private int $least, private int|NotGiven $most)
    {
    }

    public static function atLeast(int $least): self
    {
        return new self($least, NotGiven::value());
    }

    public static function between(int $least, int $most): self
    {
        return new self($least, $most);
    }

    public function read(Node $at): Reading
    {
        $within = $at->kind() === Kind::Integer
            && $at->integer() >= $this->least
            && (! is_int($this->most) || $at->integer() <= $this->most);

        return $within ? Reading::of($at->integer()) : Reading::refused($at->mismatch($this->expected()));
    }

    public function expected(): string
    {
        return is_int($this->most)
            ? sprintf('an integer from %d to %d', $this->least, $this->most)
            : sprintf('an integer of at least %d', $this->least);
    }

    public function schema(): Json
    {
        $schema = Json::object(Member::of('type', 'integer'))->with(Member::of('minimum', $this->least));

        return is_int($this->most) ? $schema->with(Member::of('maximum', $this->most)) : $schema;
    }

    public function effects(): array
    {
        return [];
    }
}

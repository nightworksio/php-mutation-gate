<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use NightWorksIO\MutationGate\Core\Config\Learned;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Format\Node;

use function sprintf;

/**
 * A value of another shape, or the word `learned`, which has the gate learn
 * it from what its earlier runs measured.
 *
 * @internal
 *
 * @template T of object|scalar
 *
 * @implements Shape<T|Learned>
 */
final readonly class OrLearned implements Shape
{
    /** @param Shape<T> $written */
    private function __construct(private Shape $written)
    {
    }

    /**
     * @template U of object|scalar
     *
     * @param  Shape<U>     $written
     * @return self<U>
     */
    public static function of(Shape $written): self
    {
        return new self($written);
    }

    public function read(Node $at): Reading
    {
        $learned = Enumerated::of(Learned::cases())->read($at);
        $written = $this->written->read($at);

        return match (true) {
            $learned->problems() === [] => $learned,
            $written->problems() === [] => $written,
            default => Reading::refused($at->mismatch($this->expected())),
        };
    }

    public function expected(): string
    {
        return sprintf('%s, or "%s"', $this->written->expected(), Learned::Learned->value);
    }

    public function schema(): Json
    {
        return Json::object(Member::of(
            'anyOf',
            Json::items($this->written->schema(), Enumerated::of(Learned::cases())->schema()),
        ));
    }

    public function effects(): array
    {
        return $this->written->effects();
    }
}

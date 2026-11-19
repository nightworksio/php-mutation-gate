<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use NightWorksIO\MutationGate\Core\Config\StreamChildren;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Format\Node;

use function sprintf;

/**
 * A whole count of at least one, or the word `cores`, as `splitStream.children` takes (ADR-0173).
 *
 * @internal
 *
 * @implements Shape<int|StreamChildren>
 */
final readonly class ChildCount implements Shape
{
    public function read(Node $at): Reading
    {
        $cores = Enumerated::of(StreamChildren::cases())->read($at);
        $count = Integer::atLeast(1)->read($at);

        return match (true) {
            $cores->problems() === [] => $cores,
            $count->problems() === [] => $count,
            default => Reading::refused($at->mismatch($this->expected())),
        };
    }

    public function expected(): string
    {
        return sprintf('%s, or "%s"', Integer::atLeast(1)->expected(), StreamChildren::Cores->value);
    }

    public function schema(): Json
    {
        return Json::object(Member::of(
            'anyOf',
            Json::items(Integer::atLeast(1)->schema(), Enumerated::of(StreamChildren::cases())->schema()),
        ));
    }

    public function effects(): array
    {
        return [];
    }
}

<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use NightWorksIO\MutationGate\Core\Config\AllKept;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Format\Node;

use function sprintf;

/**
 * A whole count of at least one, or the word `all`, as `proofs.keep.killers` takes.
 *
 * @internal
 *
 * @implements Shape<int|AllKept>
 */
final readonly class CountOrAll implements Shape
{
    public function read(Node $at): Reading
    {
        $all = Enumerated::of(AllKept::cases())->read($at);
        $count = Integer::atLeast(1)->read($at);

        return match (true) {
            $all->problems() === [] => $all,
            $count->problems() === [] => $count,
            default => Reading::refused($at->mismatch($this->expected())),
        };
    }

    public function expected(): string
    {
        return sprintf('%s, or "%s"', Integer::atLeast(1)->expected(), AllKept::All->value);
    }

    public function schema(): Json
    {
        return Json::object(Member::of(
            'anyOf',
            Json::items(Integer::atLeast(1)->schema(), Enumerated::of(AllKept::cases())->schema()),
        ));
    }

    public function effects(): array
    {
        return [];
    }
}

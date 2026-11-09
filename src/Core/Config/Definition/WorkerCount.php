<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use function array_map;
use function implode;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Local\WorkerShare;

use function sprintf;

/**
 * A whole count of at least one, or a share of the logical cores, as
 * `local.workers` takes (ADR-0120).
 *
 * @internal
 *
 * @implements Shape<int|WorkerShare>
 */
final readonly class WorkerCount implements Shape
{
    public function read(Node $at): Reading
    {
        $share = Enumerated::of(WorkerShare::cases())->read($at);
        $count = Integer::atLeast(1)->read($at);

        return match (true) {
            $share->problems() === [] => $share,
            $count->problems() === [] => $count,
            default => Reading::refused($at->mismatch($this->expected())),
        };
    }

    public function expected(): string
    {
        return sprintf(
            '%s, or one of "%s"',
            Integer::atLeast(1)->expected(),
            implode('", "', array_map(static fn(WorkerShare $share): string => $share->value, WorkerShare::cases())),
        );
    }

    public function schema(): Json
    {
        return Json::object(Member::of(
            'anyOf',
            Json::items(Integer::atLeast(1)->schema(), Enumerated::of(WorkerShare::cases())->schema()),
        ));
    }

    public function effects(): array
    {
        return [];
    }
}

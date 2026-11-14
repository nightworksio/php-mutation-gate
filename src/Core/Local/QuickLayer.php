<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Local;

use NightWorksIO\MutationGate\Core\Config\SplitStream;
use NightWorksIO\MutationGate\Core\Config\StaticCheck;
use NightWorksIO\MutationGate\Core\Config\StreamSharding;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;

/**
 * What a quick round of `watch` lays over the config (ADR-0146): its mutants
 * in split streams of their covering test files, with no static check.
 *
 * @internal
 */
final readonly class QuickLayer
{
    /** @return list<Member> */
    public static function members(): array
    {
        return [
            Member::of(
                SplitStream::SECTION,
                Json::object(
                    Member::of(SplitStream::ENABLED, value: true),
                    Member::of(SplitStream::SHARDING, StreamSharding::Source->value),
                ),
            ),
            Member::of(StaticCheck::SECTION, Json::object(Member::of(StaticCheck::TOOL, StaticCheck::NONE))),
        ];
    }
}

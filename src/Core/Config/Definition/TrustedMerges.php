<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use NightWorksIO\MutationGate\Core\Config\MergedTrust;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Kind;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Format\Node;

/**
 * `ci.trustMergedPullRequests`: `false`, `true` or `"delta"` (ADR-0139).
 *
 * @internal
 *
 * @implements Shape<MergedTrust>
 */
final readonly class TrustedMerges implements Shape
{
    private const string EXPECTED = 'true, false or "delta"';

    public static function values(): self
    {
        return new self();
    }

    public function read(Node $at): Reading
    {
        return match (true) {
            $at->kind() === Kind::Boolean => Reading::of($at->boolean() ? MergedTrust::Tree : MergedTrust::Off),
            $at->kind() === Kind::Text && $at->text() === MergedTrust::Delta->value => Reading::of(MergedTrust::Delta),
            default => Reading::refused($at->mismatch(self::EXPECTED)),
        };
    }

    public function expected(): string
    {
        return self::EXPECTED;
    }

    public function schema(): Json
    {
        return Json::object(Member::of('enum', Json::items(false, true, MergedTrust::Delta->value)));
    }

    public function effects(): array
    {
        return [];
    }
}

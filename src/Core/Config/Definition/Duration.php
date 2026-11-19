<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Kind;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * A duration written `50ms`, `90s`, `15m`, `1h30m` or `7d`.
 *
 * @internal
 *
 * @implements Shape<Seconds>
 */
final readonly class Duration implements Shape
{
    /** The pattern a JSON schema holds a written duration to. */
    public const string PATTERN = '^(?=.)(?:[0-9]+d)?(?:[0-9]+h)?(?:[0-9]+m)?(?:[0-9]+s)?(?:[0-9]+ms)?$';

    public static function written(): self
    {
        return new self();
    }

    public function read(Node $at): Reading
    {
        $duration = $at->kind() === Kind::Text ? Seconds::parse($at->text()) : $at;

        return $duration instanceof Seconds
            ? Reading::of($duration)
            : Reading::refused($at->mismatch($this->expected()));
    }

    public function expected(): string
    {
        return 'a duration such as 90s, 15m, 1h30m or 7d';
    }

    public function schema(): Json
    {
        return Json::object()
            ->with(Member::of('type', 'string'))
            ->with(Member::of('pattern', self::PATTERN));
    }

    public function effects(): array
    {
        return [];
    }
}

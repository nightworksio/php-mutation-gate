<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Kind;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Time\Day;

/**
 * A calendar day written `YYYY-MM-DD`.
 *
 * @implements Shape<Day>
 */
final readonly class Date implements Shape
{
    public static function written(): self
    {
        return new self();
    }

    public function read(Node $at): Reading
    {
        $day = $at->kind() === Kind::Text ? Day::of($at->text()) : $at;

        return $day instanceof Day ? Reading::of($day) : Reading::refused($at->mismatch($this->expected()));
    }

    public function expected(): string
    {
        return 'a date written YYYY-MM-DD';
    }

    public function schema(): Json
    {
        return Json::object()->with('type', 'string')->with('pattern', '^[0-9]{4}-[0-9]{2}-[0-9]{2}$');
    }

    public function effects(): array
    {
        return [];
    }
}

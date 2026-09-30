<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use function is_float;
use function is_int;

use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Table;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Kind;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Format\Node;

/**
 * An object whose keys are data, such as path prefixes or colour names, and
 * whose values are numbers.
 *
 * @implements Shape<Table>
 */
final readonly class NumberMap implements Shape
{
    private function __construct(private Number $number)
    {
    }

    public static function of(Number $number): self
    {
        return new self($number);
    }

    public function read(Node $at): Reading
    {
        if ($at->kind() !== Kind::Map && $at->kind() !== Kind::Empty) {
            return Reading::refused($at->mismatch($this->expected()));
        }

        $numbers = Table::none();
        $readings = [];

        foreach ($at->entries() as $key => $entry) {
            $reading = $this->number->read($at->entry($key));
            $number = $reading->value();
            $readings[] = $reading;

            if (is_int($number) || is_float($number)) {
                $numbers = $numbers->merged(Table::row($key, $number));
            }
        }

        $problems = Reading::problemsIn(...$readings);

        return $problems instanceof Invalid ? Reading::invalid($problems) : Reading::of($numbers);
    }

    public function expected(): string
    {
        return 'an object of numbers';
    }

    public function schema(): Json
    {
        return Json::object(
            Member::of('type', 'object'),
        )->with(Member::of('additionalProperties', $this->number->schema()));
    }

    public function effects(): array
    {
        return [];
    }
}

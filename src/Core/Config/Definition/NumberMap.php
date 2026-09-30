<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use function is_float;
use function is_int;
use function json_encode;

use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\PathOrigin;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Config\Table;
use NightWorksIO\MutationGate\Core\Cost\LineRate;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\JsonText;
use NightWorksIO\MutationGate\Core\Format\Kind;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Format\Node;

use function sprintf;

/**
 * An object whose keys are data, such as path prefixes or colour names, and
 * whose values are numbers.
 *
 * @implements Shape<Table>
 */
final readonly class NumberMap implements Shape
{
    private function __construct(private Number $number, private PathOrigin|Absent $origin)
    {
    }

    public static function of(Number $number): self
    {
        return new self($number, Absent::setting());
    }

    /** Numbers by path prefix, each named from where the layer is, but `""`, which is every path. */
    public static function byPrefix(Number $number, PathOrigin $origin): self
    {
        return new self($number, $origin);
    }

    public function read(Node $at): Reading
    {
        if ($at->kind() !== Kind::Map && $at->kind() !== Kind::Empty) {
            return Reading::refused($at->mismatch($this->expected()));
        }

        $numbers = Table::none();
        $readings = [];

        foreach ($at->entries() as $entryKey => $entry) {
            $key = sprintf('%s', $entryKey);
            $prefix = $this->keyed($key);
            $reading = $this->origin instanceof PathOrigin && Path::of($prefix)->escapes()
                ? Reading::refused(Problem::at(
                    $at->entry($key)->at(),
                    sprintf('expected %s, got %s', Location::INSIDE, json_encode($key, JsonText::FLAGS)),
                ))
                : $this->number->read($at->entry($key));
            $number = $reading->value();
            $readings[] = $reading;

            if (is_int($number) || is_float($number)) {
                $numbers = $numbers->merged(Table::row($prefix, $number));
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

    private function keyed(string $key): string
    {
        return $this->origin instanceof Absent || $key === LineRate::EVERYWHERE
            ? $key
            : $this->origin->path(Path::of($key))->value();
    }
}

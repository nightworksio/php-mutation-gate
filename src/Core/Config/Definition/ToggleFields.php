<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use function array_values;
use function is_bool;

use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\Toggle;
use NightWorksIO\MutationGate\Core\Config\Toggles;
use NightWorksIO\MutationGate\Core\Format\Node;

/**
 * The toggles a section holds ({@see Toggle}), each `true` or `false`, read beside the section's other keys.
 *
 * @internal
 */
final readonly class ToggleFields
{
    /** @param array<string, Field<bool>> $fields each toggle's field, by its path */
    private function __construct(private string $section, private array $fields)
    {
    }

    /** The toggles of a section. */
    public static function in(string $section): self
    {
        $fields = [];

        foreach (Toggle::cases() as $toggle) {
            if ($toggle->section() === $section) {
                $fields[$toggle->value] = Field::optional($toggle->key(), Flag::boolean(), $toggle->effect());
            }
        }

        return new self($section, $fields);
    }

    /** @return list<Field<bool>> */
    public function fields(): array
    {
        return array_values($this->fields);
    }

    /**
     * Each toggle the section sets, read from it.
     *
     * @return list<Reading<bool>>
     */
    public function read(Node $section): array
    {
        $read = [];

        foreach ($this->fields as $field) {
            $read[] = $field->read($section);
        }

        return $read;
    }

    /**
     * What these readings, read by {@see read()}, set.
     *
     * @param list<Reading<bool>> $readings
     */
    public function set(array $readings): Toggles
    {
        $toggles = Toggles::none();
        $at = 0;

        foreach ($this->fields as $path => $field) {
            $value = $readings[$at++]->value();
            $toggles = $toggles->with(Toggle::from($path), is_bool($value) ? $value : Absent::setting());
        }

        return $toggles;
    }

    /**
     * The section, where it holds these toggles alone.
     *
     * @return Field<Layer>
     */
    public function section(): Field
    {
        return Field::section(
            $this->section,
            Section::of(
                function (Node $node): Layer|Invalid {
                    $readings = $this->read($node);

                    return Reading::built(fn(): Layer => Layer::of($this->set($readings)), ...$readings);
                },
                ...$this->fields(),
            ),
        );
    }
}

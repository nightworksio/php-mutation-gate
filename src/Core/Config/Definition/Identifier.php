<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use function is_string;

use NightWorksIO\MutationGate\Core\Mutant\MutantId;

/**
 * A mutant's id, as every report prints it. YAML and NEON read an unquoted
 * id such as `123456789012` as a number, so an id that is not a string is
 * refused with a word about quoting it.
 */
final readonly class Identifier implements Node
{
    public static function mutant(): self
    {
        return new self();
    }

    public function read(mixed $value, string $at): Reading
    {
        $id = is_string($value) ? MutantId::parse($value) : $value;

        return $id instanceof MutantId ? Reading::of($id, $value) : Reading::mismatch($at, $this->expected(), $value);
    }

    public function expected(): string
    {
        return 'a mutant id, twelve lowercase hex characters in quotes';
    }

    public function schema(): array
    {
        return ['type' => 'string', 'pattern' => '^[0-9a-f]{12}$'];
    }

    public function effects(): array
    {
        return [];
    }
}

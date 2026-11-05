<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Doctor;

use function array_keys;

use ArrayIterator;
use IteratorAggregate;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use Traversable;

/**
 * Every CI definition that runs the gate, by its path, with its text: what
 * names it, and what pins the release it runs (ADR-0083).
 *
 * @internal
 *
 * @implements IteratorAggregate<string, string>
 */
final readonly class GateDefinitions implements IteratorAggregate
{
    /** @param array<string, string> $texts by the definition's path */
    private function __construct(private array $texts)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    /** These, with a definition at this path holding this text. */
    public function with(Path $definition, string $text): self
    {
        return new self([...$this->texts, $definition->value() => $text]);
    }

    /** Each definition's path. */
    public function paths(): Paths
    {
        $paths = Paths::none();

        foreach (array_keys($this->texts) as $definition) {
            $paths = $paths->with(Path::of($definition));
        }

        return $paths;
    }

    /** @return Traversable<string, string> each definition's text, by its path */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->texts);
    }
}

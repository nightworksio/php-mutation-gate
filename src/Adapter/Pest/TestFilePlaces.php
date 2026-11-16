<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_flip;
use function array_key_exists;
use function array_keys;
use function array_map;

use Closure;

use function ksort;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;

/**
 * Where each test file stands among them all, by the class Pest declares for
 * it and by the name it is named after: the only files that may hold a test
 * of a class, found without reading the others.
 *
 * @internal
 */
final readonly class TestFilePlaces
{
    /**
     * @param list<Path>               $files  every test file, in the order listed
     * @param array<string, list<int>> $places the places of the files each class or name stands for, ascending
     */
    private function __construct(private array $files, private array $places)
    {
    }

    /**
     * @param Paths                  $files every test file, in the order listed
     * @param Closure(Path): string  $pest  the class Pest declares for a test file
     */
    public static function of(Paths $files, Closure $pest): self
    {
        $listed = [...$files];
        $places = [];

        foreach ($listed as $place => $file) {
            $places[$pest($file)][] = $place;
            $places[$file->stem()][] = $place;
        }

        return new self($listed, $places);
    }

    /**
     * The test files Pest declares one of these classes for, or that are
     * named after one of these names, each once, in the order listed.
     *
     * @param  list<string> $names
     * @return list<Path>
     */
    public function standingFor(array $names): array
    {
        $held = [];

        foreach ($names as $name) {
            $held += array_key_exists($name, $this->places) ? array_flip($this->places[$name]) : [];
        }

        ksort($held);

        return array_map(fn(int $place): Path => $this->files[$place], array_keys($held));
    }
}

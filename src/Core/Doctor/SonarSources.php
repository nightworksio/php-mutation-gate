<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Doctor;

use function array_filter;
use function array_map;
use function array_values;
use function explode;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Format\Properties;
use NightWorksIO\MutationGate\Core\NotGiven;

use function trim;

/**
 * The paths `sonar.sources` names in `sonar-project.properties`: the only
 * files SonarQube indexes, and so the only ones it imports an issue on.
 */
final readonly class SonarSources
{
    public const string FILE = 'sonar-project.properties';

    private const string KEY = 'sonar.sources';

    private function __construct(private Paths $paths)
    {
    }

    public static function of(Path ...$paths): self
    {
        return new self(Paths::of(...$paths));
    }

    /** The paths the properties set `sonar.sources` to; nothing where they do not set it, or set it to none. */
    public static function in(Properties $properties): self|NotGiven
    {
        $value = $properties->value(self::KEY);
        $named = $value instanceof NotGiven ? [] : array_values(array_filter(
            array_map(trim(...), explode(',', $value)),
            static fn(string $path): bool => $path !== '',
        ));

        return $named === [] ? NotGiven::value() : self::of(...array_map(Path::of(...), $named));
    }

    /** Whether SonarQube indexes the files of this tree: it is one of the paths, or inside one. */
    public function holds(Path $tree): bool
    {
        foreach ($this->paths as $path) {
            if ($tree->within($path)) {
                return true;
            }
        }

        return false;
    }
}

<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Config;

use function array_find;
use function is_file;

use NightWorksIO\MutationGate\Adapter\Infection\OwnConfig;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\NotGiven;

/**
 * The Infection config `init --from` and `import` take over from: the file
 * the command line names, or, where it names none, the first Infection
 * itself would read in the project's root.
 */
final readonly class InfectionFile
{
    private const string NONE = 'There is no infection.json5, infection.json or .dist of either here to import from.';

    private function __construct(private Path|NotGiven $named)
    {
    }

    /** The file the command line names, or none, for the one Infection would read. */
    public static function named(string $file): self
    {
        return new self($file === '' ? NotGiven::value() : Path::of($file));
    }

    /** The file, from the project's root, or why there is none to import from. */
    public function in(string $project): Path|CannotJudge
    {
        $root = Root::of($project);

        if ($this->named instanceof Path) {
            return $this->named;
        }

        $found = array_find([...OwnConfig::files()], static fn(Path $file): bool => is_file($root->at($file)->value()));

        return $found instanceof Path ? $found : CannotJudge::because(self::NONE);
    }
}

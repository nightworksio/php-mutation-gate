<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use NightWorksIO\MutationGate\Core\Config\PathOrigin;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Kind;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Format\Node;

/**
 * A path, named from where the layer that writes it is: a config file's own
 * directory, or the project. One that goes up out of the project, or is
 * absolute, is refused, but for a file the command line names by its
 * absolute path.
 *
 * @implements Shape<Path>
 */
final readonly class Location implements Shape
{
    /** What a path that goes up out of the project is not. */
    public const string INSIDE = 'a path inside the project';

    private function __construct(private PathOrigin $origin)
    {
    }

    public static function path(PathOrigin $origin): self
    {
        return new self($origin);
    }

    public function read(Node $at): Reading
    {
        $written = $at->kind() === Kind::Text ? $at->text() : '';
        $path = $this->origin->path(Path::of($written));

        return match (true) {
            $written === '' => Reading::refused($at->mismatch($this->expected())),
            $path->escapes() && ! $this->reachable($path) => Reading::refused($at->mismatch(self::INSIDE)),
            default => Reading::of($path),
        };
    }

    public function expected(): string
    {
        return 'a path';
    }

    public function schema(): Json
    {
        return Json::object(Member::of('type', 'string'))->with(Member::of('minLength', 1));
    }

    public function effects(): array
    {
        return [];
    }

    /** Whether a path outside the project is one this layer may name: absolute, on the command line. */
    private function reachable(Path $path): bool
    {
        return $path->isAbsolute() && $this->origin->reachesOutside();
    }
}

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
 * `true` or `false`, or a path read as `Location` reads one.
 *
 * @internal
 *
 * @implements Shape<bool|Path>
 */
final readonly class FlagOrPath implements Shape
{
    private function __construct(private Location $path)
    {
    }

    public static function of(PathOrigin $origin): self
    {
        return new self(Location::path($origin));
    }

    public function read(Node $at): Reading
    {
        if ($at->kind() === Kind::Boolean) {
            return Reading::of($at->boolean());
        }

        return $at->kind() === Kind::Text ? $this->path->read($at) : Reading::refused($at->mismatch($this->expected()));
    }

    public function expected(): string
    {
        return 'true, false or a path';
    }

    public function schema(): Json
    {
        return Json::object(Member::of(
            'oneOf',
            Json::items(Json::object(Member::of('type', 'boolean')), $this->path->schema()),
        ));
    }

    public function effects(): array
    {
        return [];
    }
}

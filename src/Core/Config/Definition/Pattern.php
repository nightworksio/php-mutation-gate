<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use NightWorksIO\MutationGate\Core\Config\Origin;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Kind;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Format\Node;

/**
 * A glob, a pattern of paths, named from where the layer that writes it is,
 * as a path is: `Gen/**` in `ci/gate.json` is `ci/Gen/**`, and `../src/**`
 * there is `src/**`.
 *
 * @implements Shape<non-empty-string>
 */
final readonly class Pattern implements Shape
{
    private function __construct(private Origin $origin)
    {
    }

    public static function glob(Origin $origin): self
    {
        return new self($origin);
    }

    public function read(Node $at): Reading
    {
        $written = $at->kind() === Kind::Text ? $at->text() : '';

        return $written !== ''
            ? Reading::of($this->origin->path(Path::of($written))->value())
            : Reading::refused($at->mismatch($this->expected()));
    }

    public function expected(): string
    {
        return 'a glob';
    }

    public function schema(): Json
    {
        return Json::object(Member::of('type', 'string'))->with(Member::of('minLength', 1));
    }

    public function effects(): array
    {
        return [];
    }
}

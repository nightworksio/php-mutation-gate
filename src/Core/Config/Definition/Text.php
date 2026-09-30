<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Kind;
use NightWorksIO\MutationGate\Core\Format\Node;

/**
 * Text that is not empty, named for what it holds: `a reason`, `a glob`.
 *
 * @implements Shape<non-empty-string>
 */
final readonly class Text implements Shape
{
    private function __construct(private string $what)
    {
    }

    public static function of(string $what): self
    {
        return new self($what);
    }

    public function read(Node $at): Reading
    {
        $text = $at->kind() === Kind::Text ? $at->text() : '';

        return $text !== '' ? Reading::of($text) : Reading::refused($at->mismatch($this->what));
    }

    public function expected(): string
    {
        return $this->what;
    }

    public function schema(): Json
    {
        return Json::object()->with('type', 'string')->with('minLength', 1);
    }

    public function effects(): array
    {
        return [];
    }
}

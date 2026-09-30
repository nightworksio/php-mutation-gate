<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Kind;
use NightWorksIO\MutationGate\Core\Format\Node;

use function str_starts_with;

/**
 * An `https://` URL with something after the scheme.
 *
 * @implements Shape<non-empty-string>
 */
final readonly class Url implements Shape
{
    private const string SCHEME = 'https://';

    public static function https(): self
    {
        return new self();
    }

    public function read(Node $at): Reading
    {
        $url = $at->kind() === Kind::Text ? $at->text() : '';

        return $url !== '' && str_starts_with($url, self::SCHEME) && $url !== self::SCHEME
            ? Reading::of($url)
            : Reading::refused($at->mismatch($this->expected()));
    }

    public function expected(): string
    {
        return 'an https:// URL';
    }

    public function schema(): Json
    {
        return Json::object()->with('type', 'string')->with('pattern', '^https://.');
    }

    public function effects(): array
    {
        return [];
    }
}

<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Kind;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Format\Node;

use function preg_match;
use function sprintf;

/**
 * A URL with something after its scheme: `https://`, or where a store in
 * the same network may be spoken to in the clear, such as MinIO beside a CI
 * job, `http://` too.
 *
 * @implements Shape<non-empty-string>
 */
final readonly class Url implements Shape
{
    private function __construct(private string $pattern, private string $expected)
    {
    }

    public static function https(): self
    {
        return new self('^https://.', 'an https:// URL');
    }

    public static function web(): self
    {
        return new self('^https?://.', 'an http:// or https:// URL');
    }

    public function read(Node $at): Reading
    {
        $url = $at->kind() === Kind::Text ? $at->text() : '';

        return $url !== '' && preg_match(sprintf('#%s#', $this->pattern), $url) === 1
            ? Reading::of($url)
            : Reading::refused($at->mismatch($this->expected));
    }

    public function expected(): string
    {
        return $this->expected;
    }

    public function schema(): Json
    {
        return Json::object(Member::of('type', 'string'))->with(Member::of('pattern', $this->pattern));
    }

    public function effects(): array
    {
        return [];
    }
}

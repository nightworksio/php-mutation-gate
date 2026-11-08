<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use NightWorksIO\MutationGate\Core\Comment\Section;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Node;

/**
 * `comment.sections`: the comment's sections, each once, the verdict first (ADR-0102), since a reader and the
 * comment's lookup both take its heading for the comment.
 *
 * @internal
 *
 * @implements Shape<Listed<Section>>
 */
final readonly class VerdictFirst implements Shape
{
    private const string FIRST = 'a list of sections that starts with verdict';

    /** @param Items<Section> $sections */
    private function __construct(private Items $sections)
    {
    }

    public static function sections(): self
    {
        return new self(Items::distinctAtLeastOne(
            Enumerated::of(Section::cases()),
            static fn(Section $section): string => $section->value,
        ));
    }

    public function read(Node $at): Reading
    {
        $read = $this->sections->read($at);
        $sections = $read->value();
        $first = $sections instanceof Listed ? [...$sections] : [];

        return $first !== [] && $first[0] !== Section::Verdict ? Reading::refused($at->mismatch(self::FIRST)) : $read;
    }

    public function expected(): string
    {
        return self::FIRST;
    }

    public function schema(): Json
    {
        return $this->sections->schema();
    }

    public function effects(): array
    {
        return [];
    }
}

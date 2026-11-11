<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Reach;

use function array_map;
use function json_encode;

use const JSON_INVALID_UTF8_SUBSTITUTE;

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Format\JsonText;
use stdClass;

/**
 * What the gate asks `outbreak graph reach` (ADR-0132), as compact JSON: the
 * project's root, its sources, its test files and the files every test
 * loads, each path relative to the root; each file's text the question
 * gives; the files asked about and asked to be named; and the files the
 * coverage map shows each test file running. A byte that is not UTF-8 is
 * written as the replacement character, which names nothing.
 *
 * @internal
 */
final readonly class GraphAsked
{
    public static function of(GraphQuestion $question, string $root): string
    {
        return (string) json_encode([
            'root' => $root,
            'sources' => self::listed($question->sources()),
            'tests' => self::listed($question->tests()),
            'always' => self::listed($question->always()),
            'given' => self::object(array_map(static fn(Contents $text): string => $text->text(), $question->texts())),
            'reach' => self::listed($question->reaching()),
            'joined' => self::listed($question->joined()),
            'names' => self::listed($question->named()),
            'map' => self::object(array_map(self::listed(...), $question->map())),
        ], JsonText::FLAGS | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /** @return list<string> */
    private static function listed(Paths $paths): array
    {
        return array_map(static fn(Path $path): string => $path->value(), [...$paths]);
    }

    /**
     * @param array<string, mixed> $members
     *
     * @return array<string, mixed>|stdClass
     */
    private static function object(array $members): array|stdClass
    {
        return $members === [] ? new stdClass() : $members;
    }
}

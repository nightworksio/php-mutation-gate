<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use function array_map;

use NightWorksIO\MutationGate\Core\Verdict\FailingTests;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;

use function sprintf;

/**
 * Tests that fail without any mutant as text: the verdict's line naming them,
 * then each mutant it leaves out of every score, by its heading, under it
 * (ADR-0008); as plain lines, the same in the console and in a JUnit failure,
 * or as a Markdown list item with the mutants nested.
 *
 * @internal
 */
final readonly class FailingText
{
    /** A list item, after how deep it is indented. */
    private const string ITEM = '%s- %s';

    /** @return list<string> */
    public static function lines(FailingTests $failing): array
    {
        return [
            $failing->line(),
            ...array_map(
                static fn(JudgedMutant $mutant): string
                    => sprintf('%s%s', MutantText::INDENT, MutantText::heading($mutant)),
                [...$failing],
            ),
        ];
    }

    /** @return list<string> */
    public static function items(FailingTests $failing): array
    {
        return [
            sprintf(self::ITEM, '', Escape::text($failing->line())),
            ...array_map(
                static fn(JudgedMutant $mutant): string
                    => sprintf(self::ITEM, TestsText::INDENT, Escape::code(MutantText::heading($mutant))),
                [...$failing],
            ),
        ];
    }
}

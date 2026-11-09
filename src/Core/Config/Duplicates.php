<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

/** `mutants.duplicates`: whether mutants that are one program are judged once (ADR-0025, ADR-0137). */
enum Duplicates: string
{
    /** The first of each group is judged, and every other takes its judgement. */
    case JudgeOnce = 'judge-once';

    /** Every mutant is judged by its own run. */
    case JudgeEach = 'judge-each';

    /** The builder's call that sets it. */
    public function php(): string
    {
        return match ($this) {
            self::JudgeOnce => 'Mutants::judgingDuplicatesOnce()',
            self::JudgeEach => 'Mutants::judgingEach()',
        };
    }
}

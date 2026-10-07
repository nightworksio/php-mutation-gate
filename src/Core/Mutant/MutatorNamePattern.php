<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Mutant;

/**
 * How a registered mutator's name is written, `<set>/<Name>` (ADR-0021): its
 * set's name as a config writes it, and its own in the case a class is. Each
 * pattern is written without delimiters or anchors, so that PHP and a JSON
 * Schema's `pattern` read it alike.
 */
final readonly class MutatorNamePattern
{
    /** A set's name: lower case letters and digits, joined by single hyphens. */
    public const string SET = '[a-z][a-z0-9]*(?:-[a-z0-9]+)*';

    /** A mutator's own name: a letter in upper case, then letters and digits. */
    public const string OWN = '[A-Z][A-Za-z0-9]*';

    /**
     * A mutator's short name, the last part of the name any runner gives it:
     * a letter in upper case, then letters, digits and underscores, as
     * Infection's `Foreach_` is.
     */
    public const string SHORT = '[A-Z][A-Za-z0-9_]*';
}

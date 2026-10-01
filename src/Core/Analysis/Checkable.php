<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Analysis;

use NightWorksIO\MutationGate\Core\File\Contents;

/**
 * A mutant as a static analyser checks it (ADR-0020, decision 9): its text,
 * which the analyser reads in place of its file, and the original it is
 * judged against. That is the file as written, where the mutant's text is
 * the file changed where the mutation changed it, as Infection's and the
 * gate's own engine make it. Where the runner prints its mutants another
 * way, as Pest prints each one whole, it is the original printed the same
 * way, which must first analyse as the file itself does, or the mutant is
 * left unchecked.
 */
final readonly class Checkable
{
    private function __construct(private Contents $mutant, private Contents|AsWritten $original)
    {
    }

    /** A mutant judged against the file as written. */
    public static function inPlace(Contents $mutant): self
    {
        return new self($mutant, AsWritten::file());
    }

    /** A mutant judged against the original printed as the runner prints its mutants. */
    public static function printed(Contents $original, Contents $mutant): self
    {
        return new self($mutant, $original);
    }

    public function mutant(): Contents
    {
        return $this->mutant;
    }

    /** The original the mutant is judged against: printed as the runner prints, or the file as written. */
    public function original(): Contents|AsWritten
    {
        return $this->original;
    }
}

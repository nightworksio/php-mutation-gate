<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Delivery;

use NightWorksIO\MutationGate\Core\Comment\Mode;
use NightWorksIO\MutationGate\Core\Report\Markdown;

use function rtrim;
use function sprintf;

/**
 * How a comment is written into its thread (ADR-0009, ADR-0020, ADR-0102): the one comment updated in place, or this
 * run's own, found by the marker of its run and hiding the earlier ones where asked; over the planned state alone
 * where a re-check writes it; and only where a comment is already there where a passing run updates one.
 *
 * @internal
 */
final readonly class CommentWrite
{
    /** The marker a run's own comment is found by, where each run writes its own. */
    public const string RUN = '<!-- mutation-gate run: %s -->';

    private function __construct(
        private Mode $mode,
        private string $run,
        private bool $overPlanned,
        private bool $existingOnly,
    ) {
    }

    /** The one comment, updated in place. */
    public static function sticky(): self
    {
        return new self(Mode::Sticky, '', overPlanned: false, existingOnly: false);
    }

    /** As `comment.mode` says, for the run at this URL, which the one comment updated in place needs no marker of. */
    public static function of(Mode $mode, string $run): self
    {
        return new self($mode, $mode === Mode::Sticky ? '' : $run, overPlanned: false, existingOnly: false);
    }

    /** The same, written only over the comment's planned state. */
    public function overPlanned(): self
    {
        return new self($this->mode, $this->run, overPlanned: true, existingOnly: $this->existingOnly);
    }

    /** The same, written only where the gate has commented before, as a passing run under `comment.passing: update`. */
    public function existingOnly(): self
    {
        return new self($this->mode, $this->run, overPlanned: $this->overPlanned, existingOnly: true);
    }

    public function mode(): Mode
    {
        return $this->mode;
    }

    /** The URL of the run whose comment it is. */
    public function run(): string
    {
        return $this->run;
    }

    public function isOverPlanned(): bool
    {
        return $this->overPlanned;
    }

    public function isExistingOnly(): bool
    {
        return $this->existingOnly;
    }

    /** Whether writing this run's comment hides the gate's earlier ones. */
    public function hidesOld(): bool
    {
        return $this->mode === Mode::NewHidingOld;
    }

    /** The marker the comment it writes is found by: the gate's own, or its run's. */
    public function marker(): string
    {
        return $this->mode === Mode::Sticky ? Markdown::MARKER : sprintf(self::RUN, $this->run);
    }

    /** This markdown, holding the marker of its run where each run writes its own. */
    public function marked(string $markdown): string
    {
        return $this->mode === Mode::Sticky
            ? $markdown
            : sprintf("%s\n\n%s\n", rtrim($markdown, "\n"), $this->marker());
    }
}

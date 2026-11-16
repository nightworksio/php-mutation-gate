<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use Closure;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMapFile;
use NightWorksIO\MutationGate\Core\Coverage\KeptMap;
use NightWorksIO\MutationGate\Core\Coverage\MapLimits;
use NightWorksIO\MutationGate\Core\Coverage\MeasuredAt;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Written;

use function sprintf;

/**
 * A map carried from an earlier measure into the first round of `watch`
 * (ADR-0146): the map `coverage --into` wrote in a clean working tree, laid
 * where a round reads the map the round before left, so the first round is
 * quick over every file changed since the commit it was measured at.
 *
 * @internal
 */
final readonly class CarriedMap
{
    private const string UNREAD = 'The map at %s cannot be carried into the first round: %s';

    private const string UNPLACED = <<<'SAID'
        it does not say which commit it was measured at, or was measured in a dirty working tree
        SAID;

    private const string UNCHANGED = 'git cannot say what changed since the commit it was measured at: %s';

    public function __construct(private Adapters $adapters, private Settings $settings)
    {
    }

    /**
     * The quick round the first round of `watch` is over the map in this
     * directory; none where none is named, or, said, where it cannot be
     * carried.
     *
     * @param Closure(string): void $note
     */
    public function first(Path|NotGiven $directory, Closure $note): QuickRound|NotGiven
    {
        $carried = $directory instanceof Path ? $this->placed($directory) : $directory;

        if ($carried instanceof CannotJudge) {
            $note($carried->why());
        }

        return $carried instanceof QuickRound ? $carried : NotGiven::value();
    }

    /**
     * The quick round the first round is over the map in this directory, the
     * map now laid where the round reads it; or why the first round measures
     * every test.
     */
    public function placed(Path $directory): QuickRound|CannotJudge
    {
        $file = CoverageMapFile::in($directory);
        $bytes = $this->adapters->project->read($file);
        $limits = MapLimits::kept($this->settings->proofs()->retained());
        $kept = match (true) {
            $bytes instanceof Contents => CoverageMapFile::kept($bytes->text(), $limits),
            $bytes instanceof CannotJudge => $bytes,
            default => CoverageMapFile::missingAt($file->value()),
        };
        $at = $kept instanceof KeptMap ? $kept->measuredAt() : $kept;
        $changes = match (true) {
            $at instanceof CannotJudge => $at,
            ! $at instanceof MeasuredAt || $at->isDirty() => CannotJudge::because(self::UNPLACED),
            default => $this->changedFrom($at),
        };

        return $changes instanceof Changes
            ? $this->laid($bytes, $changes)
            : CannotJudge::because(sprintf(self::UNREAD, $directory->value(), $this->why($changes)));
    }

    private function changedFrom(MeasuredAt $at): Changes|CannotJudge
    {
        $changes = $this->adapters->changes->changesFrom($at->commit());

        return $changes instanceof Changes ? $changes : CannotJudge::because(sprintf(self::UNCHANGED, $changes->why()));
    }

    /** The quick round over these changes, the map's bytes laid where a round reads its map; or why they are not. */
    private function laid(Contents $bytes, Changes $changes): QuickRound|CannotJudge
    {
        $written = $this->adapters->project->write(CoverageMapFile::in(Workspace::coverage()), $bytes);

        return $written instanceof Written ? QuickRound::after($changes) : $written;
    }

    private function why(Changes|CannotJudge $changes): string
    {
        return $changes instanceof CannotJudge ? $changes->why() : self::UNPLACED;
    }
}

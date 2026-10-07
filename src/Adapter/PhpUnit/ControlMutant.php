<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpUnit;

use function file_get_contents;
use function is_file;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Control\Control;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Mutator\Engine\MadeMutant;

use function sprintf;

/**
 * An unmutated control as the PHPUnit runner runs it (ADR-0008, decision 2):
 * a mutant that changes nothing, its file as the project holds it, which the
 * override serves in the original's place as it serves a mutant's, so the
 * control's run and the mutant's differ in the mutation alone. Its id is made
 * from the control, so each control's files are its own.
 */
final readonly class ControlMutant
{
    /** What the mutant that changes nothing is made by. */
    private const string UNCHANGED = 'control';

    /** What its id is made from in place of a diff: the line a diff would add, the control's key hashed. */
    private const string KEYED = '+%s';

    /** The control's mutant, or why its file cannot be read. */
    public static function of(Project $project, Control $control): MadeMutant|CannotJudge
    {
        $file = $control->file();
        $absolute = $project->absolute($file);
        $text = is_file($absolute) ? file_get_contents($absolute) : false;
        $first = Line::of(1);

        return $text === false
            ? CannotJudge::because(sprintf(Control::UNREAD, $file->value()))
            : MadeMutant::of(
                MutantId::hash(
                    $file,
                    self::UNCHANGED,
                    sprintf(self::KEYED, Digest::sha256Of($control->key())->value()),
                    0,
                ),
                Location::of($file, $first, $first),
                Mutation::of(self::UNCHANGED, MutatorFamily::None, ''),
                Contents::of($text),
            );
    }
}

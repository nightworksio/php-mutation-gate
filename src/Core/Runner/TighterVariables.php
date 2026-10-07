<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use function explode;
use function implode;
use function is_numeric;
use function is_string;

use NightWorksIO\MutationGate\Core\Time\Seconds;

use function sprintf;

/**
 * How a process of a patched runner is told `timeouts.tighter`: its floor
 * and its mutators, each in a variable of its own.
 */
final readonly class TighterVariables
{
    /** What separates one mutator from the next where a process is told them. */
    private const string BETWEEN = ',';

    /**
     * What a process is told of these mutators, by the variables it reads;
     * nothing where none is listed.
     *
     * @return array<string, string>
     */
    public static function of(TighterSilence $tighter): array
    {
        $mutators = [...$tighter];

        return $mutators === [] ? [] : [
            ChildVariable::TighterFloor->value => sprintf('%F', $tighter->floor()->seconds()),
            ChildVariable::TighterMutators->value => implode(self::BETWEEN, $mutators),
        ];
    }

    /**
     * What a process was told, as it reads the variables: none where it was
     * told nothing, or a floor that is no positive number of seconds.
     */
    public static function read(string|false $floor, string|false $mutators): TighterSilence
    {
        $named = is_string($floor) && is_numeric($floor) && (float) $floor > 0.0
            && is_string($mutators) && $mutators !== '';

        return $named
            ? TighterSilence::of(Seconds::of((float) $floor), ...explode(self::BETWEEN, $mutators))
            : TighterSilence::none();
    }
}

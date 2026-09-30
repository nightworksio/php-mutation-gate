<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection\Import;

use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\TimeoutMode;
use NightWorksIO\MutationGate\Core\Config\Triage;
use NightWorksIO\MutationGate\Core\Format\Kind;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Import\Carried;
use NightWorksIO\MutationGate\Core\Import\Import;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function sprintf;

/** Infection's `timeout` and `timeoutsAsEscaped` as the gate's `timeouts` (ADR-0016, decision 1). */
final readonly class Timeouts
{
    private const string SECONDS = 'timeouts.seconds: %d';

    private const string NOT_SECONDS = '%s is not a whole number of seconds';

    private const string UNJUDGED = 'timeouts.mode: unjudged';

    private const string TRIAGED = 'the gate triages each timeout itself, where false counts it as killed';

    public static function of(Node $settings): Import
    {
        $limit = self::limit($settings->field(Mapped::Timeout->value));

        return $limit->and(self::mode($settings->field(Mapped::TimeoutsAsEscaped->value)));
    }

    private static function limit(Node $timeout): Import
    {
        if (! $timeout->isPresent()) {
            return Import::none();
        }

        return $timeout->kind() === Kind::Integer && $timeout->integer() > 0
            ? Import::of(
                Layer::of(Triage::of(limit: Seconds::of($timeout->integer()))),
                Carried::imported(Mapped::Timeout->value, sprintf(self::SECONDS, $timeout->integer())),
            )
            : Import::of(
                Layer::none(),
                Carried::dropped(Mapped::Timeout->value, sprintf(self::NOT_SECONDS, $timeout->json())),
            );
    }

    private static function mode(Node $asEscaped): Import
    {
        if (! $asEscaped->isPresent()) {
            return Import::none();
        }

        return $asEscaped->kind() === Kind::Boolean && $asEscaped->boolean()
            ? Import::of(
                Layer::of(Triage::of(mode: TimeoutMode::Unjudged)),
                Carried::imported(Mapped::TimeoutsAsEscaped->value, self::UNJUDGED),
            )
            : Import::of(Layer::none(), Carried::dropped(Mapped::TimeoutsAsEscaped->value, self::TRIAGED));
    }
}

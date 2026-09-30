<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use Closure;
use DateTimeImmutable;
use NightWorksIO\MutationGate\Core\Config\Definition\At;
use NightWorksIO\MutationGate\Core\Config\Definition\Fields;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Time\Day;

use function sprintf;

/** Equivalent mutants the config ignores (ADR-0008): `ignores.entries`, `ignores.maxDays`, `ignores.native`. */
final readonly class Ignores
{
    /** @param Listed<Ignored> $entries */
    public function __construct(private Listed $entries, private int|Absent $maxDays, private NativeMarkers $native)
    {
    }

    /**
     * The ignores a config's `ignores` object makes, or every entry that does not expire within `ignores.maxDays`
     * of now; with no instant, no expiry is judged.
     *
     * @return Closure(Fields, string): (self|Invalid)
     */
    public static function read(DateTimeImmutable|Absent $now): Closure
    {
        return static function (Fields $read, string $at) use ($now): self|Invalid {
            $ignores = new self(
                Listed::of($read->objects('entries', Ignored::class)),
                $read->has('maxDays') ? $read->int('maxDays') : Absent::setting(),
                $read->object('native', NativeMarkers::class),
            );
            $late = $ignores->late($now, $at);

            return $late === [] ? $ignores : Invalid::because(...$late);
        };
    }

    /** An `ignores.entries` entry: one mutant by its id, or a mutator in the paths a glob matches. */
    public static function entry(Fields $read, string $at): IgnoredMutant|IgnoredPattern|Invalid
    {
        $mutant = $read->optional('mutant', MutantId::class);
        $pattern = $read->has('path') || $read->has('mutator');
        $expires = $read->optional('expires', Day::class);

        return match (true) {
            $mutant instanceof MutantId && ! $pattern => IgnoredMutant::of($mutant, $read->string('reason'), $expires),
            $mutant instanceof Absent && $read->has('path') && $read->has('mutator') => IgnoredPattern::of(
                $read->string('path'),
                $read->string('mutator'),
                $read->string('reason'),
                $expires,
            ),
            default => Invalid::because(
                Problem::at($at, 'expected either mutant, or path and mutator, but not both'),
            ),
        };
    }

    /** @return Listed<Ignored> each an IgnoredMutant or an IgnoredPattern */
    public function entries(): Listed
    {
        return $this->entries;
    }

    /** How many days from a run every entry must expire within, or none, when entries may never expire. */
    public function maxDays(): int|Absent
    {
        return $this->maxDays;
    }

    public function native(): NativeMarkers
    {
        return $this->native;
    }

    /**
     * Every entry that does not expire within `maxDays` of now.
     *
     * @return list<Problem>
     */
    private function late(DateTimeImmutable|Absent $now, string $at): array
    {
        if ($this->maxDays instanceof Absent || $now instanceof Absent) {
            return [];
        }

        $latest = Day::on($now->modify(sprintf('+%d days', $this->maxDays)));
        $late = [];

        foreach ($this->entries as $index => $entry) {
            $expires = $entry->expires();

            if ($expires instanceof Absent || $latest->isBefore($expires)) {
                $late[] = Problem::at(
                    At::key(At::index(At::key($at, 'entries'), $index), 'expires'),
                    sprintf(
                        'expected a date by %s, within ignores.maxDays of today, got %s',
                        $latest->value(),
                        $expires instanceof Day ? sprintf('"%s"', $expires->value()) : 'nothing',
                    ),
                );
            }
        }

        return $late;
    }
}

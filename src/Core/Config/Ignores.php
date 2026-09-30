<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function array_map;

use DateTimeImmutable;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Time\Day;

use function sprintf;

/**
 * Equivalent mutants (ADR-0008, ADR-0013): those the config ignores,
 * `ignores.entries`, how long an ignore may last, `ignores.maxDays`, what the
 * runners' own markers do, `ignores.native`, and whether a mutant the
 * optimizer compiles as its original is proven equivalent,
 * `equivalence.static`.
 */
final readonly class Ignores implements Part
{
    /** @param Listed<Ignored>|Absent $entries */
    private function __construct(
        private Listed|Absent $entries,
        private int|Absent $maxDays,
        private NativeMarkers|Absent $native,
        private bool|Absent $staticEquivalence,
    ) {
    }

    /** @param Listed<Ignored>|Absent $entries */
    public static function of(
        Listed|Absent $entries = new Absent(),
        int|Absent $maxDays = new Absent(),
        NativeMarkers|Absent $native = new Absent(),
        bool|Absent $staticEquivalence = new Absent(),
    ): self {
        return new self($entries, $maxDays, $native, $staticEquivalence);
    }

    public static function none(): self
    {
        return self::of();
    }

    public static function standard(): self
    {
        $none = self::none();

        return self::of(
            entries: $none->entries(),
            native: $none->native(),
            staticEquivalence: $none->staticEquivalence(),
        );
    }

    public function over(Part $later): self
    {
        return $later instanceof self
            ? new self(
                match (true) {
                    $later->entries instanceof Absent => $this->entries,
                    $this->entries instanceof Absent => $later->entries,
                    default => $this->entries->and(
                        $later->entries,
                        static fn(Ignored $ignored): string => $ignored->written()->line(),
                    ),
                },
                Absent::laid($this->maxDays, $later->maxDays),
                Absent::laid($this->native, $later->native),
                Absent::laid($this->staticEquivalence, $later->staticEquivalence),
            )
            : $this;
    }

    /** @return Listed<Ignored> each an IgnoredMutant or an IgnoredPattern */
    public function entries(): Listed
    {
        return $this->entries instanceof Listed ? $this->entries : Listed::of();
    }

    /** How many days from a run every entry must expire within, or none, when entries may never expire. */
    public function maxDays(): int|Absent
    {
        return $this->maxDays;
    }

    public function native(): NativeMarkers
    {
        return $this->native instanceof NativeMarkers ? $this->native : NativeMarkers::Refuse;
    }

    /** `equivalence.static`: whether a mutant the optimizer compiles as its original is proven equivalent. */
    public function staticEquivalence(): bool
    {
        return $this->staticEquivalence instanceof Absent || $this->staticEquivalence;
    }

    /**
     * Every entry that does not expire within `maxDays` of now, at its path.
     *
     */
    public function late(DateTimeImmutable $now): Invalid|Absent
    {
        if ($this->maxDays instanceof Absent) {
            return Absent::setting();
        }

        $latest = Day::on($now->modify(sprintf('+%d days', $this->maxDays)));
        $late = [];

        foreach ($this->entries() as $index => $entry) {
            $expires = $entry->expires();

            if ($expires instanceof Absent || $latest->isBefore($expires)) {
                $late[] = Problem::at(
                    sprintf('ignores.entries[%d].expires', $index),
                    sprintf(
                        'expected a date by %s, within ignores.maxDays of today, got %s',
                        $latest->value(),
                        $expires instanceof Day ? sprintf('"%s"', $expires->value()) : 'nothing',
                    ),
                );
            }
        }

        return $late === [] ? Absent::setting() : Invalid::because(...$late);
    }

    public function written(Origin $origin): Json
    {
        $entries = $this->entries instanceof Listed ? Json::items(...array_map(
            static fn(Ignored $ignored): Json => $ignored->written(),
            [...$this->entries],
        )) : $this->entries;

        return Json::object(
            Member::unlessEmpty(
                'ignores',
                Json::object(
                    Member::of('entries', $entries),
                    Member::of('maxDays', $this->maxDays),
                    Member::of('native', $this->native instanceof NativeMarkers ? $this->native->value : $this->native),
                ),
            ),
            Member::unlessEmpty('equivalence', Json::object(Member::of('static', $this->staticEquivalence))),
        );
    }

    public function php(Origin $origin): PhpCalls
    {
        $entries = $this->entries instanceof Listed && [...$this->entries] !== []
            ? PhpCalls::onGate(
                'ignoring',
                ...array_map(static fn(Ignored $ignored): string => $ignored->php(), [
                    ...$this->entries,
                ]),
            )
            : PhpCalls::none();

        return $entries->and(PhpCalls::inWith(...[
            ...$this->maxDays instanceof Absent ? [] : [sprintf('Ignores::within(%d)', $this->maxDays)],
            ...$this->native instanceof NativeMarkers ? [match ($this->native) {
                NativeMarkers::Refuse => 'Ignores::refusingNativeMarkers()',
                NativeMarkers::Allow => 'Ignores::allowingNativeMarkers()',
            }] : [],
            ...$this->staticEquivalence instanceof Absent ? [] : [
                $this->staticEquivalence ? 'Equivalence::provenStatically()' : 'Equivalence::notProvenStatically()',
            ],
        ]));
    }
}

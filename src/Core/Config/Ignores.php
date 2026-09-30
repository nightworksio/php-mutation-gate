<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function array_map;

use DateTimeImmutable;
use NightWorksIO\MutationGate\Core\Config\Definition\Date;
use NightWorksIO\MutationGate\Core\Config\Definition\Enumerated;
use NightWorksIO\MutationGate\Core\Config\Definition\Field;
use NightWorksIO\MutationGate\Core\Config\Definition\Flag;
use NightWorksIO\MutationGate\Core\Config\Definition\Identifier;
use NightWorksIO\MutationGate\Core\Config\Definition\Integer;
use NightWorksIO\MutationGate\Core\Config\Definition\Items;
use NightWorksIO\MutationGate\Core\Config\Definition\Reading;
use NightWorksIO\MutationGate\Core\Config\Definition\Section;
use NightWorksIO\MutationGate\Core\Config\Definition\Text;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
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

    /** @return list<Field<Layer>> */
    public static function fields(): array
    {
        $judges = Effect::JudgesOrReportsOnly;
        $entries = Field::optional('entries', Items::of(self::entry()), $judges);
        $maxDays = Field::optional('maxDays', Integer::atLeast(1), $judges);
        $native = Field::optional('native', Enumerated::of(NativeMarkers::cases()), $judges);

        return [
            Field::section(
                'ignores',
                Section::of(
                    static function (Node $ignores) use ($entries, $maxDays, $native): Layer|Invalid {
                        $ignored = $entries->read($ignores);
                        $days = $maxDays->read($ignores);
                        $markers = $native->read($ignores);

                        return Reading::built(
                            static fn(): Layer => Layer::of(self::of(
                                $ignored->value(),
                                $days->value(),
                                $markers->value(),
                            )),
                            $ignored,
                            $days,
                            $markers,
                        );
                    },
                    $entries,
                    $maxDays,
                    $native,
                ),
            ),
            Field::section(
                'equivalence',
                Section::single(
                    Field::optional('static', Flag::boolean(), $judges),
                    static fn(bool|Absent $proven): Layer => Layer::of(self::of(staticEquivalence: $proven)),
                ),
            ),
        ];
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
        return $this->entries instanceof Listed ? $this->entries : Listed::of([]);
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
     * @return list<Problem>
     */
    public function late(DateTimeImmutable $now): array
    {
        if ($this->maxDays instanceof Absent) {
            return [];
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

        return $late;
    }

    public function written(Origin $origin): Json
    {
        $ignores = $this->entries instanceof Listed ? Json::object()->with(
            'entries',
            Json::items(array_map(
                static fn(Ignored $ignored): Json => $ignored->written(),
                [...$this->entries],
            )),
        ) : Json::object();
        $ignores = $this->maxDays instanceof Absent ? $ignores : $ignores->with('maxDays', $this->maxDays);
        $ignores = $this->native instanceof NativeMarkers ? $ignores->with('native', $this->native->value) : $ignores;
        $written = $ignores->isEmpty() ? Json::object() : Json::object()->with('ignores', $ignores);

        return $this->staticEquivalence instanceof Absent
            ? $written
            : $written->with('equivalence', Json::object()->with('static', $this->staticEquivalence));
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

    /**
     * An `ignores.entries` entry: one mutant by its id, or a mutator in the paths a glob matches.
     *
     * @return Section<Ignored>
     */
    private static function entry(): Section
    {
        $judges = Effect::JudgesOrReportsOnly;
        $mutant = Field::optional('mutant', Identifier::mutant(), $judges);
        $path = Field::optional('path', Text::of('a glob'), $judges);
        $mutator = Field::optional('mutator', Text::of('a mutator or a family of them'), $judges);
        $reason = Field::required('reason', Text::of('a reason'), $judges);
        $expires = Field::optional('expires', Date::written(), $judges);

        return Section::of(
            static function (Node $entry) use ($mutant, $path, $mutator, $reason, $expires): Ignored|Invalid {
                $id = $mutant->read($entry);
                $glob = $path->read($entry);
                $family = $mutator->read($entry);
                $why = $reason->read($entry);
                $until = $expires->read($entry);

                return Reading::built(
                    static fn(): Ignored|Invalid => self::ignored(
                        $entry,
                        $id->value(),
                        $glob->value(),
                        $family->value(),
                        $why->must(),
                        $until->value(),
                    ),
                    $id,
                    $glob,
                    $family,
                    $why,
                    $until,
                );
            },
            $mutant,
            $path,
            $mutator,
            $reason,
            $expires,
        )->oneOf([['mutant'], ['path', 'mutator']]);
    }

    private static function ignored(
        Node $entry,
        MutantId|Absent $mutant,
        string|Absent $path,
        string|Absent $mutator,
        string $reason,
        Day|Absent $expires,
    ): Ignored|Invalid {
        return match (true) {
            $mutant instanceof MutantId && $path instanceof Absent && $mutator instanceof Absent
                => IgnoredMutant::of($mutant, $reason, $expires),
            $mutant instanceof Absent && ! $path instanceof Absent && ! $mutator instanceof Absent
                => IgnoredPattern::of($path, $mutator, $reason, $expires),
            default => Invalid::because(
                Problem::at($entry->at(), 'expected either mutant, or path and mutator, but not both'),
            ),
        };
    }
}

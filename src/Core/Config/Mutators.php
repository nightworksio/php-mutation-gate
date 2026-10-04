<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function array_diff;
use function array_diff_key;
use function array_flip;
use function array_key_exists;
use function array_keys;
use function array_map;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\NotGiven;

use function sprintf;

/**
 * The registered mutators a run makes mutants with besides its runner's own
 * (ADR-0021): `mutators.sets`, the sets turned on by name, and
 * `mutators.except`, single mutators of theirs or of the default set turned
 * off by name, and under Pest or Infection only of theirs. Both lists grow
 * layer by layer, so a preset's sets stay on beside the config file's. They
 * are part of what a config builds on (see Setup).
 *
 * A set only presets turn on is offered rather than chosen: a run that finds
 * nothing registered under its name skips it, where a set the config file or
 * the command line names cannot be judged (ADR-0021, decision 12).
 */
final readonly class Mutators
{
    /**
     * @param Listed<string>|Absent    $sets
     * @param Listed<string>|Absent    $except
     * @param array<string, PresetSet> $offered each set only presets turn on, by its name
     */
    private function __construct(
        private Listed|Absent $sets,
        private Listed|Absent $except,
        private array $offered = [],
    ) {
    }

    /**
     * @param Listed<string>|Absent $sets   the names of the sets turned on
     * @param Listed<string>|Absent $except the names of the mutators turned off, each `<set>/<Name>`
     */
    public static function of(Listed|Absent $sets = new Absent(), Listed|Absent $except = new Absent()): self
    {
        return new self($sets, $except);
    }

    /** The sets a preset turns on, each offered; none, where it turns on none. */
    public static function offered(PresetSet ...$sets): self
    {
        $offered = [];

        foreach ($sets as $set) {
            $offered[$set->set()->value()] = $set;
        }

        return new self($offered === [] ? new Absent() : Listed::of(...array_keys($offered)), new Absent(), $offered);
    }

    public static function none(): self
    {
        return self::of();
    }

    public static function standard(): self
    {
        return self::of(Listed::of(), Listed::of());
    }

    /**
     * These, with a later layer's laid over them: each list grows, and a set
     * stays offered only where no layer chooses it.
     */
    public function over(self $later): self
    {
        return new self(
            $this->joined($this->sets, $later->sets),
            $this->joined($this->except, $later->except),
            [...$later->offeredBeside($this), ...$this->offeredBeside($later)],
        );
    }

    /** @return Listed<Name> `mutators.sets`: the sets turned on, in the order they were named */
    public function sets(): Listed
    {
        return Listed::of(...array_map(Name::of(...), $this->sets instanceof Listed ? [...$this->sets] : []));
    }

    /** The preset that offers a set, where only presets turn it on; nothing where a layer chooses it. */
    public function offering(Name $set): PresetSet|NotGiven
    {
        return array_key_exists($set->value(), $this->offered) ? $this->offered[$set->value()] : NotGiven::value();
    }

    /** @return Listed<string> `mutators.except`: the mutators turned off, each by its name, `<set>/<Name>` */
    public function except(): Listed
    {
        return $this->except instanceof Listed ? $this->except : Listed::of();
    }

    public function written(): Json
    {
        return Json::object(Member::unlessEmpty(
            'mutators',
            Json::object(
                Member::of('sets', $this->sets instanceof Listed ? Json::items(...$this->sets) : $this->sets),
                Member::of('except', $this->except instanceof Listed ? Json::items(...$this->except) : $this->except),
            ),
        ));
    }

    public function php(): PhpCalls
    {
        return PhpCalls::inWith(...[
            ...$this->sets instanceof Listed
                ? [sprintf('Mutators::sets(%s)', PhpCalls::literals(...$this->sets))]
                : [],
            ...$this->except instanceof Listed
                ? [sprintf('Mutators::except(%s)', PhpCalls::literals(...$this->except))]
                : [],
        ]);
    }

    /**
     * The sets these offer that the other layer does not choose.
     *
     * @return array<string, PresetSet>
     */
    private function offeredBeside(self $other): array
    {
        $chosen = $other->sets instanceof Listed ? array_diff([...$other->sets], array_keys($other->offered)) : [];

        return array_diff_key($this->offered, array_flip($chosen));
    }

    /**
     * @param  Listed<string>|Absent $earlier
     * @param  Listed<string>|Absent $later
     * @return Listed<string>|Absent
     */
    private function joined(Listed|Absent $earlier, Listed|Absent $later): Listed|Absent
    {
        return match (true) {
            $later instanceof Absent => $earlier,
            $earlier instanceof Absent => $later,
            default => $earlier->and($later, static fn(string $name): string => $name),
        };
    }
}

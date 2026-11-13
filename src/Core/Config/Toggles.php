<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function array_key_exists;

use NightWorksIO\MutationGate\Core\Format\Json;

/** Which steps of a run a layer turns on or off ({@see Toggle}), each where the layer sets it. */
final readonly class Toggles implements Part
{
    /** @param array<string, bool> $set each toggle's value, by its path, where the layer sets it */
    private function __construct(private array $set)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    public static function standard(): self
    {
        $set = [];

        foreach (Toggle::cases() as $toggle) {
            $set[$toggle->value] = true;
        }

        return new self($set);
    }

    /** A layer that sets this toggle so. */
    public static function of(Toggle $toggle, bool $on): self
    {
        return new self([$toggle->value => $on]);
    }

    /** This layer, also setting this toggle so where a value is read. */
    public function with(Toggle $toggle, bool|Absent $on): self
    {
        return $on instanceof Absent ? $this : new self([...$this->set, $toggle->value => $on]);
    }

    public function over(Part $later): self
    {
        return $later instanceof self ? new self([...$this->set, ...$later->set]) : $this;
    }

    /** Whether a step is on: as the layer sets it, or on. */
    public function on(Toggle $toggle): bool
    {
        return array_key_exists($toggle->value, $this->set) ? $this->set[$toggle->value] : true;
    }

    /** The runner a config chooses, with the toggles Pest reads beneath its options; any other runner as it is. */
    public function beneath(Choice $runner): Choice
    {
        if ($runner->use()->value() !== BuiltinRunner::Pest->value) {
            return $runner;
        }

        $options = Json::object();

        foreach (Toggle::cases() as $toggle) {
            $options = $toggle->isPests() ? $options->merged(Json::at($toggle->key(), $this->on($toggle))) : $options;
        }

        return Choice::of($runner->use()->value(), $runner->options()->over($options));
    }

    public function written(PathOrigin $origin): Json
    {
        $written = Json::object();

        foreach (Toggle::cases() as $toggle) {
            if (array_key_exists($toggle->value, $this->set)) {
                $written = $written->merged(Json::at($toggle->value, $this->set[$toggle->value]));
            }
        }

        return $written;
    }

    public function php(PathOrigin $origin): PhpCalls
    {
        $calls = [];

        foreach (Toggle::cases() as $toggle) {
            if (array_key_exists($toggle->value, $this->set)) {
                $calls[] = $toggle->php($this->set[$toggle->value]);
            }
        }

        return PhpCalls::inWith(...$calls);
    }
}

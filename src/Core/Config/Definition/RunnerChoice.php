<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use function is_array;
use function is_string;

use NightWorksIO\MutationGate\Core\Config\Choice;
use NightWorksIO\MutationGate\Core\Config\ChosenRunner;
use NightWorksIO\MutationGate\Core\Config\Effect;
use NightWorksIO\MutationGate\Core\Runner\Withheld;

/**
 * The `runner` key: an adapter chosen as any other is, and in its object
 * form also `withhold`, the environment variables a project adds to those the
 * runner never hands its tests (ADR-0004). A config file may write
 * `withhold` alone, for zero-config to find the runner it goes with.
 */
final readonly class RunnerChoice implements Node
{
    /** @param Section<Fields> $written */
    private function __construct(private Builtins $builtins, private Section $written)
    {
    }

    public static function choosing(Builtins $builtins): self
    {
        $judges = Effect::JudgesOrReportsOnly;

        return new self(
            $builtins,
            Section::fields(
                Field::required('use', Text::of('a name or a class'), $judges),
                Field::optional('with', OpenObject::any(), $judges),
                Field::setting('withhold', Items::of(Text::of('a variable name or a glob')), $judges, []),
            ),
        );
    }

    public function read(mixed $value, string $at): Reading
    {
        if (is_string($value) && $value !== '') {
            return $this->chosen($this->builtins->choose($value, [], $at), []);
        }

        if (! Json::isMap($value)) {
            return Reading::mismatch($at, $this->expected(), $value);
        }

        $written = $this->written->read($value, $at);
        $fields = $written->value();

        return $fields instanceof Fields ? $this->object($fields, $at) : $written;
    }

    public function expected(): string
    {
        return 'a name, a class, or an object with use and with';
    }

    public function schema(): array
    {
        $withhold = ['withhold' => Items::of(Text::of('a variable name or a glob'))->schema()];

        return ['anyOf' => [
            ['type' => 'string', 'minLength' => 1],
            ...$this->builtins->schemas($withhold, [], []),
            [
                'type' => 'object',
                'properties' => $withhold,
                'required' => ['withhold'],
                'additionalProperties' => false,
            ],
        ]];
    }

    public function effects(): array
    {
        return [...$this->builtins->effects(), '.withhold' => Effect::JudgesOrReportsOnly];
    }

    private function object(Fields $fields, string $at): Reading
    {
        $with = $fields->has('with') ? Json::decode($fields->string('with')) : [];

        return $this->chosen($this->builtins->choose($fields->string('use'), $with, $at), $fields->strings('withhold'));
    }

    /**
     * The runner chosen, shown by its name alone where nothing else is written, and affecting results as its
     * choice does; what it withholds only judges.
     *
     * @param list<string> $withhold
     */
    private function chosen(Reading $chosen, array $withhold): Reading
    {
        $choice = $chosen->value();

        if (! $choice instanceof Choice) {
            return $chosen;
        }

        $shown = $chosen->shown();

        return Reading::affecting(
            ChosenRunner::of($choice, Withheld::of(...$withhold)),
            $withhold === [] ? $shown : [...is_array($shown) ? $shown : ['use' => $shown], 'withhold' => $withhold],
            $shown,
        );
    }
}

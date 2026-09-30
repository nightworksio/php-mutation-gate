<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use NightWorksIO\MutationGate\Core\Config\Choice;
use NightWorksIO\MutationGate\Core\Config\Effect;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Kind;
use NightWorksIO\MutationGate\Core\Format\Node;

/**
 * A setting that chooses an adapter (ADR-0002): a name or a class alone, or
 * `{"use": …, "with": …}` with its options.
 *
 * @implements Shape<Choice>
 */
final readonly class Adapter implements Shape
{
    public const string EXPECTED = 'a name, a class, or an object with use and with';

    /** @param Section<Choice> $object */
    private function __construct(private Builtins $builtins, private Section $object)
    {
    }

    public static function choosing(Builtins $builtins): self
    {
        $judges = Effect::JudgesOrReportsOnly;
        $use = Field::required('use', Text::of('a name or a class'), $judges);
        $with = Field::optional('with', OpenObject::any(), $judges);

        return new self(
            $builtins,
            Section::of(
                static function (Node $at) use ($builtins, $use, $with): Choice|Invalid {
                    $named = $use->read($at);

                    return Reading::built(
                        static fn(): Choice|Invalid => self::chosen(
                            $builtins->choose($named->must(), $at->field('with')),
                        ),
                        $named,
                        $with->read($at),
                    );
                },
                $use,
                $with,
            ),
        );
    }

    /**
     * What a choice reads into: the chosen adapter, or every problem with its options.
     *
     * @param Reading<Choice> $chosen
     */
    public static function chosen(Reading $chosen): Choice|Invalid
    {
        $choice = $chosen->value();

        return $choice instanceof Choice ? $choice : Invalid::because(...$chosen->problems());
    }

    public function read(Node $at): Reading
    {
        return match ($at->kind()) {
            Kind::Text => $at->text() === ''
                ? Reading::refused($at->mismatch(self::EXPECTED))
                : $this->builtins->choose($at->text(), $at->field('with')),
            Kind::Map, Kind::Empty => $this->object->read($at),
            Kind::List, Kind::Integer, Kind::Number, Kind::Boolean, Kind::Null, Kind::Nothing => Reading::refused(
                $at->mismatch(self::EXPECTED),
            ),
        };
    }

    public function expected(): string
    {
        return self::EXPECTED;
    }

    public function schema(): Json
    {
        return Json::object()->with(
            'anyOf',
            Json::items([
                Json::object()->with('type', 'string')->with('minLength', 1),
                ...$this->builtins->schemas(Json::object(), [], []),
            ]),
        );
    }

    public function effects(): array
    {
        return $this->builtins->effects();
    }
}

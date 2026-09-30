<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\Config\Definition\Duration;
use NightWorksIO\MutationGate\Core\Config\Definition\Field;
use NightWorksIO\MutationGate\Core\Config\Definition\Reading;
use NightWorksIO\MutationGate\Core\Config\Definition\Section;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function sprintf;

/** How long the gate runs while a person works and before a push (ADR-0010): `local`. */
final readonly class Local implements Part
{
    private const int WATCH = 60;

    private const int PRE_PUSH = 300;

    private function __construct(private Seconds|Absent $watchBudget, private Seconds|Absent $prePushBudget)
    {
    }

    public static function of(
        Seconds|Absent $watchBudget = new Absent(),
        Seconds|Absent $prePushBudget = new Absent(),
    ): self {
        return new self($watchBudget, $prePushBudget);
    }

    public static function none(): self
    {
        return self::of();
    }

    public static function standard(): self
    {
        $none = self::none();

        return self::of($none->watchBudget(), $none->prePushBudget());
    }

    /** @return list<Field<Layer>> */
    public static function fields(): array
    {
        $judges = Effect::JudgesOrReportsOnly;
        $watch = Field::optional('watchBudget', Duration::written(), $judges);
        $prePush = Field::optional('prePushBudget', Duration::written(), $judges);

        return [Field::section(
            'local',
            Section::of(
                static function (Node $local) use ($watch, $prePush): Layer|Invalid {
                    $watching = $watch->read($local);
                    $pushing = $prePush->read($local);

                    return Reading::built(
                        static fn(): Layer => Layer::of(self::of($watching->value(), $pushing->value())),
                        $watching,
                        $pushing,
                    );
                },
                $watch,
                $prePush,
            ),
        )];
    }

    public function over(Part $later): self
    {
        return $later instanceof self
            ? new self(
                Absent::laid($this->watchBudget, $later->watchBudget),
                Absent::laid($this->prePushBudget, $later->prePushBudget),
            )
            : $this;
    }

    public function watchBudget(): Seconds
    {
        return $this->watchBudget instanceof Seconds ? $this->watchBudget : Seconds::of(self::WATCH);
    }

    public function prePushBudget(): Seconds
    {
        return $this->prePushBudget instanceof Seconds ? $this->prePushBudget : Seconds::of(self::PRE_PUSH);
    }

    public function written(Origin $origin): Json
    {
        $local = $this->watchBudget instanceof Seconds
            ? Json::object()->with('watchBudget', $this->watchBudget->written())
            : Json::object();
        $local = $this->prePushBudget instanceof Seconds
            ? $local->with('prePushBudget', $this->prePushBudget->written())
            : $local;

        return $local->isEmpty() ? $local : Json::object()->with('local', $local);
    }

    public function php(Origin $origin): PhpCalls
    {
        return PhpCalls::inWith(...[
            ...$this->watchBudget instanceof Seconds
                ? [sprintf('Local::watchBudget(%s)', PhpCalls::literal($this->watchBudget->written()))]
                : [],
            ...$this->prePushBudget instanceof Seconds
                ? [sprintf('Local::prePushBudget(%s)', PhpCalls::literal($this->prePushBudget->written()))]
                : [],
        ]);
    }
}

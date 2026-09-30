<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function sprintf;

/** How long the gate runs while a person works and before a push (ADR-0010): `local`. */
final readonly class Local implements Part
{
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
        return $this->watchBudget instanceof Seconds ? $this->watchBudget : Seconds::minutes(1);
    }

    public function prePushBudget(): Seconds
    {
        return $this->prePushBudget instanceof Seconds ? $this->prePushBudget : Seconds::of(self::PRE_PUSH);
    }

    public function written(PathOrigin $origin): Json
    {
        return Json::object(Member::unlessEmpty(
            'local',
            Json::object(
                Member::of(
                    'watchBudget',
                    $this->watchBudget instanceof Seconds ? $this->watchBudget->written() : $this->watchBudget,
                ),
                Member::of(
                    'prePushBudget',
                    $this->prePushBudget instanceof Seconds ? $this->prePushBudget->written() : $this->prePushBudget,
                ),
            ),
        ));
    }

    public function php(PathOrigin $origin): PhpCalls
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

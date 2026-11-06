<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function is_int;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Report\BadgeColors;
use NightWorksIO\MutationGate\Core\Report\Trend;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\ThisPackage;

use function sprintf;

/**
 * What a project publishes, and how an organisation's dashboard reads what
 * its projects published: `badge.colors`, the lowest score of each
 * shields.io colour, red below them all, and `badge.trendKept`, the newest
 * entries the trend keeps (ADR-0009); `dashboard.topic`, the
 * topic of the repositories the dashboard shows, and
 * `dashboard.repositories`, those it shows by name (ADR-0024).
 */
final readonly class Publishing implements Part
{
    /** The topic of the repositories a dashboard shows where the config names none: the gate's own name. */
    public const string TOPIC = ThisPackage::NAME;

    /** @param Listed<string>|Absent $repositories */
    private function __construct(
        private Table|Absent $colors,
        private string|Absent $topic,
        private Listed|Absent $repositories,
        private int|Absent $trendKept,
    ) {
    }

    /** @param Listed<string>|Absent $repositories */
    public static function of(
        Table|Absent $colors = new Absent(),
        string|Absent $topic = new Absent(),
        Listed|Absent $repositories = new Absent(),
        int|Absent $trendKept = new Absent(),
    ): self {
        return new self($colors, $topic, $repositories, $trendKept);
    }

    public static function none(): self
    {
        return self::of();
    }

    public static function standard(): self
    {
        return self::of(colors: self::none()->colors(), topic: self::TOPIC, trendKept: Trend::KEPT);
    }

    /**
     * A later layer's colours replace an earlier one's: they are bands of one scale, so a colour laid among
     * another layer's would cut the scale into bands neither wrote. A later topic or list of repositories replaces
     * an earlier one too.
     */
    public function over(Part $later): self
    {
        return $later instanceof self
            ? new self(
                Absent::laid($this->colors, $later->colors),
                Absent::laid($this->topic, $later->topic),
                Absent::laid($this->repositories, $later->repositories),
                Absent::laid($this->trendKept, $later->trendKept),
            )
            : $this;
    }

    /** `badge.colors`: the lowest score of each shields.io colour, red below them all. */
    public function colors(): Table
    {
        if ($this->colors instanceof Table) {
            return $this->colors;
        }

        $colors = Table::none();

        foreach (BadgeColors::DEFAULTS as $color => $lowest) {
            $colors = $colors->merged(Table::row($color, $lowest));
        }

        return $colors;
    }

    /** `badge.trendKept`: how many of the newest entries the trend keeps. */
    public function trendKept(): int
    {
        return is_int($this->trendKept) ? $this->trendKept : Trend::KEPT;
    }

    /**
     * A reporter's choice; the badge's with `badge.colors`, `badge.trendKept` and this floor for new code laid
     * beneath its options, so each it sets itself wins, its colours whole, as one scale.
     */
    public function under(Choice $choice, Floor $newCode): Choice
    {
        $own = $choice->options()->object(Key::of('colors'));

        return $choice->use()->value() === BuiltinReporter::Badge->value
            ? $choice->over(Json::object(
                Member::of('colors', $own instanceof NotGiven ? $this->colors()->written() : new Absent()),
                Member::of(Trend::KEPT_OPTION, $this->trendKept()),
                Member::of('newCode', $newCode->percent()),
            ))
            : $choice;
    }

    /** `dashboard.topic`: the topic of the repositories the dashboard shows. */
    public function topic(): string
    {
        return $this->topic instanceof Absent ? self::TOPIC : $this->topic;
    }

    /**
     * `dashboard.repositories`: those the dashboard shows by name, in place of a topic.
     *
     * @return Listed<string>|Absent
     */
    public function repositories(): Listed|Absent
    {
        return $this->repositories;
    }

    public function written(PathOrigin $origin): Json
    {
        return Json::object(
            Member::unlessEmpty(
                'badge',
                Json::object(
                    Member::of('colors', $this->colors instanceof Table ? $this->colors->written() : $this->colors),
                    Member::of(Trend::KEPT_OPTION, $this->trendKept),
                ),
            ),
            Member::unlessEmpty(
                'dashboard',
                Json::object(
                    Member::of('topic', $this->topic),
                    Member::of(
                        'repositories',
                        $this->repositories instanceof Listed
                            ? Json::items(...$this->repositories)
                            : $this->repositories,
                    ),
                ),
            ),
        );
    }

    public function php(PathOrigin $origin): PhpCalls
    {
        $calls = [];

        foreach ($this->colors instanceof Table ? $this->colors : [] as $colour => $lowest) {
            $calls[] = sprintf('Badge::colour(%s, %s)', PhpCalls::literal($colour), PhpCalls::literal($lowest));
        }

        if (is_int($this->trendKept)) {
            $calls[] = sprintf('Badge::trendKept(%d)', $this->trendKept);
        }

        $topic = $this->topic instanceof Absent
            ? []
            : [sprintf('Dashboard::topic(%s)', PhpCalls::literal($this->topic))];
        $repositories = $this->repositories instanceof Listed
            ? [sprintf('Dashboard::repositories(%s)', PhpCalls::literals(...$this->repositories))]
            : [];

        return PhpCalls::inWith(...$calls, ...$topic, ...$repositories);
    }
}

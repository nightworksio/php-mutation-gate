<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Effect;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\Publishing;
use NightWorksIO\MutationGate\Core\Config\Table;
use NightWorksIO\MutationGate\Core\Dashboard\RepositoryName;
use NightWorksIO\MutationGate\Core\Dashboard\Topic;
use NightWorksIO\MutationGate\Core\Format\Node;

use function sprintf;

/**
 * How the keys a config writes are read into its `Publishing` part (ADR-0002).
 *
 * @internal
 */
final readonly class PublishingKeys
{
    /** @return list<Field<Layer>> */
    public static function fields(): array
    {
        $reports = Effect::JudgesOrReportsOnly;
        $topic = Field::optional(
            'topic',
            Text::matching('a GitHub topic', sprintf('^%s$', Topic::PATTERN)),
            $reports,
        );
        $repositories = Field::optional(
            'repositories',
            Items::distinct(
                Text::matching('a repository name', sprintf('^%s$', RepositoryName::PATTERN)),
                static fn(string $name): string => $name,
            ),
            $reports,
        );

        return [
            Field::section(
                'badge',
                Section::single(
                    Field::optional('colors', NumberMap::of(Number::percent()), $reports),
                    static fn(Table|Absent $colors): Layer => Layer::of(Publishing::of(colors: $colors)),
                ),
            ),
            Field::section(
                'dashboard',
                Section::of(
                    static function (Node $dashboard) use ($topic, $repositories): Layer|Invalid {
                        $named = $topic->read($dashboard);
                        $listed = $repositories->read($dashboard);

                        return Reading::built(
                            static fn(): Layer => Layer::of(Publishing::of(
                                topic: $named->value(),
                                repositories: $listed->value(),
                            )),
                            $named,
                            $listed,
                        );
                    },
                    $topic,
                    $repositories,
                ),
            ),
        ];
    }
}

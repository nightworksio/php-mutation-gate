<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use NightWorksIO\MutationGate\Core\Config\Effect;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\PathOrigin;
use NightWorksIO\MutationGate\Core\Config\Publishing;
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
    public static function fields(PathOrigin $origin): array
    {
        $reports = Effect::JudgesOrReportsOnly;
        $colors = Field::optional('colors', NumberMap::of(Number::percent()), $reports);
        $logo = Field::optional('logo', FlagOrPath::of($origin), $reports);
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
                Section::of(
                    static function (Node $badge) use ($colors, $logo): Layer|Invalid {
                        $bands = $colors->read($badge);
                        $shown = $logo->read($badge);

                        return Reading::built(
                            static fn(): Layer => Layer::of(Publishing::of(
                                colors: $bands->value(),
                                logo: $shown->value(),
                            )),
                            $bands,
                            $shown,
                        );
                    },
                    $colors,
                    $logo,
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

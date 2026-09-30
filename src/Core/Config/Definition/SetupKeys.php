<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use NightWorksIO\MutationGate\Core\Config\Choice;
use NightWorksIO\MutationGate\Core\Config\Effect;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\Config\PathOrigin;
use NightWorksIO\MutationGate\Core\Config\Setup;

/** How the keys a config writes are read into its `Setup` part (ADR-0002). */
final readonly class SetupKeys
{
    /** What the `$schema` key of a config file is for. */
    private const string SCHEMA_KEY = 'The JSON Schema an editor checks this file by.';

    /** @return list<Field<Layer>> */
    public static function fields(PathOrigin $origin): array
    {
        $judges = Effect::JudgesOrReportsOnly;
        $results = Effect::AffectsResults;

        return [
            Field::optional(
                '$schema',
                Into::of(
                    Unchecked::describedAs(self::SCHEMA_KEY),
                    static fn(): Layer => Layer::none(),
                ),
                $judges,
            ),
            Field::optional(
                'extensions',
                Into::of(
                    Items::of(Text::of('a class name')),
                    static fn(Listed $classes): Layer => Layer::of(Setup::of(extensions: $classes)),
                ),
                $judges,
            ),
            Field::optional(
                'preset',
                Into::of(
                    Presets::named(),
                    static fn(Listed $names): Layer => Layer::of(Setup::of(presets: $names)),
                ),
                $judges,
            ),
            Field::optional(
                'runner',
                Into::of(
                    RunnerChoice::choosing(Builtins::runners()),
                    static fn(Setup $runner): Layer => Layer::of($runner),
                ),
                $results,
            ),
            Field::optional(
                'treeSource',
                Into::of(
                    Adapter::choosing(Builtins::treeSources($origin)),
                    static fn(Choice $source): Layer => Layer::of(Setup::of(treeSource: $source)),
                ),
                $results,
            ),
        ];
    }
}

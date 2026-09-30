<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use function array_flip;
use function array_key_exists;
use function array_keys;
use function array_map;

use NightWorksIO\MutationGate\Core\Analysis\BuiltInAnalyser;
use NightWorksIO\MutationGate\Core\Config\Choice;
use NightWorksIO\MutationGate\Core\Config\Effect;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Origin;
use NightWorksIO\MutationGate\Core\Config\StaticCheck;
use NightWorksIO\MutationGate\Core\File\Workspace;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Kind;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Format\Node;

use function sprintf;

/**
 * The adapters this package builds in for one setting, each with the
 * options it takes, checked strictly, where any other name or class takes
 * any options.
 */
final readonly class Builtins
{
    /** Why a chat reporter's options never hold its URL, and where it comes from instead (ADR-0016). */
    private const string CREDENTIAL
        = 'expected no url: a webhook URL is a credential; set %s, or name another variable in urlEnv';

    /** @param array<string, Section<Json>> $options the options of each built-in adapter, by its name */
    private function __construct(private array $options)
    {
    }

    /** @param array<string, Section<Json>> $options */
    public static function of(array $options): self
    {
        return new self($options);
    }

    public static function runners(): self
    {
        return self::none('pest', 'infection');
    }

    /** The analysers this package brings, and `auto` and `none`, none of which takes options. */
    public static function staticCheckers(): self
    {
        return self::none(
            StaticCheck::AUTO,
            StaticCheck::NONE,
            ...array_map(static fn(BuiltInAnalyser $analyser): string => $analyser->value, BuiltInAnalyser::cases()),
        );
    }

    /** The tree sources, whose paths are named from the layer's origin. */
    public static function treeSources(Origin $origin): self
    {
        return self::of([
            'phpunit' => Section::options(
                Json::object(Member::of('fallback', Json::items())),
                Field::optional('fallback', Items::of(Location::path($origin)), Effect::AffectsResults),
            ),
            'composer' => Section::options(Json::object()),
        ]);
    }

    /** The proof stores, whose paths are named from the layer's origin. */
    public static function stores(Origin $origin): self
    {
        $judges = Effect::JudgesOrReportsOnly;

        return self::of([
            'directory' => Section::options(
                Json::object(Member::of('path', Workspace::ledger()->value())),
                Field::optional('path', Location::path($origin), $judges),
            ),
            's3' => Section::options(
                Json::object(Member::of('prefix', 'mutation-gate'))->with(Member::of('region', 'us-east-1')),
                Field::required('bucket', Text::of('a bucket name'), $judges),
                Field::optional('prefix', Text::of('a key prefix'), $judges),
                Field::optional('region', Text::of('a region'), $judges),
                Field::optional('endpoint', Text::of('a URL'), $judges),
                Field::optional('publicUrl', Url::https(), $judges),
            ),
        ]);
    }

    public static function ciPlans(): self
    {
        return self::none('github', 'gitlab', 'buildkite', 'circleci', 'json');
    }

    public static function reporters(): self
    {
        $judges = Effect::JudgesOrReportsOnly;
        $variable = Text::of('an environment variable name');
        $chat = static fn(string $url, Json $defaults, Field ...$more): Section => Section::options(
            Json::object(Member::of('urlEnv', $url))->merged($defaults),
            Field::optional('urlEnv', $variable, $judges),
            Field::optional('url', Refused::because(sprintf(self::CREDENTIAL, $url)), $judges),
            ...$more,
        );

        return self::of([
            ...self::bare('json', 'junit', 'sarif', 'html', 'tests', 'kill-matrix', 'gitlab'),
            'slack' => $chat('MUTATION_GATE_SLACK_URL', Json::object()),
            'discord' => $chat('MUTATION_GATE_DISCORD_URL', Json::object()),
            'webhook' => $chat(
                'MUTATION_GATE_WEBHOOK_URL',
                Json::object(Member::of('secretEnv', 'MUTATION_GATE_WEBHOOK_SECRET')),
                Field::optional('secretEnv', $variable, $judges),
            ),
            'otlp' => Section::options(Json::object(), Field::optional('endpoint', Url::https(), $judges)),
        ]);
    }

    /**
     * The adapter a config chooses, with its options at `with`: a built-in one's checked and filled in with
     * their defaults, any other's kept as they are written.
     *
     * @return Reading<Choice>
     */
    public function choose(string $use, Node $with): Reading
    {
        if (array_key_exists($use, $this->options)) {
            $options = $this->options[$use]->read($with);
            $read = $options->value();

            return $read instanceof Json ? Reading::of(Choice::of($use, $read)) : Reading::invalid(
                Invalid::because(...$options->problems()),
            );
        }

        return match ($with->kind()) {
            Kind::Nothing, Kind::Empty => Reading::of(Choice::of($use, Json::object())),
            Kind::Map => Reading::of(Choice::of($use, $with->value())),
            Kind::List, Kind::Text, Kind::Integer, Kind::Number, Kind::Boolean, Kind::Null => Reading::refused(
                $with->mismatch('an object'),
            ),
        };
    }

    public function has(string $use): bool
    {
        return array_key_exists($use, $this->options);
    }


    /**
     * The JSON Schema of each way to choose one: every built-in adapter with its own options, and any other
     * name or class with any options.
     *
     * @param  list<string> $alsoRequired those of the other keys a built-in adapter needs
     * @param  list<string> $without      the built-in adapters that take none of the other keys
     * @return list<Json>
     */
    public function schemas(Json $also, array $alsoRequired, array $without): array
    {
        $schemas = [];
        $bare = array_flip($without);

        foreach ($this->options as $name => $options) {
            $own = array_key_exists($name, $bare);
            $properties = Json::object(Member::of('use', Json::object(Member::of('const', $name))));
            $properties = $own ? $properties : $properties->merged($also);
            $schemas[] = Json::object()
                ->with(Member::of('type', 'object'))
                ->with(Member::of('properties', $properties->with(Member::of('with', $options->schema()))))
                ->with(Member::of('required', Json::items('use', ...$own ? [] : $alsoRequired)))
                ->with(Member::of('additionalProperties', value: false));
        }

        $use = Json::object()
            ->with(Member::of('type', 'string'))
            ->with(Member::of('minLength', 1))
            ->with(Member::of('not', Json::object(Member::of('enum', Json::items(...array_keys($this->options))))));
        $schemas[] = Json::object()
            ->with(Member::of('type', 'object'))
            ->with(
                Member::of(
                    'properties',
                    Json::object()
                    ->with(Member::of('use', $use))
                    ->merged($also)
                    ->with(Member::of('with', Json::object(Member::of('type', 'object')))),
                ),
            )
            ->with(Member::of('required', Json::items('use')))
            ->with(Member::of('additionalProperties', value: false));

        return $schemas;
    }

    /** @return array<string, Effect> the options of every built-in adapter, by their path from the setting */
    public function effects(): array
    {
        $effects = [];

        foreach ($this->options as $options) {
            foreach ($options->effects() as $path => $effect) {
                $effects[sprintf('.with%s', $path)] = $effect;
            }
        }

        return $effects;
    }

    /** Built-in adapters that take no options. */
    private static function none(string ...$names): self
    {
        return self::of(self::bare(...$names));
    }

    /** @return array<string, Section<Json>> the options of adapters that take none, by name */
    private static function bare(string ...$names): array
    {
        return array_map(static fn(): Section => Section::options(Json::object()), array_flip($names));
    }
}

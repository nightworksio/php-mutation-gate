<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use function array_flip;
use function array_key_exists;
use function array_keys;

use BackedEnum;
use NightWorksIO\MutationGate\Core\Analysis\BuiltInAnalyser;
use NightWorksIO\MutationGate\Core\Config\BuiltinCiPlan;
use NightWorksIO\MutationGate\Core\Config\BuiltinReporter;
use NightWorksIO\MutationGate\Core\Config\BuiltinRunner;
use NightWorksIO\MutationGate\Core\Config\BuiltinStore;
use NightWorksIO\MutationGate\Core\Config\BuiltinTreeSource;
use NightWorksIO\MutationGate\Core\Config\Choice;
use NightWorksIO\MutationGate\Core\Config\Effect;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Config\PathOrigin;
use NightWorksIO\MutationGate\Core\Config\ProjectRoot;
use NightWorksIO\MutationGate\Core\Config\StaticCheck;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Workspace;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Kind;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Report\ProblemsShown;
use NightWorksIO\MutationGate\Core\ThisPackage;

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

    /**
     * @param array<string, Section<Options>> $options the options of each built-in adapter, by its name
     * @param PathOrigin                      $origin  where the layer is, which another adapter's paths are named from
     */
    private function __construct(private array $options, private PathOrigin $origin)
    {
    }

    /** @param array<string, Section<Options>> $options */
    public static function of(array $options, PathOrigin $origin): self
    {
        return new self($options, $origin);
    }

    public static function runners(PathOrigin $origin): self
    {
        return self::none($origin, ...BuiltinRunner::cases());
    }

    /** The analysers this package brings, and `auto` and `none`, none of which takes options. */
    public static function staticCheckers(PathOrigin $origin): self
    {
        return self::none($origin, StaticCheck::AUTO, StaticCheck::NONE, ...BuiltInAnalyser::cases());
    }

    /** The tree sources, whose paths are named from the layer's origin. */
    public static function treeSources(PathOrigin $origin): self
    {
        return self::of([
            BuiltinTreeSource::PhpUnit->value => Section::options(
                Json::object(Member::of('fallback', Json::items())),
                Field::optional(
                    'fallback',
                    Items::distinct(Location::path($origin), static fn(Path $path): string => $path->value()),
                    Effect::AffectsResults,
                ),
            ),
            BuiltinTreeSource::Composer->value => Section::options(Json::object()),
        ], $origin);
    }

    /** The proof stores, whose paths are named from the layer's origin. */
    public static function stores(PathOrigin $origin): self
    {
        $judges = Effect::JudgesOrReportsOnly;

        return self::of([
            BuiltinStore::Directory->value => Section::options(
                Json::object(Member::of('path', Workspace::ledger()->value())),
                Field::optional('path', Location::path($origin), $judges),
            ),
            BuiltinStore::S3->value => Section::options(
                Json::object(Member::of('prefix', ThisPackage::NAME))->with(Member::of('region', 'us-east-1')),
                Field::required('bucket', Text::of('a bucket name'), $judges),
                Field::optional('prefix', Text::of('a key prefix'), $judges),
                Field::optional('region', Text::of('a region'), $judges),
                Field::optional('endpoint', Url::web(), $judges),
                Field::optional('publicUrl', Url::https(), $judges),
            ),
        ], $origin);
    }

    public static function ciPlans(PathOrigin $origin): self
    {
        return self::none($origin, ...BuiltinCiPlan::cases());
    }

    public static function reporters(PathOrigin $origin): self
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
            ...self::bare(
                BuiltinReporter::Console,
                BuiltinReporter::GitHubAnnotations,
                BuiltinReporter::GitHubSummary,
                BuiltinReporter::Json,
                BuiltinReporter::JUnit,
                BuiltinReporter::Sarif,
                BuiltinReporter::Html,
                BuiltinReporter::Tests,
                BuiltinReporter::KillMatrix,
                BuiltinReporter::GitLab,
            ),
            BuiltinReporter::Slack->value => $chat('MUTATION_GATE_SLACK_URL', Json::object()),
            BuiltinReporter::Discord->value => $chat('MUTATION_GATE_DISCORD_URL', Json::object()),
            BuiltinReporter::Webhook->value => $chat(
                'MUTATION_GATE_WEBHOOK_URL',
                Json::object(Member::of('secretEnv', 'MUTATION_GATE_WEBHOOK_SECRET')),
                Field::optional('secretEnv', $variable, $judges),
            ),
            BuiltinReporter::Otlp->value => Section::options(
                Json::object(),
                Field::optional('endpoint', Url::https(), $judges),
            ),
            BuiltinReporter::Problems->value => Section::options(
                Json::object(),
                Field::optional('only', Enumerated::of(ProblemsShown::cases()), $judges),
            ),
            BuiltinReporter::GitHubComment->value => Section::options(
                Json::object(),
                Field::optional('identity', Text::of('an account name'), $judges),
            ),
            BuiltinReporter::Badge->value => Section::options(
                Json::object(),
                Field::optional('colors', NumberMap::of(Number::percent()), $judges),
                Field::optional('commit', Text::of('a commit'), $judges),
            ),
        ], $origin);
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

            return $read instanceof Options ? Reading::of(Choice::of($use, $this->reaching($read))) : Reading::invalid(
                Invalid::because(...$options->problems()),
            );
        }

        return match ($with->kind()) {
            Kind::Nothing, Kind::Empty => Reading::of(Choice::of($use, Options::at(Json::object(), $this->origin))),
            Kind::Map => Reading::of(Choice::of($use, Options::at($with->value(), $this->origin))),
            Kind::List, Kind::Text, Kind::Integer, Kind::Number, Kind::Boolean, Kind::Null => Reading::refused(
                $with->mismatch('an object'),
            ),
        };
    }

    /** A built-in adapter, with none of its options written: each takes its default. */
    public function standard(string $use): Choice
    {
        return $this->choose($use, Node::config(Json::object()->line())->field('with'))->must();
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
     * @param  list<string> $unrequired   the built-in adapters that take the other keys, but need none
     * @return list<Json>
     */
    public function schemas(Json $also, array $alsoRequired, array $without, array $unrequired): array
    {
        $schemas = [];
        $bare = array_flip($without);
        $optional = array_flip($unrequired);

        foreach ($this->options as $name => $options) {
            $own = array_key_exists($name, $bare);
            $needs = $own || array_key_exists($name, $optional) ? [] : $alsoRequired;
            $properties = Json::object(Member::of('use', Json::object(Member::of('const', $name))));
            $properties = $own ? $properties : $properties->merged($also);
            $schemas[] = Json::object()
                ->with(Member::of('type', 'object'))
                ->with(Member::of('properties', $properties->with(Member::of('with', $options->schema()))))
                ->with(Member::of('required', Json::items('use', ...$needs)))
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

    /**
     * Options a built-in adapter's section read, whose paths it named from the project: from the project still,
     * and reaching outside it where the layer that chose it may name such a path, as the command line may.
     */
    private function reaching(Options $options): Options
    {
        return $this->origin->reachesOutside()
            ? Options::at($options->written(), ProjectRoot::commandLine())
            : $options;
    }

    /** Built-in adapters that take no options, each by its name or by the case that holds it. */
    private static function none(PathOrigin $origin, BackedEnum|string ...$names): self
    {
        return self::of(self::bare(...$names), $origin);
    }

    /** @return array<string, Section<Options>> the options of adapters that take none, by name */
    private static function bare(BackedEnum|string ...$names): array
    {
        $bare = [];

        foreach ($names as $name) {
            $bare[sprintf('%s', $name instanceof BackedEnum ? $name->value : $name)] = Section::options(Json::object());
        }

        return $bare;
    }
}

<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use DateTimeImmutable;
use NightWorksIO\MutationGate\Core\Config\Definition\Adapter;
use NightWorksIO\MutationGate\Core\Config\Definition\Builtins;
use NightWorksIO\MutationGate\Core\Config\Definition\Date;
use NightWorksIO\MutationGate\Core\Config\Definition\Duration;
use NightWorksIO\MutationGate\Core\Config\Definition\Enumerated;
use NightWorksIO\MutationGate\Core\Config\Definition\Field;
use NightWorksIO\MutationGate\Core\Config\Definition\Fields;
use NightWorksIO\MutationGate\Core\Config\Definition\Flag;
use NightWorksIO\MutationGate\Core\Config\Definition\Identifier;
use NightWorksIO\MutationGate\Core\Config\Definition\Integer;
use NightWorksIO\MutationGate\Core\Config\Definition\Items;
use NightWorksIO\MutationGate\Core\Config\Definition\Json;
use NightWorksIO\MutationGate\Core\Config\Definition\Location;
use NightWorksIO\MutationGate\Core\Config\Definition\Number;
use NightWorksIO\MutationGate\Core\Config\Definition\NumberMap;
use NightWorksIO\MutationGate\Core\Config\Definition\OpenObject;
use NightWorksIO\MutationGate\Core\Config\Definition\Percent;
use NightWorksIO\MutationGate\Core\Config\Definition\Presets;
use NightWorksIO\MutationGate\Core\Config\Definition\Refused;
use NightWorksIO\MutationGate\Core\Config\Definition\ReportEntry;
use NightWorksIO\MutationGate\Core\Config\Definition\RunnerChoice;
use NightWorksIO\MutationGate\Core\Config\Definition\Section;
use NightWorksIO\MutationGate\Core\Config\Definition\Text;
use NightWorksIO\MutationGate\Core\Config\Definition\Unchecked;
use NightWorksIO\MutationGate\Core\Config\Definition\Url;
use NightWorksIO\MutationGate\Core\Cost\SecondsPerLine;
use NightWorksIO\MutationGate\Core\Hold\HotPaths;

use function sprintf;

/**
 * Every setting of the config, with its type, its default and what it can
 * change. The validator reads a config through this definition, and the
 * JSON Schema is written from it, so the two cannot drift (ADR-0002).
 */
final readonly class Definition
{
    private const string SCHEMA = 'https://json-schema.org/draft/2020-12/schema';

    /** The built-in reporters that send rather than write a file, so take no `path` (ADR-0016). */
    private const array WRITING_NO_FILE = ['slack', 'discord', 'webhook', 'otlp'];

    /** Why a chat reporter's options never hold its URL, and where it comes from instead (ADR-0016). */
    private const string CREDENTIAL
        = 'expected no url: a webhook URL is a credential; set %s, or name another variable in urlEnv';

    /** What the `$schema` key of a config file is for. */
    private const string SCHEMA_KEY = 'The JSON Schema an editor checks this file by.';

    private const string PUBLISHED
        = 'https://raw.githubusercontent.com/nightworksio/php-mutation-gate/v1/resources/mutation-gate.schema.json';

    /** The floor new code is held to (ADR-0003). */
    private const int NEW_CODE_FLOOR = 100;

    /** The seconds of work one shard is cut to, and the most shards a plan cuts (ADR-0006). */
    private const int SHARD_SECONDS = 600;

    private const int MOST_SHARDS = 20;

    /** The seconds a mutant may run, and how many timed-out mutants are run again (ADR-0008). */
    private const int TIMEOUT_SECONDS = 10;

    private const int TIMEOUT_RETRIES = 20;

    /** The lowest score of each badge colour (ADR-0009). */
    private const array BADGE_COLORS = ['brightgreen' => 90, 'green' => 80, 'yellow' => 70, 'orange' => 60];

    /** The widest a percentage goes. */
    private const int WHOLE = 100;

    /**
     * @param  DateTimeImmutable|Absent $now the instant `ignores.maxDays` counts from, or none, to describe a config
     * @return Section<Fields>
     */
    public static function config(DateTimeImmutable|Absent $now): Section
    {
        $results = Effect::AffectsResults;
        $judges = Effect::JudgesOrReportsOnly;

        return Section::fields(
            Field::optional('$schema', Unchecked::describedAs(self::SCHEMA_KEY), $judges),
            Field::setting('extensions', Items::of(Text::of('a class name')), $judges, []),
            Field::optional('preset', Presets::named(), $judges),
            Field::found('runner', RunnerChoice::choosing(self::runners()), $results),
            Field::setting('treeSource', Adapter::choosing(self::treeSources()), $results, 'phpunit'),
            Field::entries(
                'trees',
                Items::of(
                    Section::of(
                        DeclaredTree::read(...),
                        Field::required('path', Location::path(), $results),
                        Field::optional('floor', Percent::floor(), $judges),
                        Field::optional('reason', Text::of('a reason'), $judges),
                        Field::setting('exclude', Items::of(Text::of('a glob')), $results, []),
                    ),
                ),
            ),
            Field::section(
                'newCode',
                Section::fields(
                    Field::setting('floor', Percent::floor(), $judges, self::NEW_CODE_FLOOR),
                ),
            ),
            Field::setting(
                'uncovered',
                Enumerated::of(UncoveredMutants::cases()),
                $judges,
                UncoveredMutants::Count->value,
            ),
            Field::section(
                'baseline',
                Section::fields(
                    Field::setting('path', Location::path(), $judges, 'mutation-gate.baseline.json'),
                    Field::setting(
                        'improvement',
                        Enumerated::of(Improvement::cases()),
                        $judges,
                        Improvement::Require->value,
                    ),
                ),
            ),
            Field::setting('packages', Items::of(Text::of('a glob')), $results, []),
            Field::section(
                'reach',
                Section::fields(
                    Field::setting('everything', Items::of(Text::of('a glob')), $judges, []),
                ),
            ),
            Field::section(
                'holds',
                Section::fields(
                    Field::setting('hotPath', Number::between(0, 1), $judges, HotPaths::standard()->share()),
                ),
            ),
            Field::section(
                'shards',
                Section::fields(
                    Field::setting('seconds', Integer::atLeast(1), $judges, self::SHARD_SECONDS),
                    Field::setting('max', Integer::atLeast(1), $judges, self::MOST_SHARDS),
                    Field::optional('target', Duration::written(), $judges),
                    Field::setting('setup', Duration::written(), $judges, '1m'),
                )->atMostOne(['seconds', 'target']),
            ),
            Field::section(
                'costs',
                Section::fields(
                    Field::setting(
                        'secondsPerLine',
                        NumberMap::of(Number::atLeast(0)),
                        $judges,
                        SecondsPerLine::standard()->written(),
                    ),
                    Field::optional(
                        'perRunnerMinute',
                        Section::of(
                            Price::read(...),
                            Field::required('amount', Number::atLeast(0), $judges),
                            Field::required('currency', Text::of('a currency, such as EUR'), $judges),
                        ),
                        $judges,
                    ),
                ),
            ),
            Field::section('ci', self::ci()),
            Field::section(
                'proofs',
                Section::fields(
                    Field::setting('store', Adapter::choosing(self::stores()), $judges, 'directory'),
                    Field::setting('ignore', Items::of(Text::of('a glob')), $judges, []),
                    Field::setting('write', Enumerated::of(ProofWriting::cases()), $judges, ProofWriting::Auto->value),
                ),
            ),
            Field::optional('budget', Duration::written(), $judges),
            Field::section(
                'timeouts',
                Section::fields(
                    Field::setting('mode', Enumerated::of(TimeoutMode::cases()), $judges, TimeoutMode::Confirm->value),
                    Field::setting('seconds', Integer::atLeast(1), $results, self::TIMEOUT_SECONDS),
                    Field::setting('retries', Integer::atLeast(0), $results, self::TIMEOUT_RETRIES),
                ),
            ),
            Field::section(
                'flaky',
                Section::fields(
                    Field::setting('confirmSurvivors', Flag::boolean(), $results, default: true),
                ),
            ),
            Field::section(
                'tests',
                Section::fields(
                    Field::setting(
                        'order',
                        Enumerated::of(TestOrder::cases()),
                        $results,
                        TestOrder::KillersFirst->value,
                    ),
                ),
            ),
            Field::section('ignores', self::ignores($now)),
            Field::section(
                'equivalence',
                Section::fields(Field::setting('static', Flag::boolean(), $judges, default: true)),
            ),
            Field::setting(
                'reports',
                Items::of(ReportEntry::choosing(self::reporters(), self::WRITING_NO_FILE)),
                $judges,
                [],
            ),
            Field::section(
                'badge',
                Section::fields(
                    Field::setting(
                        'colors',
                        NumberMap::of(Number::between(0, self::WHOLE)),
                        $judges,
                        self::BADGE_COLORS,
                    ),
                ),
            ),
            Field::section(
                'pest',
                Section::fields(
                    Field::setting('patch', Flag::boolean(), $results, default: false),
                    Field::setting('canary', Text::of('a group name'), $results, 'mutation-canary'),
                ),
            ),
            Field::section(
                'local',
                Section::fields(
                    Field::setting('watchBudget', Duration::written(), $judges, '60s'),
                    Field::setting('prePushBudget', Duration::written(), $judges, '5m'),
                ),
            ),
        );
    }

    /** The JSON Schema (draft 2020-12) of a config, as `config:schema` prints it and the package ships it. */
    public static function schema(): string
    {
        return Json::pretty([
            '$schema' => self::SCHEMA,
            '$id' => self::PUBLISHED,
            'title' => 'mutation-gate',
            'description' => 'The config of nightworksio/mutation-gate: mutation-gate.json, .yaml, .yml or .neon.',
            ...self::config(Absent::setting())->schema(),
        ]);
    }

    /** @return array<string, Effect> every setting, by its path, with what it can change */
    public static function effects(): array
    {
        return self::config(Absent::setting())->settings();
    }

    /** @return Section<Fields> */
    private static function ci(): Section
    {
        $judges = Effect::JudgesOrReportsOnly;

        return Section::fields(
            Field::optional('plan', Adapter::choosing(self::ciPlans()), $judges),
            Field::optional('defaultBranch', Text::of('a branch name'), $judges),
            Field::setting('check', Text::of('a check-run name'), $judges, 'mutation / verdict'),
            Field::section(
                'gitlab',
                Section::fields(
                    Field::setting('template', Location::path(), $judges, '.gitlab/mutation-gate.yml'),
                ),
            ),
            Field::section(
                'buildkite',
                Section::fields(
                    Field::setting('step', OpenObject::any(), $judges, []),
                    Field::setting('definition', Location::path(), $judges, '.buildkite/pipeline.yml'),
                ),
            ),
        );
    }

    /** @return Section<Ignores> */
    private static function ignores(DateTimeImmutable|Absent $now): Section
    {
        $judges = Effect::JudgesOrReportsOnly;
        $entry = Section::of(
            Ignores::entry(...),
            Field::optional('mutant', Identifier::mutant(), $judges),
            Field::optional('path', Text::of('a glob'), $judges),
            Field::optional('mutator', Text::of('a mutator or a family of them'), $judges),
            Field::required('reason', Text::of('a reason'), $judges),
            Field::optional('expires', Date::written(), $judges),
        )->oneOf([['mutant'], ['path', 'mutator']]);

        return Section::of(
            Ignores::read($now),
            Field::setting('entries', Items::of($entry), $judges, []),
            Field::optional('maxDays', Integer::atLeast(1), $judges),
            Field::setting('native', Enumerated::of(NativeMarkers::cases()), $judges, NativeMarkers::Refuse->value),
        );
    }

    private static function runners(): Builtins
    {
        return self::none('pest', 'infection');
    }

    private static function treeSources(): Builtins
    {
        return Builtins::of([
            'phpunit' => Section::fields(
                Field::setting('fallback', Items::of(Location::path()), Effect::AffectsResults, []),
            ),
            'composer' => Section::fields(),
        ]);
    }

    private static function stores(): Builtins
    {
        $judges = Effect::JudgesOrReportsOnly;

        return Builtins::of([
            'directory' => Section::fields(Field::setting('path', Location::path(), $judges, '.mutation-gate/ledger')),
            's3' => Section::fields(
                Field::required('bucket', Text::of('a bucket name'), $judges),
                Field::setting('prefix', Text::of('a key prefix'), $judges, 'mutation-gate'),
                Field::setting('region', Text::of('a region'), $judges, 'us-east-1'),
                Field::optional('endpoint', Text::of('a URL'), $judges),
                Field::optional('publicUrl', Url::https(), $judges),
            ),
        ]);
    }

    private static function ciPlans(): Builtins
    {
        return self::none('github', 'gitlab', 'buildkite', 'circleci', 'json');
    }

    private static function reporters(): Builtins
    {
        $judges = Effect::JudgesOrReportsOnly;
        $files = self::bare('json', 'junit', 'sarif', 'html', 'tests', 'kill-matrix', 'gitlab');
        $chat = static fn(string $variable, Field ...$more): Section => Section::fields(
            Field::setting('urlEnv', Text::of('an environment variable name'), $judges, $variable),
            Field::optional('url', Refused::because(sprintf(self::CREDENTIAL, $variable)), $judges),
            ...$more,
        );

        return Builtins::of([
            ...$files,
            'slack' => $chat('MUTATION_GATE_SLACK_URL'),
            'discord' => $chat('MUTATION_GATE_DISCORD_URL'),
            'webhook' => $chat(
                'MUTATION_GATE_WEBHOOK_URL',
                Field::setting(
                    'secretEnv',
                    Text::of('an environment variable name'),
                    $judges,
                    'MUTATION_GATE_WEBHOOK_SECRET',
                ),
            ),
            'otlp' => Section::fields(Field::optional('endpoint', Url::https(), $judges)),
        ]);
    }

    /** Built-in adapters that take no options. */
    private static function none(string ...$names): Builtins
    {
        return Builtins::of(self::bare(...$names));
    }

    /** @return array<string, Section<Fields>> the options of adapters that take none, by name */
    private static function bare(string ...$names): array
    {
        $options = [];

        foreach ($names as $name) {
            $options[$name] = Section::fields();
        }

        return $options;
    }
}

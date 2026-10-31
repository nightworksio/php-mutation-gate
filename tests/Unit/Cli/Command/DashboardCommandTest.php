<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Cli\Command\DashboardCommand;
use NightWorksIO\MutationGate\Cli\Config\Detected;
use NightWorksIO\MutationGate\Cli\Config\Effective;
use NightWorksIO\MutationGate\Cli\Config\Registered;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Registry\Origin;
use NightWorksIO\MutationGate\Extension\Extensions;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\Dashboards;
use NightWorksIO\MutationGate\Tests\Support\Decoded;
use NightWorksIO\MutationGate\Tests\Support\GitHubAnswering;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\StoppedClock;
use NightWorksIO\MutationGate\Tests\Support\Tree;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\Response\MockResponse;

afterEach(function (): void {
    Scratch::sweep();
});

/**
 * `dashboard` run in a project with this config, against GitHub answering
 * these, with these options: its exit code and what it printed.
 *
 * @param  array<string, MockResponse> $answers
 * @param  array<string, mixed>        $options
 * @return array{int, string}
 */
function dashboarded(string $config, array $answers, array $options): array
{
    $project = Scratch::directory();

    if ($config !== '') {
        Scratch::write($project, 'mutation-gate.json', $config);
    }

    $tester = new CommandTester(DashboardCommand::command(
        new Effective(
            $project,
            Registered::config(Extensions::of(Origin::of('nightworksio/mutation-gate')), static fn(): bool => true),
            new Detected(Directory::at($project), Directory::at(sprintf('%s/vendor', $project))),
            new DateTimeImmutable(Configs::NOW),
        ),
        Variables::of(['GITHUB_TOKEN' => 'secret', 'GITHUB_REPOSITORY' => 'acme/dashboard']),
        GitHubAnswering::client($answers),
        new StoppedClock('2026-10-01T06:00:00Z'),
    ));
    $code = $tester->execute($options);

    return [$code, $tester->getDisplay()];
}

/** @return array<string, MockResponse> */
function acmePublished(): array
{
    return [
        Dashboards::page(1) => Dashboards::json([
            Dashboards::repository('api', ['mutation-gate']),
            Dashboards::repository('vault', ['mutation-gate'], 'private'),
            Dashboards::repository('blog', []),
        ]),
        Dashboards::published('api', 'summary.json') => new MockResponse(Dashboards::summary()),
        Dashboards::published('api', 'trend.json') => new MockResponse(Dashboards::trend()),
        Dashboards::published('vault', 'summary.json') => new MockResponse(Dashboards::summary()),
    ];
}

it('writes the page and dashboard.json of the public repositories with the topic, and says so', function (): void {
    $out = sprintf('%s/site', Scratch::directory());
    [$code, $said] = dashboarded('', acmePublished(), ['--org' => 'acme', '--out' => $out]);
    $json = (string) file_get_contents(sprintf('%s/dashboard.json', $out));
    $page = (string) file_get_contents(sprintf('%s/index.html', $out));

    expect($code)->toBe(0)
        ->and($said)->toBe(sprintf("Wrote %s: 1 repository.\n", $out))
        ->and(Decoded::at($json, 'organisation'))->toBe('acme')
        ->and(Decoded::at($json, 'time'))->toBe('2026-10-01T06:00:00Z')
        ->and(Decoded::at($json, 'repositories', 0, 'name'))->toBe('api')
        ->and(Decoded::at($json, 'repositories', 1))->toBeNull()
        ->and($page)->toContain('<h1>Mutation testing across acme</h1>')
        ->and($page)->toContain((string) file_get_contents(Tree::at('resources/dashboard-page/page.css')))
        ->and($page)->toContain((string) file_get_contents(Tree::at('resources/dashboard-page/page.js')));
});

it('shows the repositories the config names in place of a topic', function (): void {
    $out = sprintf('%s/site', Scratch::directory());
    [$code, $said] = dashboarded('{"dashboard": {"repositories": ["api"]}}', [
        '/repos/acme/api' => Dashboards::json(Dashboards::repository('api', [])),
        Dashboards::published('api', 'summary.json') => new MockResponse(Dashboards::summary()),
    ], ['--org' => 'acme', '--out' => $out]);

    expect($code)->toBe(0)
        ->and($said)->toBe(sprintf("Wrote %s: 1 repository.\n", $out));
});

it('shows private repositories too where asked, onto a private Pages site', function (): void {
    $out = sprintf('%s/site', Scratch::directory());
    [$code, $said] = dashboarded('', [...acmePublished(), '/repos/acme/dashboard/pages' => new MockResponse('{"public": false}')], [
        '--org' => 'acme',
        '--out' => $out,
        '--include-private' => true,
    ]);

    expect($code)->toBe(0)
        ->and($said)->toBe(sprintf("Wrote %s: 2 repositories.\n", $out))
        ->and(Decoded::at((string) file_get_contents(sprintf('%s/dashboard.json', $out)), 'repositories', 1, 'private'))->toBeTrue();
});

it('refuses to show private repositories on a site GitHub does not say is private', function (): void {
    $out = sprintf('%s/site', Scratch::directory());
    [$code, $said] = dashboarded('', [...acmePublished(), '/repos/acme/dashboard/pages' => new MockResponse('{"public": true}')], [
        '--org' => 'acme',
        '--out' => $out,
        '--include-private' => true,
    ]);

    expect($code)->toBe(2)
        ->and($said)->toContain('--include-private is for a private Pages site, and GitHub does not say the site of acme/dashboard is private.')
        ->and(is_dir($out))->toBeFalse();
});

it('exits 2, writing nothing, where it was not told where, the config cannot be read, or GitHub cannot list the repositories', function (string $config, string $org, bool $out, string $said): void {
    $site = sprintf('%s/site', Scratch::directory());
    [$code, $printed] = dashboarded($config, [], [
        ...$org === '' ? [] : ['--org' => $org],
        '--out' => $out ? $site : '',
    ]);

    expect($code)->toBe(2)
        ->and($printed)->toContain($said)
        ->and(is_dir($site))->toBeFalse();
})->with([
    'no organisation' => ['', '', true, 'dashboard builds a site for --org=<organisation> into --out=<directory>.'],
    'no directory' => ['', 'acme', false, 'dashboard builds a site for --org=<organisation> into --out=<directory>.'],
    'a topic GitHub allows none of' => ['{"dashboard": {"topic": "Not A Topic"}}', 'acme', true, 'dashboard.topic'],
    'no answer from GitHub' => ['', 'acme', true, 'GitHub cannot list the repositories of acme: '],
]);

it('exits 2, writing nothing, where the organisation it is told is blank', function (): void {
    $site = sprintf('%s/site', Scratch::directory());
    [$code, $printed] = dashboarded('', [], ['--org' => '', '--out' => $site]);

    expect($code)->toBe(2)
        ->and($printed)->toContain('dashboard builds a site for --org=<organisation> into --out=<directory>.')
        ->and(is_dir($site))->toBeFalse();
});

it('says why it could not write the site', function (): void {
    $root = Scratch::directory();
    Scratch::write($root, 'site/dashboard.json/in-the-way', '');
    [$code, $said] = dashboarded('', acmePublished(), ['--org' => 'acme', '--out' => sprintf('%s/site', $root)]);

    expect($code)->toBe(2)
        ->and($said)->toContain(sprintf('%s/site/dashboard.json could not be written.', $root));
});

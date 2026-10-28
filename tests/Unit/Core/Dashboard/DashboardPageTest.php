<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Dashboard\Dashboard;
use NightWorksIO\MutationGate\Core\Dashboard\DashboardPage;
use NightWorksIO\MutationGate\Core\Dashboard\Listing;
use NightWorksIO\MutationGate\Core\Dashboard\Published;
use NightWorksIO\MutationGate\Core\Report\Trend;
use NightWorksIO\MutationGate\Core\Report\TrendSvg;
use NightWorksIO\MutationGate\Tests\Support\Dashboards;
use NightWorksIO\MutationGate\Tests\Support\Moment;

$page = static fn(Listing ...$listings): string => DashboardPage::html(
    Dashboard::of('acme', Moment::at('2026-10-01T06:00:00Z'), ...$listings),
    'body{margin:0}',
    'console.log(1)',
);

$published = static fn(string $name, string $url = 'https://github.com/acme/api', bool $private = false): Listing => Listing::of(
    $name,
    $url,
    private: $private,
    published: Published::read(Dashboards::summary()),
    trend: Trend::decode(Dashboards::trend()),
);

it('heads the page with the organisation, says how many repositories as of when, and holds its style and script inline', function () use ($page, $published): void {
    $one = $page($published('api'));
    $two = $page($published('api'), $published('web'));

    expect($one)->toContain('<title>Mutation testing across acme</title>')
        ->and($one)->toContain('<h1>Mutation testing across acme</h1>')
        ->and($one)->toContain('<p class="lead">1 repository, as of 2026-10-01T06:00:00Z.</p>')
        ->and($two)->toContain('<p class="lead">2 repositories, as of 2026-10-01T06:00:00Z.</p>')
        ->and($one)->toContain('<style>body{margin:0}</style>')
        ->and($one)->toContain('<script>console.log(1)</script>')
        ->and($one)->toContain(
            '<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; style-src \'sha256-IAdwN3biDCQ3brrgp1m8kBEsPQYyqfREKOEfeoREopc=\'; script-src \'sha256-CihokcEcBW4atb/CW/XWsvWwbTjqwQlE9nj9ii5ww5M=\'">',
        )
        ->and($one)->not->toContain('src=')
        ->and($one)->not->toContain('<link');
});

it('writes a row of each repository: its verdict, score, trend, trees below their floors, savings and latest verdict', function () use ($page, $published): void {
    expect($page($published('api')))->toContain(implode("\n", [
        '<tr>',
        '<th scope="row" data-value="api"><a href="https://github.com/acme/api">api</a></th>',
        '<td data-value="failed" class="verdict failed">failed</td>',
        '<td data-value="44.44">44.44%</td>',
        sprintf('<td>%s</td>', TrendSvg::of(Trend::decode(Dashboards::trend()))),
        '<td data-value="1"><ul><li><code>src</code> 44.44%, below 80.00%</li></ul></td>',
        '<td data-value="147600">41h</td>',
        '<td data-value="2026-09-30T10:00:00Z">2026-09-30T10:00:00Z</td>',
        '</tr>',
    ]));
});

it('says a repository that has no tree below its floor, no score and no history says so', function () use ($page): void {
    $quiet = Listing::of('api', 'https://github.com/acme/api', private: false, published: Published::read('{"format": 1, "commit": "a", "time": "2026-09-30T10:00:00Z", "verdict": "passed", "trees": [], "newCode": {"floor": 100}}'), trend: Trend::none());

    expect($page($quiet))->toContain('<td data-value="passed" class="verdict passed">passed</td>')
        ->and($page($quiet))->toContain('<td data-value="">nothing to mutate</td>')
        ->and($page($quiet))->toContain('<td data-value="0">none</td>')
        ->and($page($quiet))->toContain('<td data-value="">no history yet</td>');
});

it('lists each tree below its floor in the row', function () use ($page): void {
    $below = Listing::of('api', 'https://github.com/acme/api', private: false, published: Published::read('{"format": 1, "commit": "a", "time": "2026-09-30T10:00:00Z", "verdict": "failed", "score": 10, '
        . '"trees": [{"path": "src", "floor": 80, "score": 10}, {"path": "lib", "floor": 50, "score": 20}], "newCode": {"floor": 100}}'), trend: Trend::none());

    expect($page($below))->toContain('<td data-value="2"><ul><li><code>src</code> 10.00%, below 80.00%</li><li><code>lib</code> 20.00%, below 50.00%</li></ul></td>');
});

it('marks a private repository, links none GitHub gave no address over HTTPS for, and says why one published nothing', function () use ($page, $published): void {
    $unpublished = Listing::of('docs', 'https://github.com/acme/docs', private: false, published: CannotJudge::because('It has published no summary.json.'), trend: Trend::none());

    expect($page($published('vault', 'https://github.com/acme/vault', private: true)))->toContain('<a href="https://github.com/acme/vault">vault</a> <span class="private">private</span>')
        ->and($page($published('plain', 'http://example.test/plain')))->toContain('<th scope="row" data-value="plain">plain</th>')
        ->and($page($unpublished))->toContain(implode("\n", [
            '<tr class="unpublished">',
            '<th scope="row" data-value="docs"><a href="https://github.com/acme/docs">docs</a></th>',
            '<td colspan="6" data-value="">It has published no summary.json.</td>',
            '</tr>',
        ]));
});

it('lets nothing a repository published become markup', function () use ($page): void {
    $hostile = Listing::of(
        '<b>x</b>',
        'https://github.com/acme/"><script>alert(1)</script>',
        private: false,
        published: Published::read('{"format": 1, "commit": "a", "time": "2026-09-30T10:00:00Z", "verdict": "failed", "trees": [{"path": "<img src=x>", "floor": 90, "score": 10}], "newCode": {"floor": 100}}'),
        trend: Trend::none(),
    );
    $html = $page($hostile);

    expect($html)->not->toContain('<b>x</b>')
        ->and($html)->not->toContain('<script>alert(1)</script>')
        ->and($html)->not->toContain('<img src=x>')
        ->and($html)->toContain('&lt;img src=x&gt;');
});

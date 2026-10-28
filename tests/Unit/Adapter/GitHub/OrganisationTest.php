<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\GitHub\Api;
use NightWorksIO\MutationGate\Adapter\GitHub\Organisation;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\Dashboard\Listing;
use NightWorksIO\MutationGate\Core\Dashboard\Published;
use NightWorksIO\MutationGate\Tests\Support\Dashboards;
use NightWorksIO\MutationGate\Tests\Support\GitHubAnswering;
use Symfony\Component\HttpClient\Response\MockResponse;

/** @param array<string, MockResponse> $answers */
function acme(array $answers): Organisation
{
    return Organisation::of(Api::at(GitHubAnswering::client($answers), '', 'secret'), 'acme');
}

/**
 * Each listing's name, whether it is private, and its verdict or why it published nothing.
 *
 * @param  list<Listing>|CannotTell     $listings
 * @return list<array{string, bool, string}>
 */
function listed(array|CannotTell $listings): array
{
    return array_map(static function (Listing $listing): array {
        $published = $listing->published();

        return [$listing->name(), $listing->isPrivate(), $published instanceof Published ? $published->verdict()->value : $published->why()];
    }, is_array($listings) ? $listings : []);
}

it('lists every repository with the topic, page by page, with what each published on its branch', function (): void {
    $full = [];

    foreach (range(1, 100) as $at) {
        $full[] = Dashboards::repository(sprintf('other-%d', $at), ['php']);
    }

    $full[50] = Dashboards::repository('api', ['php', 'mutation-gate']);
    $listings = acme([
        Dashboards::page(1) => Dashboards::json($full),
        Dashboards::page(2) => Dashboards::json([Dashboards::repository('vault', ['mutation-gate'], 'private'), Dashboards::repository('blog', [])]),
        Dashboards::published('api', 'summary.json') => new MockResponse(Dashboards::summary()),
        Dashboards::published('api', 'trend.json') => new MockResponse(Dashboards::trend()),
        Dashboards::published('vault', 'summary.json') => new MockResponse(Dashboards::summary()),
    ])->listings('mutation-gate', new Absent());

    expect(listed($listings))->toBe([['api', false, 'failed'], ['vault', true, 'failed']])
        ->and(is_array($listings) ? $listings[0]->url() : '')->toBe('https://github.com/acme/api')
        ->and(is_array($listings) ? count([...$listings[0]->trend()->scores()]) : 0)->toBe(2)
        ->and(is_array($listings) ? count([...$listings[1]->trend()->scores()]) : 1)->toBe(0);
});

it('reads no page past one that holds fewer than a page can', function (): void {
    $listings = acme([
        Dashboards::page(1) => Dashboards::json([Dashboards::repository('api', ['mutation-gate'])]),
        Dashboards::page(2) => Dashboards::json([Dashboards::repository('web', ['mutation-gate'])]),
    ])->listings('mutation-gate', new Absent());

    expect(array_map(static fn(Listing $listing): string => $listing->name(), is_array($listings) ? $listings : []))->toBe(['api']);
});

it('reads the first 50 pages of repositories, and none past them', function (): void {
    $answers = [];

    foreach (range(1, 51) as $page) {
        $repositories = [];

        foreach (range(1, 100) as $at) {
            $repositories[] = Dashboards::repository(sprintf('other-%d-%d', $page, $at), []);
        }

        $repositories[0] = Dashboards::repository(sprintf('on-page-%d', $page), ['mutation-gate']);
        $answers[Dashboards::page($page)] = Dashboards::json($repositories);
    }

    $listings = acme($answers)->listings('mutation-gate', new Absent());
    $names = array_map(static fn(Listing $listing): string => $listing->name(), is_array($listings) ? $listings : []);

    expect($names)->toHaveCount(50)
        ->and($names)->toContain('on-page-50')
        ->and($names)->not->toContain('on-page-51');
});

it('names the organisation and each repository in GitHub\'s addresses as a path segment each', function (): void {
    $answers = [
        '/orgs/acme%2Flabs/repos?type=all&per_page=100&page=1' => Dashboards::json([Dashboards::repository('web/app', ['mutation-gate'])]),
        '/repos/acme%2Flabs/web%2Fapp' => Dashboards::json(Dashboards::repository('web/app', [])),
        '/repos/acme%2Flabs/web%2Fapp/contents/summary.json?ref=mutation-gate' => new MockResponse(Dashboards::summary()),
    ];
    $labs = Organisation::of(Api::at(GitHubAnswering::client($answers), '', 'secret'), 'acme/labs');

    expect(listed($labs->listings('mutation-gate', new Absent())))->toBe([['web/app', false, 'failed']])
        ->and(listed($labs->listings('mutation-gate', Listed::of('web/app'))))->toBe([['web/app', false, 'failed']]);
});

it('says why a repository published nothing it can read', function (): void {
    $listings = acme([
        Dashboards::page(1) => Dashboards::json([Dashboards::repository('api', ['mutation-gate'])]),
    ])->listings('mutation-gate', new Absent());
    $published = is_array($listings) ? $listings[0]->published() : null;

    expect($published)->toBeInstanceOf(CannotJudge::class)
        ->and($published instanceof CannotJudge ? $published->why() : '')->toStartWith('It has published no summary.json on its mutation-gate branch: ')
        ->and($published instanceof CannotJudge ? $published->why() : '')->toContain('404');
});

it('cannot list the repositories where GitHub does not answer', function (): void {
    expect(acme([])->listings('mutation-gate', new Absent()))->toBeInstanceOf(CannotTell::class)
        ->and(acme([Dashboards::page(1) => Dashboards::json([]), Dashboards::page(2) => new MockResponse('', ['http_code' => 500])])->listings('mutation-gate', new Absent()))->toBe([]);
});

it('lists the repositories named in place of a topic, and one GitHub does not show as private, with why', function (): void {
    $listings = acme([
        '/repos/acme/api' => Dashboards::json(Dashboards::repository('api', [])),
        Dashboards::published('api', 'summary.json') => new MockResponse(Dashboards::summary()),
    ])->listings('mutation-gate', Listed::of('api', 'gone'));
    $gone = is_array($listings) ? $listings[1]->published() : null;

    expect(listed($listings)[0])->toBe(['api', false, 'failed'])
        ->and(is_array($listings) ? [$listings[1]->name(), $listings[1]->isPrivate(), $listings[1]->url()] : [])->toBe(['gone', true, ''])
        ->and($gone instanceof CannotJudge ? $gone->why() : '')->toStartWith('GitHub does not show it: ');
});

it('reads a Pages site as private only where GitHub says it is not public', function (string $answer, bool $private): void {
    $pages = $answer === '' ? [] : ['/repos/acme/dashboard/pages' => new MockResponse($answer)];

    expect(acme($pages)->pagesArePrivate('acme/dashboard'))->toBe($private);
})->with([
    'not public' => ['{"public": false}', true],
    'public' => ['{"public": true}', false],
    'saying nothing of it' => ['{"html_url": "https://acme.github.io"}', false],
    'no site' => ['', false],
]);

it('reads from the branch the publish job writes, named for the gate', function (): void {
    expect(Organisation::PUBLISHED)->toBe('mutation-gate');
});

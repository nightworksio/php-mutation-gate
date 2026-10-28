<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Dashboard\Dashboard;
use NightWorksIO\MutationGate\Core\Dashboard\Listing;
use NightWorksIO\MutationGate\Core\Dashboard\Published;
use NightWorksIO\MutationGate\Core\Report\Trend;
use NightWorksIO\MutationGate\Tests\Support\Dashboards;
use NightWorksIO\MutationGate\Tests\Support\Decoded;
use NightWorksIO\MutationGate\Tests\Support\Moment;

$listing = static function (string $name, bool $private = false): Listing {
    $published = Published::read(Dashboards::summary());

    return Listing::of($name, sprintf('https://github.com/acme/%s', $name), private: $private, published: $published, trend: Trend::decode(Dashboards::trend()));
};

it('lists its repositories by name, as of an instant, for an organisation', function () use ($listing): void {
    $dashboard = Dashboard::of('acme', Moment::at('2026-10-01T06:00:00Z'), $listing('web'), $listing('api'), $listing('Billing'));

    expect(array_map(static fn(Listing $one): string => $one->name(), $dashboard->listings()))->toBe(['Billing', 'api', 'web'])
        ->and($dashboard->organisation())->toBe('acme')
        ->and($dashboard->time()->value())->toBe('2026-10-01T06:00:00Z');
});

it('writes dashboard.json: each repository\'s verdict, score, trees below their floors and savings, and why one published nothing', function () use ($listing): void {
    $unpublished = Listing::of('docs', '', private: true, published: CannotJudge::because('It has published nothing.'), trend: Trend::none());
    $json = Dashboard::of('acme', Moment::at('2026-10-01T06:00:00Z'), $listing('api'), $unpublished)->json();

    expect(Decoded::at($json, 'format'))->toBe(1)
        ->and(Decoded::at($json, 'organisation'))->toBe('acme')
        ->and(Decoded::at($json, 'time'))->toBe('2026-10-01T06:00:00Z')
        ->and(Decoded::at($json, 'repositories', 0))->toBe([
            'name' => 'api',
            'url' => 'https://github.com/acme/api',
            'private' => false,
            'commit' => '3f9a1c2',
            'time' => '2026-09-30T10:00:00Z',
            'verdict' => 'failed',
            'score' => 44.44,
            'belowFloor' => [['path' => 'src', 'floor' => 80.0, 'score' => 44.44]],
            'savedLast30Days' => 147_600.0,
        ])
        ->and(Decoded::at($json, 'repositories', 1))->toBe([
            'name' => 'docs',
            'url' => '',
            'private' => true,
            'unpublished' => 'It has published nothing.',
        ]);
});

it('leaves out the score of a project with nothing to mutate, and the savings of one with no history', function (): void {
    $published = Published::read('{"format": 1, "commit": "a", "time": "2026-09-30T10:00:00Z", "verdict": "passed", "trees": [], "newCode": {"floor": 100}}');
    $json = Dashboard::of('acme', Moment::at('2026-10-01T06:00:00Z'), Listing::of('api', '', private: false, published: $published, trend: Trend::none()))->json();

    expect(Decoded::at($json, 'repositories', 0))->toBe([
        'name' => 'api',
        'url' => '',
        'private' => false,
        'commit' => 'a',
        'time' => '2026-09-30T10:00:00Z',
        'verdict' => 'passed',
        'belowFloor' => [],
    ]);
});

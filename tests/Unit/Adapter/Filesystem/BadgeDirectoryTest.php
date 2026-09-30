<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\BadgeDirectory;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Cost\NoHistory;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Report\Badge;
use NightWorksIO\MutationGate\Core\Report\BadgeColors;
use NightWorksIO\MutationGate\Core\Report\Trend;
use NightWorksIO\MutationGate\Core\Report\TrendSvg;
use NightWorksIO\MutationGate\Core\Score\Score;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Extension\Options;
use NightWorksIO\MutationGate\Tests\Support\Environment;
use NightWorksIO\MutationGate\Tests\Support\Moment;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\StoppedClock;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

afterEach(function (): void {
    Scratch::sweep();
});

$clock = static fn(): StoppedClock => new StoppedClock('2026-09-30T12:00:00Z');

it('writes the badge, and the trend with this verdict appended to the one it finds', function () use ($clock): void {
    $publish = sprintf('%s/publish', Scratch::directory());
    $earlier = Trend::none()->with(Verdicts::failing(), Revision::ref('before'), Moment::at('2026-09-29T12:00:00Z'));
    Scratch::write($publish, 'trend.json', $earlier->json());
    $answer = BadgeDirectory::at($publish, BadgeColors::defaults(), 'abc123', $clock())->report(Verdicts::passing());
    $trend = $earlier->with(Verdicts::passing(), Revision::ref('abc123'), Moment::at('2026-09-30T12:00:00Z'));

    expect($answer)->toEqual(Written::to($publish))
        ->and(file_get_contents(sprintf('%s/badge.json', $publish)))->toBe(Badge::json(Score::ofHundredths(10_000), BadgeColors::defaults()))
        ->and(file_get_contents(sprintf('%s/trend.json', $publish)))->toBe($trend->json())
        ->and(file_get_contents(sprintf('%s/trend.svg', $publish)))->toBe(TrendSvg::of($trend));
});

it('starts a trend where it finds none', function () use ($clock): void {
    $publish = sprintf('%s/publish', Scratch::directory());
    BadgeDirectory::at($publish, BadgeColors::defaults(), 'abc123', $clock())->report(Verdicts::failing());

    expect(iterator_to_array(Trend::decode((string) file_get_contents(sprintf('%s/trend.json', $publish)))->scores(), preserve_keys: false))->toBe([37.5]);
});

it('updates neither the badge nor the trend for a run its budget cut short', function () use ($clock): void {
    $publish = sprintf('%s/publish', Scratch::directory());

    expect(BadgeDirectory::at($publish, BadgeColors::defaults(), 'abc123', $clock())->report(Verdicts::passing()->cutShort()))
        ->toEqual(NotWritten::because('A run its budget cut short updates neither the badge nor the trend.'))
        ->and(is_dir($publish))->toBeFalse();
});

it('says why it could not write', function (string $blocked) use ($clock): void {
    $root = Scratch::directory();
    Scratch::write($root, sprintf('publish/%s/in-the-way', $blocked), '');

    expect(BadgeDirectory::at(sprintf('%s/publish', $root), BadgeColors::defaults(), 'abc123', $clock())->report(Verdicts::passing()))
        ->toEqual(NotWritten::because(sprintf('%s/publish/%s could not be written.', $root, $blocked)));
})->with(['badge.json', 'trend.json', 'trend.svg', 'savings.json']);

it('reads its directory, its colours and its commit from its options', function () use ($clock): void {
    $options = Options::ofJson('{"path": "out", "colors": {"blue": 50}, "commit": "def456"}');

    expect(BadgeDirectory::configured($options, $clock()))->toEqual(BadgeDirectory::at('out', BadgeColors::of(['blue' => 50]), 'def456', $clock()));
});

it('publishes to .mutation-gate/publish with the default colours and the commit the CI names', function () use ($clock): void {
    $read = static fn(): BadgeDirectory|Invalid => BadgeDirectory::configured(Options::none(), $clock());

    expect(Environment::during(['GITHUB_SHA' => '0123abc'], $read))
        ->toEqual(BadgeDirectory::at('.mutation-gate/publish', BadgeColors::defaults(), '0123abc', $clock()));
});

it('reads the commit of each CI it knows, and none elsewhere', function (string $variable) use ($clock): void {
    $none = ['GITHUB_SHA' => null, 'CI_COMMIT_SHA' => null, 'BUILDKITE_COMMIT' => null, 'CIRCLE_SHA1' => null];
    $set = $variable === '' ? $none : [...$none, $variable => 'fedcba9'];
    $read = static fn(): BadgeDirectory|Invalid => BadgeDirectory::configured(Options::ofJson('{"commit": 7}'), $clock());

    expect(Environment::during($set, $read))
        ->toEqual(BadgeDirectory::at('.mutation-gate/publish', BadgeColors::defaults(), $variable === '' ? '' : 'fedcba9', $clock()));
})->with(['GITHUB_SHA', 'CI_COMMIT_SHA', 'BUILDKITE_COMMIT', 'CIRCLE_SHA1', '']);

it('refuses colours that are not a map of scores, and a directory that is not text', function (string $options, string $at, string $message) use ($clock): void {
    expect(BadgeDirectory::configured(Options::ofJson($options), $clock()))->toEqual(Invalid::because(Problem::at($at, $message)));
})->with([
    'a colour as text' => ['{"colors": {"green": "high"}}', 'colors', 'Each badge colour maps to the lowest score that earns it.'],
    'colours as a number' => ['{"colors": 80}', 'colors', 'Each badge colour maps to the lowest score that earns it.'],
    'a directory as a number' => ['{"path": 3}', 'path', 'The badge and trend are written to a directory, as text.'],
]);

it('writes what the gate saved over the last 30 days beside the badge', function () use ($clock): void {
    $publish = sprintf('%s/publish', Scratch::directory());
    Scratch::write($publish, 'trend.json', '{"format": 1, "runs": [
        {"commit": "a", "time": "2026-08-01T00:00:00Z", "trees": {}, "runnerSeconds": 60, "fullRunSeconds": 99999},
        {"commit": "b", "time": "2026-09-10T00:00:00Z", "trees": {}, "runnerSeconds": 60, "fullRunSeconds": 3660}
    ]}');

    BadgeDirectory::at($publish, BadgeColors::defaults(), 'abc123', $clock())->report(Verdicts::named('accounted'));

    expect(file_get_contents(sprintf('%s/savings.json', $publish)))->toBe(Badge::savings(Seconds::of(8_820.0)));
});

it('says there is no history yet where no run knew what it saved', function () use ($clock): void {
    $publish = sprintf('%s/publish', Scratch::directory());

    BadgeDirectory::at($publish, BadgeColors::defaults(), 'abc123', $clock())->report(Verdicts::failing());

    expect(file_get_contents(sprintf('%s/savings.json', $publish)))->toBe(Badge::savings(NoHistory::yet()));
});

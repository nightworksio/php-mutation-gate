<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Format\JsonText;
use NightWorksIO\MutationGate\Core\Report\Summary;
use NightWorksIO\MutationGate\Core\Report\Trend;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function sprintf;

use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * An organisation, `acme`, as its dashboard reads it through GitHub's API:
 * its repositories and what each published on its `mutation-gate` branch.
 */
final class Dashboards
{
    /** What a repository published of the failing verdict, judged at the start of a day. */
    public static function summary(): string
    {
        return Summary::json(
            Verdicts::failing(),
            Revision::ref('3f9a1c2'),
            Moment::at('2026-09-30T10:00:00Z'),
            Floor::of(95),
            Seconds::of(147_600.0),
        );
    }

    /** The trend of the failing verdict, then the passing one. */
    public static function trend(): string
    {
        return Trend::none()
            ->with(Verdicts::failing(), Revision::ref('a'), Moment::at('2026-09-29T10:00:00Z'))
            ->with(Verdicts::passing(), Revision::ref('b'), Moment::at('2026-09-30T10:00:00Z'))
            ->json();
    }

    /**
     * A repository as GitHub lists it.
     *
     * @param  list<string>                              $topics
     * @return array{name: string, html_url: string, visibility: string, topics: list<string>}
     */
    public static function repository(string $name, array $topics, string $visibility = 'public'): array
    {
        return [
            'name' => $name,
            'html_url' => sprintf('https://github.com/acme/%s', $name),
            'visibility' => $visibility,
            'topics' => $topics,
        ];
    }

    /**
     * GitHub's answer of a JSON value.
     *
     * @param list<array<string, string|list<string>>>|array<string, string|list<string>> $data
     */
    public static function json(array $data): MockResponse
    {
        return new MockResponse(JsonText::compact($data));
    }

    /** The path a repository's published file is read at. */
    public static function published(string $repository, string $file): string
    {
        return sprintf('/repos/acme/%s/contents/%s?ref=mutation-gate', $repository, $file);
    }

    /** The path one page of the organisation's repositories is listed at. */
    public static function page(int $page): string
    {
        return sprintf('/orgs/acme/repos?type=all&per_page=100&page=%d', $page);
    }
}

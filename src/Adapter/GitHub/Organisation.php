<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\GitHub;

use function array_filter;
use function count;
use function in_array;
use function is_string;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\Dashboard\Listing;
use NightWorksIO\MutationGate\Core\Dashboard\Published;
use NightWorksIO\MutationGate\Core\Report\Summary;
use NightWorksIO\MutationGate\Core\Report\Trend;
use NightWorksIO\MutationGate\Core\ThisPackage;

use function rawurlencode;
use function sprintf;

/**
 * An organisation's repositories as its dashboard reads them through
 * GitHub's API (ADR-0024): those with a topic, or those it names, each with
 * what its default branch's verdict published on the branch the publish job
 * writes, `summary.json` and `trend.json`. A repository GitHub does not show
 * is listed as private, so a public dashboard leaves it out.
 */
final readonly class Organisation
{
    /** The branch the publish job writes a repository's badge, trend and summary to, named for the gate. */
    public const string PUBLISHED = ThisPackage::NAME;

    /** How many repositories GitHub lists on one page, at most. */
    private const int PER_PAGE = 100;

    /** The most pages of repositories it reads: 5,000 repositories. */
    private const int MOST_PAGES = 50;

    private const string UNLISTED = 'GitHub does not show it: %s';

    private const string UNPUBLISHED = 'It has published no summary.json on its %s branch: %s';

    private function __construct(private Api $api, private string $name)
    {
    }

    public static function of(Api $api, string $name): self
    {
        return new self($api, $name);
    }

    /**
     * Each repository with this topic, or each of these names in place of a
     * topic, with what it published; or why GitHub cannot list them.
     *
     * @param  Listed<string>|Absent $names
     * @return list<Listing>|CannotTell
     */
    public function listings(string $topic, Listed|Absent $names): array|CannotTell
    {
        if ($names instanceof Listed) {
            $listings = [];

            foreach ($names as $name) {
                $listings[] = $this->named($name);
            }

            return $listings;
        }

        $repositories = $this->withTopic($topic);

        if ($repositories instanceof CannotTell) {
            return $repositories;
        }

        $listings = [];

        foreach ($repositories as $repository) {
            $listings[] = $this->listed($repository);
        }

        return $listings;
    }

    /** Whether the Pages site of a repository is private: GitHub says it is not public. */
    public function pagesArePrivate(string $repository): bool
    {
        $pages = $this->api->get(sprintf('/repos/%s/pages', $repository));

        return $pages instanceof Answer && $pages->isFalse('public');
    }

    /**
     * Every repository of the organisation with the topic, page by page,
     * until a page holds fewer than a page can.
     *
     * @return list<Answer>|CannotTell
     */
    private function withTopic(string $topic): array|CannotTell
    {
        $found = [];
        $page = 0;

        do {
            ++$page;
            $repositories = $this->page($page);

            if ($repositories instanceof CannotTell) {
                return $repositories;
            }

            $found = [
                ...$found,
                ...array_filter($repositories, static fn(Answer $one): bool => self::hasTopic($one, $topic)),
            ];
        } while (count($repositories) === self::PER_PAGE && $page < self::MOST_PAGES);

        return $found;
    }

    /**
     * One page of the organisation's repositories.
     *
     * @return list<Answer>|CannotTell
     */
    private function page(int $page): array|CannotTell
    {
        $answer = $this->api->get(sprintf(
            '/orgs/%s/repos?type=all&per_page=%d&page=%d',
            rawurlencode($this->name),
            self::PER_PAGE,
            $page,
        ));

        return $answer instanceof Answer ? $answer->items() : $answer;
    }

    private function named(string $name): Listing
    {
        $repository = $this->api->get(sprintf('/repos/%s/%s', rawurlencode($this->name), rawurlencode($name)));

        return $repository instanceof Answer
            ? $this->listed($repository)
            : Listing::of(
                $name,
                '',
                private: true,
                published: CannotJudge::because(sprintf(self::UNLISTED, $repository->why())),
                trend: Trend::none(),
            );
    }

    private function listed(Answer $repository): Listing
    {
        $name = $repository->text('name');
        $summary = $this->file($name, Summary::FILE);
        $trend = $this->file($name, Trend::FILE);

        return Listing::of(
            $name,
            $repository->text('html_url'),
            private: $repository->text('visibility') !== 'public',
            published: is_string($summary) ? Published::read($summary) : $summary,
            trend: is_string($trend) ? Trend::decode($trend) : Trend::none(),
        );
    }

    /** What a file holds on a repository's published branch; or why it cannot be read. */
    private function file(string $repository, string $file): string|CannotJudge
    {
        $text = $this->api->raw(sprintf(
            '/repos/%s/%s/contents/%s?ref=%s',
            rawurlencode($this->name),
            rawurlencode($repository),
            $file,
            self::PUBLISHED,
        ));

        return $text instanceof CannotTell
            ? CannotJudge::because(sprintf(self::UNPUBLISHED, self::PUBLISHED, $text->why()))
            : $text;
    }

    private static function hasTopic(Answer $repository, string $topic): bool
    {
        return in_array($topic, $repository->texts('topics'), strict: true);
    }
}

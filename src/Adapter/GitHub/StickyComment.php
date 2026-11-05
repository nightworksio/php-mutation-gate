<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\GitHub;

use function array_filter;
use function array_values;
use function count;

use NightWorksIO\MutationGate\Core\Ci\PullRequestNumber;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Report\Markdown;

use function sprintf;
use function str_contains;

/**
 * The sticky comment on a pull request: the one comment carrying the hidden
 * marker that the commenting identity wrote, looked up page by page.
 *
 * @internal
 */
final readonly class StickyComment
{
    /** The identity `GITHUB_TOKEN` comments as, which cannot read `/user`. */
    public const string ACTIONS = 'github-actions[bot]';

    private const int PAGE = 100;

    /** The most pages of comments read to find the sticky one. */
    private const int PAGES = 30;

    private function __construct(private Api $api, private string $repository)
    {
    }

    /** The sticky comment of this repository's pull requests, asked through this API. */
    public static function in(Api $api, string $repository): self
    {
        return new self($api, $repository);
    }

    /** Who comments: this identity, or the token's own where it is left empty. */
    public function author(string $identity): string
    {
        return $identity === '' ? $this->identityOfToken() : $identity;
    }

    /** The comment this author wrote on the pull request holding this marker; none where there is none. */
    public function on(PullRequestNumber $number, string $author, string $marker = Markdown::MARKER): Answer|NotGiven
    {
        $found = NotGiven::value();
        $full = true;

        for ($page = 1; $found instanceof NotGiven && $full && $page <= self::PAGES; ++$page) {
            $items = $this->page($number, $page);
            $found = $this->among($items, $author, $marker);
            $full = count($items) === self::PAGE;
        }

        return $found;
    }

    /**
     * Every comment this author wrote on the pull request holding the gate's marker but not this one, oldest first,
     * up to the most pages read: those a run that writes its own comment hides.
     *
     * @return list<Answer>
     */
    public function othersOn(PullRequestNumber $number, string $author, string $marker): array
    {
        $others = [];
        $full = true;

        for ($page = 1; $full && $page <= self::PAGES; ++$page) {
            $items = $this->page($number, $page);
            $other = static fn(Answer $comment): bool => self::isOther($comment, $author, $marker);
            $others = [...$others, ...array_values(array_filter($items, $other))];
            $full = count($items) === self::PAGE;
        }

        return $others;
    }

    /** Whether this author wrote this comment, which holds the gate's marker but not this one. */
    private static function isOther(Answer $comment, string $author, string $marker): bool
    {
        $body = $comment->text('body');

        return str_contains($body, Markdown::MARKER)
            && ! str_contains($body, $marker)
            && $comment->text('user', 'login') === $author;
    }

    /** Who the token comments as: its user, or GitHub Actions' own bot where it cannot read one. */
    private function identityOfToken(): string
    {
        $user = $this->api->get('/user');
        $login = $user instanceof Answer ? $user->text('login') : '';

        return $login === '' ? self::ACTIONS : $login;
    }

    /** @return list<Answer> one page of the pull request's comments, oldest first; none where it cannot be read */
    private function page(PullRequestNumber $number, int $page): array
    {
        $comments = $this->api->get(sprintf(
            '/repos/%s/issues/%d/comments?per_page=%d&page=%d',
            $this->repository,
            $number->value(),
            self::PAGE,
            $page,
        ));

        return $comments instanceof Answer ? $comments->items() : [];
    }

    /**
     * The comment among these that this identity wrote with the marker; none where none is.
     *
     * @param list<Answer> $comments
     */
    private function among(array $comments, string $identity, string $marker): Answer|NotGiven
    {
        foreach ($comments as $comment) {
            $sticky = str_contains($comment->text('body'), $marker);

            if ($sticky && $comment->text('user', 'login') === $identity) {
                return $comment;
            }
        }

        return NotGiven::value();
    }
}

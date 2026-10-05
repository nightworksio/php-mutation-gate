<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\GitHub;

use function count;

use NightWorksIO\MutationGate\Core\Ci\PullRequestNumber;
use NightWorksIO\MutationGate\Core\NotGiven;

use function sprintf;
use function str_contains;

/**
 * The sticky comment on a pull request: the one comment carrying the hidden
 * marker that the commenting identity wrote, looked up page by page.
 */
final readonly class StickyComment
{
    /** The identity `GITHUB_TOKEN` comments as, which cannot read `/user`. */
    private const string ACTIONS = 'github-actions[bot]';

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

    /**
     * The sticky comment this identity wrote on the pull request, where an
     * identity left empty is the token's own; none where there is none.
     */
    public function on(PullRequestNumber $number, string $identity): Answer|NotGiven
    {
        $author = $identity === '' ? $this->identityOfToken() : $identity;
        $found = NotGiven::value();
        $full = true;

        for ($page = 1; $found instanceof NotGiven && $full && $page <= self::PAGES; ++$page) {
            $comments = $this->api->get(sprintf(
                '/repos/%s/issues/%d/comments?per_page=%d&page=%d',
                $this->repository,
                $number->value(),
                self::PAGE,
                $page,
            ));
            $items = $comments instanceof Answer ? $comments->items() : [];
            $found = $this->among($items, $author);
            $full = count($items) === self::PAGE;
        }

        return $found;
    }

    /** Who the token comments as: its user, or GitHub Actions' own bot where it cannot read one. */
    private function identityOfToken(): string
    {
        $user = $this->api->get('/user');
        $login = $user instanceof Answer ? $user->text('login') : '';

        return $login === '' ? self::ACTIONS : $login;
    }

    /**
     * The comment among these that this identity wrote with the marker; none where none is.
     *
     * @param list<Answer> $comments
     */
    private function among(array $comments, string $identity): Answer|NotGiven
    {
        foreach ($comments as $comment) {
            $sticky = str_contains($comment->text('body'), Markdown::MARKER);

            if ($sticky && $comment->text('user', 'login') === $identity) {
                return $comment;
            }
        }

        return NotGiven::value();
    }
}

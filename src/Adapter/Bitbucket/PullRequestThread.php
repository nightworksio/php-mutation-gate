<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Bitbucket;

use function array_slice;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Ci\PullRequestNumber;
use NightWorksIO\MutationGate\Core\Delivery\CommentStates;
use NightWorksIO\MutationGate\Core\Delivery\CommentThread;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Lenient;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Http\Reply;
use NightWorksIO\MutationGate\Core\Http\RestApi;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Report\PlannedMarkdown;
use NightWorksIO\MutationGate\Core\Written;

use function rawurlencode;
use function sprintf;
use function str_contains;

/**
 * A pull request's comments, as Bitbucket Cloud's pull request comments API serves them to a repository access
 * token (ADR-0048). Such a token cannot read `/user`, so the sticky comment is the first comment holding the
 * marker that the token may edit: each in turn, oldest first, is put in place, and one Bitbucket refuses to let the
 * token edit, which someone else wrote, is passed over. Where none is the token's own, a new one is posted. Over
 * the planned state alone, a comment no longer in it is put back as it was, which tells the token's own from
 * another's without changing it.
 */
final readonly class PullRequestThread implements CommentThread
{
    private const string REPOSITORY = '/repositories/%s/%s';

    private const string COMMENTS = '%s/pullrequests/%d/comments';

    private const string PAGES_OF_COMMENTS = '%s?pagelen=%d&page=%d';

    private const int PAGE = 50;

    /** The most pages of comments read to find the sticky one. */
    private const int PAGES = 30;

    /** The most comments holding the marker tried before a new one is posted. */
    private const int TRIES = 10;

    private const string UNWRITTEN = 'The pull request comment could not be written (%s).';

    private function __construct(
        private RestApi $api,
        private string $repository,
        private PullRequestNumber|NotWritten $pullRequest,
        private string $pipeline,
    ) {
    }

    /**
     * The comments of this pull request in the repository of this workspace and slug, asked through this API,
     * linking this pipeline; or why none is written.
     */
    public static function of(
        RestApi $api,
        string $workspace,
        string $slug,
        PullRequestNumber|NotWritten $pullRequest,
        string $pipeline,
    ): self {
        $repository = sprintf(self::REPOSITORY, rawurlencode($workspace), rawurlencode($slug));

        return new self($api, $repository, $pullRequest, $pipeline);
    }

    public function written(string $markdown, bool $overPlanned): Written|NotWritten
    {
        $number = $this->pullRequest;

        if ($number instanceof NotWritten) {
            return $number;
        }

        $comments = sprintf(self::COMMENTS, $this->repository, $number->value());
        $marked = $this->marked($comments);

        return $marked instanceof CannotJudge
            ? NotWritten::because(sprintf(self::UNWRITTEN, $marked->why()))
            : $this->sent($comments, $marked, CommentMarkdown::of($markdown, $this->pipeline), $overPlanned);
    }

    /**
     * This body put in place of the first of these comments that is the token's own; or, where none is, posted
     * among these comments as a new one, unless it is written only over the planned state.
     *
     * @param list<Node> $marked
     */
    private function sent(string $comments, array $marked, string $body, bool $overPlanned): Written|NotWritten
    {
        $outcome = NotGiven::value();

        foreach (array_slice($marked, 0, self::TRIES) as $comment) {
            $outcome = $outcome instanceof NotGiven
                ? $this->updated($comments, $comment, $body, $overPlanned)
                : $outcome;
        }

        return match (true) {
            ! $outcome instanceof NotGiven => $outcome,
            $overPlanned => NotWritten::because(CommentStates::NOT_PLANNED),
            default => $this->answered($this->api->post($comments, $this->document($body))),
        };
    }

    /**
     * The comment with this body, or, over the planned state alone where it is no longer in it, as it was; none
     * where the comment is not the token's own.
     */
    private function updated(
        string $comments,
        Node $comment,
        string $body,
        bool $overPlanned,
    ): Written|NotWritten|NotGiven {
        $raw = $this->rawOf($comment);
        $kept = $overPlanned && ! PlannedMarkdown::isPlanned($raw);
        $path = sprintf('%s/%d', $comments, Lenient::integer($comment->field('id')));
        $reply = $this->api->put($path, $this->document($kept ? $raw : $body));

        return match (true) {
            $reply instanceof Reply && $reply->isRefusedOrMissing() => NotGiven::value(),
            $kept && $reply instanceof Reply && $reply->isAccepted() => NotWritten::because(CommentStates::NOT_PLANNED),
            default => $this->answered($reply),
        };
    }

    /** Where the comment an answer holds is shown; or why it was not written. */
    private function answered(Reply|CannotJudge $reply): Written|NotWritten
    {
        $comment = $this->api->json($reply);

        return $comment instanceof CannotJudge
            ? NotWritten::because(sprintf(self::UNWRITTEN, $comment->why()))
            : Written::to(Lenient::text($comment->field('links')->field('html')->field('href')));
    }

    /**
     * Those of these comments that hold the marker, oldest first, read page by page from this one up to the most
     * pages read: none deleted, none a reply, none on a line; or why they cannot be read.
     *
     * @param  list<Node>            $marked those of the pages before
     * @return list<Node>|CannotJudge
     */
    private function marked(string $comments, int $page = 1, array $marked = []): array|CannotJudge
    {
        $read = $this->api->json($this->api->get(sprintf(self::PAGES_OF_COMMENTS, $comments, self::PAGE, $page)));

        if ($read instanceof CannotJudge) {
            return $read;
        }

        foreach (Lenient::items($read->field('values')) as $comment) {
            $marked = $this->isMarked($comment) ? [...$marked, $comment] : $marked;
        }

        return $read->field('next')->isPresent() && $page < self::PAGES
            ? $this->marked($comments, $page + 1, $marked)
            : $marked;
    }

    private function isMarked(Node $comment): bool
    {
        return ! Lenient::boolean($comment->field('deleted'), otherwise: false)
            && ! $comment->field('parent')->isPresent()
            && ! $comment->field('inline')->isPresent()
            && str_contains($this->rawOf($comment), CommentMarkdown::MARKER);
    }

    private function rawOf(Node $comment): string
    {
        return Lenient::text($comment->field('content')->field('raw'));
    }

    /** A comment's body as Bitbucket takes it. */
    private function document(string $body): Json
    {
        return Json::object(Member::of('content', Json::object(Member::of('raw', $body))));
    }
}

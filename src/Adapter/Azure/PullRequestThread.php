<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Azure;

use function array_filter;
use function array_slice;
use function array_values;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Ci\PullRequestNumber;
use NightWorksIO\MutationGate\Core\Delivery\CommentStates;
use NightWorksIO\MutationGate\Core\Delivery\CommentThread;
use NightWorksIO\MutationGate\Core\Delivery\CommentWrite;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Lenient;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Http\Reply;
use NightWorksIO\MutationGate\Core\Http\RestApi;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Report\PlannedMarkdown;
use NightWorksIO\MutationGate\Core\Report\UnfoldedMarkdown;
use NightWorksIO\MutationGate\Core\Written;

use function rawurlencode;
use function sprintf;
use function str_contains;

/**
 * A pull request's threads, as Azure Repos' REST API serves them. Each gate comment is the first comment of a thread of
 * its own, and a comment that holds the marker but is not the token's own is passed over. Azure Repos shows no fold in
 * a pull request's comment, so everything shows open. `comment.mode: new-hiding-old` closes the token's other threads.
 *
 * @internal
 */
final readonly class PullRequestThread implements CommentThread
{
    private const string PULL_REQUEST = '/%s/_apis/git/repositories/%s/pullRequests/%d';

    private const string QUERY = '%s?api-version=7.1';

    /** The most threads holding the marker tried before a new one is posted. */
    private const int TRIES = 10;

    private function __construct(
        private RestApi $api,
        private string $pullRequestPath,
        private string $page,
        private PullRequestNumber|NotWritten $pullRequest,
        private string $pipeline,
    ) {
    }

    /**
     * The threads of this pull request in this project and repository, asked through this API, shown at this page,
     * linking this pipeline; or why none is written.
     */
    public static function of(
        RestApi $api,
        string $project,
        string $repository,
        string $page,
        PullRequestNumber|NotWritten $pullRequest,
        string $pipeline,
    ): self {
        return new self(
            $api,
            sprintf(
                self::PULL_REQUEST,
                rawurlencode($project),
                rawurlencode($repository),
                $pullRequest instanceof PullRequestNumber ? $pullRequest->value() : 0,
            ),
            $page,
            $pullRequest,
            $pipeline,
        );
    }

    public function written(string $markdown, CommentWrite $write): Written|NotWritten
    {
        if ($this->pullRequest instanceof NotWritten) {
            return $this->pullRequest;
        }

        $read = $this->api->json($this->api->get($this->threads()));

        if ($read instanceof CannotJudge) {
            return NotWritten::because(sprintf(CommentStates::UNWRITTEN, $read->why()));
        }

        $gates = array_values(array_filter(Lenient::items($read->field('value')), $this->isGates(...)));

        return $write->isExistingOnly() && $gates === []
            ? NotWritten::because(CommentStates::NOT_THERE)
            : $this->sent($gates, $markdown, $write);
    }

    /** Whether the pull request is a draft; not where Azure Repos does not say. */
    public function isDraft(): bool
    {
        $read = $this->pullRequest instanceof PullRequestNumber
            ? $this->api->json($this->api->get(sprintf(self::QUERY, $this->pullRequestPath)))
            : NotGiven::value();

        return $read instanceof Node && Lenient::boolean($read->field('isDraft'), otherwise: false);
    }

    /**
     * This body put in the first of these threads marked as the write's and the token's own; or, where none is, in a
     * new thread, unless it is written only over the planned state. Old threads are closed where the write says so.
     *
     * @param list<Node> $gates
     */
    private function sent(array $gates, string $markdown, CommentWrite $write): Written|NotWritten
    {
        $body = UnfoldedMarkdown::of($markdown, $this->pipeline);
        $marker = UnfoldedMarkdown::of($write->marker(), $this->pipeline);
        $marked = array_filter($gates, static fn(Node $thread): bool => str_contains(self::body($thread), $marker));
        $outcome = NotGiven::value();

        foreach (array_slice($marked, 0, self::TRIES) as $thread) {
            $outcome = $outcome instanceof NotGiven
                ? $this->updated($thread, $body, $write->isOverPlanned())
                : $outcome;
        }

        $placed = match (true) {
            ! $outcome instanceof NotGiven => $outcome,
            $write->isOverPlanned() => NotWritten::because(CommentStates::NOT_PLANNED),
            default => $this->posted($body),
        };

        if ($placed instanceof NotWritten) {
            return $placed;
        }

        if ($write->hidesOld()) {
            $this->closed($gates, $placed);
        }

        return Written::to(sprintf('%s?discussionId=%d', $this->page, $placed->thread()));
    }

    /**
     * The thread with this body as its comment, or, over the planned state alone where it is no longer in it, as it
     * was; none where the comment is not the token's own.
     */
    private function updated(Node $thread, string $body, bool $overPlanned): PlacedComment|NotWritten|NotGiven
    {
        $raw = self::body($thread);
        $kept = $overPlanned && ! PlannedMarkdown::isPlanned($raw);
        $id = Lenient::integer($thread->field('id'));
        $path = sprintf(
            '%s/threads/%d/comments/%d',
            $this->pullRequestPath,
            $id,
            Lenient::integer(self::comment($thread)->field('id')),
        );
        $reply = $this->api->patch(
            sprintf(self::QUERY, $path),
            Json::object(Member::of('content', $kept ? $raw : $body)),
        );

        return match (true) {
            $reply instanceof Reply && $reply->isRefusedOrMissing() => NotGiven::value(),
            $kept && $reply instanceof Reply && $reply->isAccepted() => NotWritten::because(CommentStates::NOT_PLANNED),
            default => $this->placedIn(
                $reply,
                static fn(Node $comment): PlacedComment => PlacedComment::of($id, self::authorOf($comment)),
            ),
        };
    }

    private function posted(string $body): PlacedComment|NotWritten
    {
        $comment = Json::object(
            Member::of('parentCommentId', 0),
            Member::of('content', $body),
            Member::of('commentType', 1),
        );
        $thread = Json::object(
            Member::of('comments', Json::items($comment)),
            Member::of('status', ThreadStatus::Active->value),
        );

        return $this->placedIn(
            $this->api->post($this->threads(), $thread),
            static fn(Node $thread): PlacedComment => PlacedComment::of(
                Lenient::integer($thread->field('id')),
                self::authorOf(self::comment($thread)),
            ),
        );
    }

    /**
     * What an answer says was placed; or why nothing was.
     *
     * @param callable(Node): PlacedComment $placed
     */
    private function placedIn(Reply|CannotJudge $reply, callable $placed): PlacedComment|NotWritten
    {
        $read = $this->api->json($reply);

        return $read instanceof CannotJudge
            ? NotWritten::because(sprintf(CommentStates::UNWRITTEN, $read->why()))
            : $placed($read);
    }

    /**
     * Each of these threads closed that the same token began, but the one just written and those closed already. A
     * thread that holds the marker and is somebody else's stays as it is.
     *
     * @param list<Node> $gates
     */
    private function closed(array $gates, PlacedComment $placed): void
    {
        foreach ($gates as $thread) {
            $id = Lenient::integer($thread->field('id'));

            if ($this->closes($thread, $id, $placed)) {
                $this->api->patch(
                    sprintf(self::QUERY, sprintf('%s/threads/%d', $this->pullRequestPath, $id)),
                    Json::object(Member::of('status', ThreadStatus::Closed->value)),
                );
            }
        }
    }

    private function closes(Node $thread, int $id, PlacedComment $placed): bool
    {
        return $id !== $placed->thread()
            && self::authorOf(self::comment($thread)) === $placed->author()
            && Lenient::text($thread->field('status')) !== 'closed';
    }

    private function threads(): string
    {
        return sprintf(self::QUERY, sprintf('%s/threads', $this->pullRequestPath));
    }

    private function isGates(Node $thread): bool
    {
        return str_contains(self::body($thread), UnfoldedMarkdown::MARKER);
    }

    /** What a thread's first comment says. */
    private static function body(Node $thread): string
    {
        return Lenient::text(self::comment($thread)->field('content'));
    }

    /** A thread's first comment. */
    private static function comment(Node $thread): Node
    {
        return $thread->field('comments')->field('0');
    }

    private static function authorOf(Node $comment): string
    {
        return Lenient::text($comment->field('author')->field('id'));
    }
}

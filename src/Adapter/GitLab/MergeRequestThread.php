<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\GitLab;

use function count;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Ci\PullRequestNumber;
use NightWorksIO\MutationGate\Core\Delivery\CommentStates;
use NightWorksIO\MutationGate\Core\Delivery\CommentThread;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Lenient;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Http\RestApi;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Report\Markdown;
use NightWorksIO\MutationGate\Core\Report\PlannedMarkdown;
use NightWorksIO\MutationGate\Core\Written;

use function rawurlencode;
use function sprintf;
use function str_contains;

/**
 * A merge request's notes, as GitLab's notes API serves them to a token: the sticky note is the one holding the
 * hidden marker among the notes of the token's own user, which `/user` names, read oldest first, and it is put in
 * place where there is one and posted where there is none (ADR-0024).
 */
final readonly class MergeRequestThread implements CommentThread
{
    private const string NOTES = '/projects/%s/merge_requests/%d/notes';

    private const string PAGES_OF_NOTES = '%s?sort=asc&order_by=created_at&per_page=%d&page=%d';

    private const string LINK = '%s/-/merge_requests/%d#note_%d';

    private const int PAGE = 100;

    /** The most pages of notes read to find the sticky one. */
    private const int PAGES = 30;

    private const string UNWRITTEN = 'The merge request note could not be written (%s).';

    private function __construct(
        private RestApi $api,
        private string $project,
        private string $projectUrl,
        private PullRequestNumber|NotWritten $mergeRequest,
        private string $pipeline,
    ) {
    }

    /**
     * The notes of this merge request of the project with this ID, whose page is under this URL, asked through this
     * API, linking this pipeline; or why none is written.
     */
    public static function of(
        RestApi $api,
        string $project,
        string $projectUrl,
        PullRequestNumber|NotWritten $mergeRequest,
        string $pipeline,
    ): self {
        return new self($api, $project, $projectUrl, $mergeRequest, $pipeline);
    }

    public function written(string $markdown, bool $overPlanned): Written|NotWritten
    {
        $number = $this->mergeRequest;

        if ($number instanceof NotWritten) {
            return $number;
        }

        $existing = $this->sticky($number);

        return match (true) {
            $existing instanceof CannotJudge => NotWritten::because(sprintf(self::UNWRITTEN, $existing->why())),
            $overPlanned && ! $this->isPlanned($existing) => NotWritten::because(CommentStates::NOT_PLANNED),
            default => $this->sent($number, $existing, NoteMarkdown::of($markdown, $this->pipeline)),
        };
    }

    /** This body, posted as a new note, or put in place of the sticky one where there is one. */
    private function sent(PullRequestNumber $number, Node|NotGiven $existing, string $body): Written|NotWritten
    {
        $notes = $this->notes($number);
        $document = Json::object(Member::of('body', $body));
        $answer = $this->api->json($existing instanceof Node
            ? $this->api->put(sprintf('%s/%d', $notes, Lenient::integer($existing->field('id'))), $document)
            : $this->api->post($notes, $document));

        if ($answer instanceof CannotJudge) {
            return NotWritten::because(sprintf(self::UNWRITTEN, $answer->why()));
        }

        return Written::to(
            sprintf(self::LINK, $this->projectUrl, $number->value(), Lenient::integer($answer->field('id'))),
        );
    }

    /** The path of the merge request's notes. */
    private function notes(PullRequestNumber $number): string
    {
        return sprintf(self::NOTES, rawurlencode($this->project), $number->value());
    }

    /**
     * The sticky note the token's own user, as `/user` names it, wrote on the merge request; none where there is
     * none; or why the user or the notes cannot be read.
     */
    private function sticky(PullRequestNumber $number): Node|NotGiven|CannotJudge
    {
        $user = $this->api->json($this->api->get('/user'));

        return $user instanceof CannotJudge ? $user : $this->stickyBy(Lenient::text($user->field('username')), $number);
    }

    /** The sticky note this author wrote, read page by page, oldest first, and up to the most pages read. */
    private function stickyBy(string $author, PullRequestNumber $number): Node|NotGiven|CannotJudge
    {
        $notes = $this->notes($number);
        $found = NotGiven::value();
        $full = true;

        for ($page = 1; $found instanceof NotGiven && $full && $page <= self::PAGES; ++$page) {
            $read = $this->api->json($this->api->get(sprintf(self::PAGES_OF_NOTES, $notes, self::PAGE, $page)));
            $items = $read instanceof Node ? Lenient::items($read) : [];
            $found = $read instanceof CannotJudge ? $read : $this->among($items, $author);
            $full = count($items) === self::PAGE;
        }

        return $found;
    }

    /**
     * The sticky note among these that this author wrote; none where none is.
     *
     * @param list<Node> $notes
     */
    private function among(array $notes, string $author): Node|NotGiven
    {
        foreach ($notes as $note) {
            if ($this->isSticky($note, $author)) {
                return $note;
            }
        }

        return NotGiven::value();
    }

    /** Whether there is a sticky note, and it is in its planned state. */
    private function isPlanned(Node|NotGiven $note): bool
    {
        return $note instanceof Node && PlannedMarkdown::isPlanned(Lenient::text($note->field('body')));
    }

    /** Whether a note is the sticky one this author wrote: no system note, and holding the marker. */
    private function isSticky(Node $note, string $author): bool
    {
        return ! Lenient::boolean($note->field('system'), otherwise: false)
            && Lenient::text($note->field('author')->field('username')) === $author
            && str_contains(Lenient::text($note->field('body')), Markdown::MARKER);
    }
}

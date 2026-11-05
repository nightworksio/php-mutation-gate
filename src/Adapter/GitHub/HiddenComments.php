<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\GitHub;

use function array_map;
use function implode;

use NightWorksIO\MutationGate\Core\Change\CannotTell;

use function sprintf;
use function strtr;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The gate's earlier comments on a pull request hidden as outdated, under `comment.mode: new-hiding-old`
 * (ADR-0102), through GitHub's GraphQL API, which alone hides a comment: one query for which are hidden already,
 * then one mutation for each that is not, so each run hides only the comment before it.
 *
 * @internal
 */
final readonly class HiddenComments
{
    private const string HIDDEN = 'query { nodes(ids: [%s]) { ... on IssueComment { id isMinimized } } }';

    private const string HIDE
        = 'mutation { minimizeComment(input: {subjectId: %s, classifier: OUTDATED}) { minimizedComment { %s } } }';

    /** Whether GitHub shows a comment hidden. */
    private const string MINIMIZED = 'isMinimized';

    private function __construct(private Api $graphql)
    {
    }

    /**
     * Through GitHub's GraphQL API at the endpoint `GITHUB_GRAPHQL_URL` names, or else beside the REST API
     * `GITHUB_API_URL` names, or GitHub's own, with this token.
     */
    public static function at(HttpClientInterface $client, string $api, string $graphql, string $token): self
    {
        $endpoint = $graphql === '' ? sprintf('%s/graphql', $api === '' ? Api::GITHUB : $api) : $graphql;

        return new self(Api::at($client, $endpoint, $token));
    }

    /**
     * These comments hidden, those hidden already left as they are; why not, where GitHub did not answer.
     *
     * @param list<Answer> $comments
     */
    public function hide(array $comments): CannotTell|int
    {
        if ($comments === []) {
            return 0;
        }

        $ids = array_map(static fn(Answer $comment): string => self::quoted($comment->text('node_id')), $comments);
        $known = $this->graphql->query(sprintf(self::HIDDEN, implode(', ', $ids)));

        if ($known instanceof CannotTell) {
            return $known;
        }

        $hidden = 0;

        foreach ($known->items('data', 'nodes') as $node) {
            $hidden += ! $node->isTrue(self::MINIMIZED) && $this->hid($node) ? 1 : 0;
        }

        return $hidden;
    }

    /** Whether GitHub hid the comment this node names. */
    private function hid(Answer $node): bool
    {
        $hide = sprintf(self::HIDE, self::quoted($node->text('id')), self::MINIMIZED);

        return $this->graphql->query($hide) instanceof Answer;
    }

    private static function quoted(string $id): string
    {
        return sprintf('"%s"', strtr($id, ['\\' => '\\\\', '"' => '\\"']));
    }
}

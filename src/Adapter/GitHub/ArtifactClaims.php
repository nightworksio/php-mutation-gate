<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\GitHub;

use function base64_decode;
use function count;
use function explode;
use function hash;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

use JsonException;

use function max;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Ci\ItemClaims;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\ShardPull;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\Http\Exchange;
use NightWorksIO\MutationGate\Core\Http\MediaType;
use NightWorksIO\MutationGate\Core\Http\Reply;
use NightWorksIO\MutationGate\Core\Http\Request;
use NightWorksIO\MutationGate\Core\Http\Token;
use NightWorksIO\MutationGate\Core\Plan\ShardId;

use function rtrim;
use function sprintf;
use function str_starts_with;
use function strlen;
use function strtr;
use function substr;

/**
 * Claims and done markers as artifacts of the workflow run, named for the
 * plan, the run's attempt and the item, written and listed through the
 * artifact service with the job's runtime token (ADR-0134). A name the
 * service refuses as taken is another job's claim.
 *
 * @internal
 */
final readonly class ArtifactClaims implements ItemClaims
{
    /** The variables the artifact service is reached by, which the gate's action exposes to its step. */
    public const string RUNTIME_TOKEN = 'ACTIONS_RUNTIME_TOKEN';

    public const string RESULTS_URL = 'ACTIONS_RESULTS_URL';

    private const string SERVER = 'GITHUB_SERVER_URL';

    private const string ATTEMPT = 'GITHUB_RUN_ATTEMPT';

    private const string SERVICE = '%s/twirp/github.actions.results.api.v1.ArtifactService/%s';

    private const string CLAIM = 'mutation-gate-claim-%s-%d-%d';

    private const string DONE = 'mutation-gate-done-%s-%d-%d-%d';

    private const string DONE_OF = 'mutation-gate-done-%s-%d-%d-';

    /** The scope of the runtime token that names the run's and the job's backend ids. */
    private const string RESULTS_SCOPE = 'Actions.Results:';

    /** What the service answers a create whose name another artifact of the run holds. */
    private const int TAKEN = 409;

    /** An empty zip archive: its end of central directory record alone. */
    private const string EMPTY = "PK\x05\x06\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0";

    private const string RUN_ID = 'workflow_run_backend_id';

    private const string JOB_ID = 'workflow_job_run_backend_id';

    private const string BLOB_TYPE = 'x-ms-blob-type';

    private const string BLOCK_BLOB = 'BlockBlob';

    private const string NO_SERVICE = 'The artifact service is not reachable from this step: %s is not set.';

    private const string NO_SCOPE = 'The runtime token names no run to write artifacts to.';

    private const string REFUSED = 'The artifact service answered %s with %d.';

    private const string UNREAD = 'The artifact service answered %s with what is not JSON.';

    private function __construct(
        private Exchange $exchange,
        private string $service,
        private Token $token,
        private string $run,
        private string $job,
        private ShardPull $pull,
        private string $plan,
        private int $attempt,
        private ShardId $shard,
    ) {
    }

    /** Claims for this job of the plan, pulling as `shards.pull` says on this service; or why it cannot reach them. */
    public static function of(
        Variables $environment,
        Exchange $exchange,
        ShardPull $pull,
        Digest $plan,
        ShardId $shard,
    ): self|CannotJudge {
        $token = $environment->valueOf(self::RUNTIME_TOKEN);
        $service = $environment->valueOf(self::RESULTS_URL);
        $ids = self::backendIds($token);

        return match (true) {
            $token === '' => CannotJudge::because(sprintf(self::NO_SERVICE, self::RUNTIME_TOKEN)),
            $service === '' => CannotJudge::because(sprintf(self::NO_SERVICE, self::RESULTS_URL)),
            $ids === [] => CannotJudge::because(self::NO_SCOPE),
            default => new self(
                $exchange,
                rtrim($service, '/'),
                Token::bearer($token),
                $ids[0],
                $ids[1],
                $pull->on($environment->valueOf(self::SERVER)),
                $plan->value(),
                max(1, (int) $environment->valueOf(self::ATTEMPT)),
                $shard,
            ),
        };
    }

    /** The way this job pulls: `auto` resolved for its service. */
    public function pull(): ShardPull
    {
        return $this->pull;
    }

    public function take(ShardId $item): bool|CannotJudge
    {
        return $this->pull === ShardPull::Claim
            ? $this->created(sprintf(self::CLAIM, $this->plan, $this->attempt, $item->number()))
            : $this->unfinished($item);
    }

    public function finish(ShardId $item): ShardId|CannotJudge
    {
        $marked = $this->pull === ShardPull::Claim ? true : $this->created(sprintf(
            self::DONE,
            $this->plan,
            $this->attempt,
            $item->number(),
            $this->shard->number(),
        ));

        return $marked instanceof CannotJudge ? $marked : $item;
    }

    /**
     * The run's and the job's backend ids, as the runtime token's results scope names them; none where it names none.
     *
     * @return list<string>
     */
    private static function backendIds(string $token): array
    {
        $parts = explode('.', $token);
        $claims = isset($parts[1]) ? base64_decode(strtr($parts[1], '-_', '+/'), true) : false;

        try {
            $decoded = is_string($claims) ? json_decode($claims, true, 8, JSON_THROW_ON_ERROR) : null;
        } catch (JsonException) {
            $decoded = null;
        }

        $scopes = is_array($decoded) && is_string($decoded['scp'] ?? null) ? explode(' ', $decoded['scp']) : [];

        foreach ($scopes as $scope) {
            $ids = str_starts_with($scope, self::RESULTS_SCOPE)
                ? explode(':', substr($scope, strlen(self::RESULTS_SCOPE)))
                : [];

            if (count($ids) === 2 && $ids[0] !== '' && $ids[1] !== '') {
                return $ids;
            }
        }

        return [];
    }

    /** Whether no job marked the item done in this attempt. */
    private function unfinished(ShardId $item): bool|CannotJudge
    {
        $listed = $this->called('ListArtifacts', []);

        if ($listed instanceof CannotJudge) {
            return $listed;
        }

        $done = sprintf(self::DONE_OF, $this->plan, $this->attempt, $item->number());
        $artifacts = is_array($listed['artifacts'] ?? null) ? $listed['artifacts'] : [];

        foreach ($artifacts as $artifact) {
            $name = is_array($artifact) && is_string($artifact['name'] ?? null) ? $artifact['name'] : '';

            if (str_starts_with($name, $done)) {
                return false;
            }
        }

        return true;
    }

    /** Whether this job created an empty artifact of this name; false where another artifact holds it. */
    private function created(string $name): bool|CannotJudge
    {
        $create = $this->request('CreateArtifact', ['name' => $name, 'version' => 4]);
        $answer = $this->exchange->answer($create);

        if ($answer instanceof Reply && $answer->status() === self::TAKEN) {
            return false;
        }

        $created = $this->read('CreateArtifact', $answer);

        if (! is_array($created)) {
            return $created;
        }

        $url = is_string($created['signed_upload_url'] ?? null) ? $created['signed_upload_url'] : '';
        $uploaded = $url === ''
            ? CannotJudge::because(sprintf(self::UNREAD, 'CreateArtifact'))
            : $this->exchange->answer(Request::put($url, self::EMPTY)->with(self::BLOB_TYPE, self::BLOCK_BLOB));
        $finalized = $uploaded instanceof Reply && $uploaded->isAccepted()
            ? $this->called('FinalizeArtifact', [
                'name' => $name,
                'size' => sprintf('%d', strlen(self::EMPTY)),
                'hash' => sprintf('sha256:%s', hash('sha256', self::EMPTY)),
            ])
            : $this->refusal('the upload', $uploaded);

        return $finalized instanceof CannotJudge ? $finalized : true;
    }

    /**
     * What a method of the service answers, as JSON; or why it answered nothing readable.
     *
     * @param  array<string, string|int> $fields
     * @return array<mixed>|CannotJudge
     */
    private function called(string $method, array $fields): array|CannotJudge
    {
        return $this->read($method, $this->exchange->answer($this->request($method, $fields)));
    }

    /** @param array<string, string|int> $fields */
    private function request(string $method, array $fields): Request
    {
        return Request::post(
            sprintf(self::SERVICE, $this->service, $method),
            json_encode(
                [self::RUN_ID => $this->run, self::JOB_ID => $this->job, ...$fields],
                JSON_THROW_ON_ERROR,
            ),
        )->sending(MediaType::Json)->carrying($this->token);
    }

    /** @return array<mixed>|CannotJudge */
    private function read(string $method, Reply|CannotJudge $answer): array|CannotJudge
    {
        if (! $answer instanceof Reply || ! $answer->isAccepted()) {
            return $this->refusal($method, $answer);
        }

        try {
            $decoded = json_decode($answer->body(), true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $decoded = null;
        }

        return is_array($decoded) ? $decoded : CannotJudge::because(sprintf(self::UNREAD, $method));
    }

    private function refusal(string $method, Reply|CannotJudge $answer): CannotJudge
    {
        return $answer instanceof CannotJudge
            ? $answer
            : CannotJudge::because(sprintf(self::REFUSED, $method, $answer->status()));
    }
}

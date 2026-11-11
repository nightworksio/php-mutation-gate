<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\GitHub;

use function hash;
use function in_array;
use function is_array;
use function is_string;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Ci\ItemClaims;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\ShardPull;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\Http\Exchange;
use NightWorksIO\MutationGate\Core\Http\Reply;
use NightWorksIO\MutationGate\Core\Http\Request;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Plan\ShardId;

use function sprintf;
use function str_starts_with;
use function strlen;

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
    private const string SERVER = 'GITHUB_SERVER_URL';

    /**
     * The services, by the server URL a run reports, on which the gate's own
     * CI's race test shows an artifact name won by exactly one job.
     */
    private const array RACED = ['https://github.com'];

    private const string CLAIM = 'mutation-gate-claim-%s-%d-%d';

    private const string DONE = 'mutation-gate-done-%s-%d-%d-%d';

    private const string DONE_OF = 'mutation-gate-done-%s-%d-%d-';

    /** What the service answers a create whose name another artifact of the run holds. */
    private const int TAKEN = 409;

    /** An empty zip archive: its end of central directory record alone. */
    private const string EMPTY = "PK\x05\x06\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0";

    private const string BLOB_TYPE = 'x-ms-blob-type';

    private const string BLOCK_BLOB = 'BlockBlob';

    private const string UNHANDED = 'The gate was handed no artifact service to claim items by.';

    private function __construct(
        private RunArtifacts $artifacts,
        private ShardPull $pull,
        private string $plan,
        private int $attempt,
        private ShardId $shard,
    ) {
    }

    /**
     * Claims for this job of the plan, pulling as `shards.pull` says on the
     * service the gate was handed; or why it cannot reach them.
     */
    public static function of(
        ArtifactAccess|CannotJudge|NotGiven $service,
        Variables $environment,
        Exchange $exchange,
        ShardPull $pull,
        Digest $plan,
        ShardId $shard,
    ): self|CannotJudge {
        if (! $service instanceof ArtifactAccess) {
            return $service instanceof CannotJudge ? $service : CannotJudge::because(self::UNHANDED);
        }

        $artifacts = RunArtifacts::of($service, $exchange);

        return $artifacts instanceof CannotJudge ? $artifacts : new self(
            $artifacts,
            self::resolved($pull, $environment),
            $plan->value(),
            RunArtifacts::attempt($environment),
            $shard,
        );
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

    /** How a shard pulls on the service this run reports: `auto` claims where the race test passed there. */
    private static function resolved(ShardPull $pull, Variables $environment): ShardPull
    {
        return in_array($environment->valueOf(self::SERVER), self::RACED, true)
            ? $pull->whereRaced()
            : $pull->whereUnraced();
    }

    /** Whether no job marked the item done in this attempt. */
    private function unfinished(ShardId $item): bool|CannotJudge
    {
        $names = $this->artifacts->names();

        if ($names instanceof CannotJudge) {
            return $names;
        }

        $done = sprintf(self::DONE_OF, $this->plan, $this->attempt, $item->number());

        foreach ($names as $name) {
            if (str_starts_with($name, $done)) {
                return false;
            }
        }

        return true;
    }

    /** Whether this job created an empty artifact of this name; false where another artifact holds it. */
    private function created(string $name): bool|CannotJudge
    {
        $answer = $this->artifacts->answer('CreateArtifact', ['name' => $name, 'version' => 4]);

        if ($answer instanceof Reply && $answer->status() === self::TAKEN) {
            return false;
        }

        $created = $this->artifacts->read('CreateArtifact', $answer);

        if (! is_array($created)) {
            return $created;
        }

        $url = is_string($created['signed_upload_url'] ?? null) ? $created['signed_upload_url'] : '';
        $uploaded = $url === ''
            ? CannotJudge::because(sprintf(RunArtifacts::UNREAD, 'CreateArtifact'))
            : $this->artifacts->sent(Request::put($url, self::EMPTY)->with(self::BLOB_TYPE, self::BLOCK_BLOB));
        $finalized = $uploaded instanceof Reply && $uploaded->isAccepted()
            ? $this->artifacts->called('FinalizeArtifact', [
                'name' => $name,
                'size' => sprintf('%d', strlen(self::EMPTY)),
                'hash' => sprintf('sha256:%s', hash('sha256', self::EMPTY)),
            ])
            : $this->artifacts->refusal('the upload', $uploaded);

        return $finalized instanceof CannotJudge ? $finalized : true;
    }
}

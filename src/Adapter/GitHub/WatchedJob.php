<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\GitHub;

use function explode;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Format\Bytes;
use NightWorksIO\MutationGate\Core\Http\Exchange;
use NightWorksIO\MutationGate\Core\NotGiven;

use function sprintf;
use function str_starts_with;
use function trim;

/**
 * The jobs of the run a shard watches, as the reusable workflow's
 * `watch-jobs` names them, one per line (ADR-0135): once one concluded in
 * failure, the shard starts no further item. The reusable workflow's
 * `watch` job, which runs none of the project's code, reads their
 * conclusions and leaves an artifact named for the attempt and the failed
 * job; the shard lists the run's artifacts with its runtime token and
 * holds no token of its own. A run that watches no job, or cannot list
 * the artifacts, never stops for them.
 *
 * @internal
 */
final readonly class WatchedJob
{
    /** The variable of the gate's step that names the watched jobs, one per line. */
    public const string NAME = 'MUTATION_GATE_WATCH_JOBS';

    /** The artifact the `watch` job leaves once a watched job failed, before the failed job's name. */
    private const string STOPPED_ARTIFACT = 'mutation-gate-stopped-%d-';

    private const string STOPPED = 'The job %s failed, so this shard starts no further item.';

    private function __construct(private RunArtifacts|NotGiven $artifacts, private string $prefix)
    {
    }

    /** The jobs the environment names, read through the service the gate was handed; none where either is missing. */
    public static function of(
        Variables $environment,
        ArtifactAccess|CannotJudge|NotGiven $service,
        Exchange $exchange,
    ): self {
        $watches = false;

        foreach (explode("\n", $environment->valueOf(self::NAME)) as $name) {
            $watches = $watches || trim($name) !== '';
        }

        $artifacts = $watches && $service instanceof ArtifactAccess
            ? RunArtifacts::of($service, $exchange)
            : NotGiven::value();

        return new self(
            $artifacts instanceof RunArtifacts ? $artifacts : NotGiven::value(),
            sprintf(self::STOPPED_ARTIFACT, RunArtifacts::attempt($environment)),
        );
    }

    /**
     * Why the shard stops: a watched job concluded in failure; none where none did, or the artifacts cannot be read.
     */
    public function stopped(): string
    {
        $names = $this->artifacts instanceof RunArtifacts ? $this->artifacts->names() : [];

        foreach ($names instanceof CannotJudge ? [] : $names as $name) {
            if (str_starts_with($name, $this->prefix)) {
                return sprintf(self::STOPPED, Bytes::from($name, Bytes::length($this->prefix)));
            }
        }

        return '';
    }
}

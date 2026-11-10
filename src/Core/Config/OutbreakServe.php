<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function sprintf;

/**
 * Whether `outbreak serve` holds the project's state in memory, how often it
 * writes its snapshot and syncs its journal (ADR-0133): `outbreak.serve`.
 * Each judges or reports only and is in no key: the state answers what the
 * files answer.
 */
final readonly class OutbreakServe
{
    /** The key the `outbreak` section holds these settings under. */
    public const string OPTION = 'serve';

    /** The key of `outbreak.serve.enabled`. */
    public const string ENABLED = 'enabled';

    /** The key of `outbreak.serve.snapshotEvery`. */
    public const string SNAPSHOT_EVERY = 'snapshotEvery';

    /** The key of `outbreak.serve.syncEvery`. */
    public const string SYNC_EVERY = 'syncEvery';

    /** The minutes between snapshots, at most, where the config does not say. */
    private const int SNAPSHOT_MINUTES = 10;

    /** The seconds between syncs of the journal, at most, where the config does not say. */
    private const int SYNC_SECONDS = 1;

    private function __construct(
        private bool|Absent $enabled,
        private Seconds|Absent $snapshotEvery,
        private Seconds|Absent $syncEvery,
    ) {
    }

    public static function of(
        bool|Absent $enabled = new Absent(),
        Seconds|Absent $snapshotEvery = new Absent(),
        Seconds|Absent $syncEvery = new Absent(),
    ): self {
        return new self($enabled, $snapshotEvery, $syncEvery);
    }

    /** This, with what a later layer sets laid over it, key by key. */
    public function over(self $later): self
    {
        return new self(
            Absent::laid($this->enabled, $later->enabled),
            Absent::laid($this->snapshotEvery, $later->snapshotEvery),
            Absent::laid($this->syncEvery, $later->syncEvery),
        );
    }

    /** `outbreak.serve.enabled`: whether `outbreak serve` holds the state where the binary is in use; true by default. */
    public function enabled(): bool
    {
        return $this->enabled instanceof Absent || $this->enabled;
    }

    /** `outbreak.serve.snapshotEvery`: how often, at most, the daemon writes its snapshot; ten minutes by default. */
    public function snapshotEvery(): Seconds
    {
        return $this->snapshotEvery instanceof Seconds ? $this->snapshotEvery : Seconds::minutes(self::SNAPSHOT_MINUTES);
    }

    /** `outbreak.serve.syncEvery`: how often, at most, the daemon syncs its journal to disk; a second by default. */
    public function syncEvery(): Seconds
    {
        return $this->syncEvery instanceof Seconds ? $this->syncEvery : Seconds::of(self::SYNC_SECONDS);
    }

    /** These settings as a layer writes them, only those it sets. */
    public function written(): Member
    {
        return Member::unlessEmpty(self::OPTION, Json::object(
            Member::of(self::ENABLED, $this->enabled),
            Member::of(self::SNAPSHOT_EVERY, WholeSeconds::duration($this->snapshotEvery)),
            Member::of(self::SYNC_EVERY, WholeSeconds::duration($this->syncEvery)),
        ));
    }

    /**
     * The builder's calls that set these as a layer sets them.
     *
     * @return list<string>
     */
    public function php(): array
    {
        return [
            ...match ($this->enabled) {
                true => ['Outbreak::serving()'],
                false => ['Outbreak::notServing()'],
                default => [],
            },
            ...$this->snapshotEvery instanceof Seconds
                ? [sprintf('Outbreak::snapshotEvery(%s)', PhpCalls::literal($this->snapshotEvery->written()))]
                : [],
            ...$this->syncEvery instanceof Seconds
                ? [sprintf('Outbreak::syncEvery(%s)', PhpCalls::literal($this->syncEvery->written()))]
                : [],
        ];
    }
}

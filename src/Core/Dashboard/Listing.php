<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Dashboard;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Report\Trend;

/**
 * One repository as an organisation's dashboard lists it (ADR-0024): its
 * name, where it is, whether it is private, what its `summary.json` says or
 * why it says nothing, and its trend.
 *
 * @internal
 */
final readonly class Listing
{
    private function __construct(
        private string $name,
        private string $url,
        private bool $private,
        private Published|CannotJudge $published,
        private Trend $trend,
    ) {
    }

    public static function of(
        string $name,
        string $url,
        bool $private,
        Published|CannotJudge $published,
        Trend $trend,
    ): self {
        return new self($name, $url, $private, $published, $trend);
    }

    /** Its name within the organisation. */
    public function name(): string
    {
        return $this->name;
    }

    /** Where GitHub shows it. */
    public function url(): string
    {
        return $this->url;
    }

    public function isPrivate(): bool
    {
        return $this->private;
    }

    /** What its latest verdict on the default branch published; or why the dashboard reads nothing of it. */
    public function published(): Published|CannotJudge
    {
        return $this->published;
    }

    /** Its project's score over the runs it published, oldest first; none where it published none. */
    public function trend(): Trend
    {
        return $this->trend;
    }
}

<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\GitHub;

use NightWorksIO\MutationGate\Core\Change\CannotTell;

use function sprintf;
use function str_contains;
use function str_replace;

/**
 * The line of the sticky comment that links its run's HTML report: the
 * artifact the action and the reusable workflow upload unarchived under
 * this name, found among the run's own artifacts as the comment is written,
 * so a comment updated in place links the run that wrote it. Where the run
 * holds none, the line says so (ADR-0009).
 */
final readonly class HtmlReport
{
    /** The artifact the HTML report is uploaded as, unarchived, which is also its file's name. */
    public const string ARTIFACT = 'mutation-gate-report.html';

    /** Where a comment holds the line, until it is written. */
    public const string SLOT = '<!-- mutation-gate: html report -->';

    private const string ARTIFACTS = '/repos/%s/actions/runs/%s/artifacts?name=%s';

    private const string LINKED = '[The HTML report](%s/artifacts/%d) of [this run](%s).';

    private const string NONE = 'This run left no HTML report to link: %s. [The run](%s) holds what it wrote.';

    private const string ABSENT = 'no artifact of it is named `%s`, so the verdict wrote none or it was not uploaded';

    private const string EXPIRED = 'its artifact has expired';

    private const string UNREAD = 'its artifacts could not be read (%s)';

    private function __construct(private Api $api, private string $repository, private string $id, private string $run)
    {
    }

    /** The report of the run with this id in this repository, whose page is at this URL, asked through this API. */
    public static function of(Api $api, string $repository, string $id, string $run): self
    {
        return new self($api, $repository, $id, $run);
    }

    /** A comment with its slot, where it holds one, replaced by the line. */
    public function into(string $markdown): string
    {
        return str_contains($markdown, self::SLOT) ? str_replace(self::SLOT, $this->line(), $markdown) : $markdown;
    }

    /** The line: the link to the report, or why there is none. */
    public function line(): string
    {
        $found = $this->api->get(sprintf(self::ARTIFACTS, $this->repository, $this->id, self::ARTIFACT));

        if ($found instanceof CannotTell) {
            return sprintf(self::NONE, sprintf(self::UNREAD, $found->why()), $this->run);
        }

        $artifacts = $found->items('artifacts');

        return match (true) {
            $artifacts === [] => sprintf(self::NONE, sprintf(self::ABSENT, self::ARTIFACT), $this->run),
            $artifacts[0]->isTrue('expired') => sprintf(self::NONE, self::EXPIRED, $this->run),
            default => sprintf(self::LINKED, $this->run, $artifacts[0]->number('id'), $this->run),
        };
    }
}

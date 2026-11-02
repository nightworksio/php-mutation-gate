<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\GitHub;

use function array_any;
use function array_map;
use function array_merge;
use function array_slice;

use Closure;

use function explode;
use function implode;
use function is_array;

use NightWorksIO\MutationGate\Core\Owner\Owner;
use NightWorksIO\MutationGate\Core\Owner\OwnerGroup;
use NightWorksIO\MutationGate\Core\Report\Escape;
use NightWorksIO\MutationGate\Core\Verdict\OwnerVerdicts;

use function preg_match_all;
use function preg_split;
use function sprintf;
use function str_ends_with;
use function str_starts_with;

/**
 * The owners the sticky comment has mentioned on its pull request, kept in a
 * hidden record at its end, and the mentions an update adds (ADR-0049). Under
 * `owners.mention: first` the comment's markdown holds a placeholder for each
 * user and team an owner group's heading names, resolved as the comment is
 * written: one the record does not hold is mentioned and recorded, and any
 * other is named with its `@` escaped, so it notifies no one. A team is never
 * mentioned in a comment GitHub Actions' own bot writes, since its token
 * cannot read an organisation's teams. A placeholder anywhere but on a
 * heading stays as it is, so the project's own text mentions and records no
 * one.
 */
final readonly class OwnerMentions
{
    /** Where an owner group's heading names a user or team, until the comment is written. */
    private const string PLACEHOLDER = '<!-- mutation-gate: mention %s -->';

    /** The record of every owner mentioned so far, which the comment ends with. */
    private const string RECORD = '<!-- mutation-gate: mentioned %s -->';

    /** A placeholder, capturing the user or team it names. */
    private const string PLACEHOLDERS = '#<!-- mutation-gate: mention (@[A-Za-z0-9/_.-]+) -->#';

    private const string RECORDS = '#<!-- mutation-gate: mentioned (?<owners>[@A-Za-z0-9/_. -]*) -->#';

    /** How the comment heads an owner group. */
    private const string HEADING = '#### ';

    /** @param list<Owner> $mentioned */
    private function __construct(private array $mentioned, private bool $teams)
    {
    }

    /** No owner mentioned yet, as on a pull request with no comment; teams mentioned where its author can. */
    public static function none(bool $teams): self
    {
        return new self([], $teams);
    }

    /**
     * The owners the last record in this comment's body holds, whom no update
     * mentions again; teams mentioned where the comment's author can mention one.
     */
    public static function in(string $body, bool $teams): self
    {
        preg_match_all(self::RECORDS, $body, $records);
        $last = array_slice($records['owners'], -1);
        $tokens = array_merge(...array_map(static fn(string $record): array => explode(' ', $record), $last));
        $mentioned = [];

        foreach ($tokens as $token) {
            $owner = Owner::parse($token);
            $mentioned = $owner instanceof Owner && $owner->isHandle() ? [...$mentioned, $owner] : $mentioned;
        }

        return new self($mentioned, $teams);
    }

    /**
     * How the comment heads these groups: under `owners.mention: first`, with a placeholder for each user and team
     * and each email address as text; otherwise every owner as text.
     *
     * @return Closure(OwnerGroup): string
     */
    public static function headings(OwnerVerdicts $owners): Closure
    {
        return $owners->mentionsFirst() ? self::heading(...) : MarkdownItems::named(...);
    }

    /**
     * The markdown, each placeholder on an owner group's heading resolved to a
     * mention or to its name, ending with the record of every owner mentioned
     * so far, this update's included.
     */
    public function into(string $markdown): string
    {
        $mentions = $this;
        $lines = [];

        foreach (explode("\n", $markdown) as $line) {
            [$resolved, $mentions] = str_starts_with($line, self::HEADING)
                ? $mentions->resolved($line)
                : [$line, $mentions];
            $lines[] = $resolved;
        }

        return $mentions->recorded(implode("\n", $lines));
    }

    /** A group's owners as its heading names them, each user and team as a placeholder. */
    private static function heading(OwnerGroup $group): string
    {
        return $group->isUnowned()
            ? OwnerGroup::UNOWNED
            : implode(' ', array_map(self::placeholder(...), [...$group]));
    }

    private static function placeholder(Owner $owner): string
    {
        return $owner->isHandle() ? sprintf(self::PLACEHOLDER, $owner->value()) : Escape::text($owner->value());
    }

    /**
     * A heading with each placeholder resolved, and the owners mentioned so far, its own included.
     *
     * @return array{string, self}
     */
    private function resolved(string $heading): array
    {
        $mentions = $this;
        $parts = [];
        $split = preg_split(self::PLACEHOLDERS, $heading, flags: PREG_SPLIT_DELIM_CAPTURE);

        foreach (is_array($split) ? $split : [] as $at => $part) {
            [$shown, $mentions] = $at % 2 === 1 ? $mentions->mentioning($part) : [$part, $mentions];
            $parts[] = $shown;
        }

        return [implode('', $parts), $mentions];
    }

    /**
     * The user or team a placeholder names, as a mention where the comment may mention it now and as its escaped
     * name otherwise, and the owners mentioned so far.
     *
     * @return array{string, self}
     */
    private function mentioning(string $named): array
    {
        $owner = Owner::parse($named);

        return $owner instanceof Owner && $this->mentions($owner)
            ? [$owner->value(), new self([...$this->mentioned, $owner], $this->teams)]
            : [Escape::text($named), $this];
    }

    /** Whether the comment may mention this owner now: a user, or a team its author can mention, not yet mentioned. */
    private function mentions(Owner $owner): bool
    {
        return ($this->teams || ! $owner->isTeam()) && ! array_any($this->mentioned, $owner->is(...));
    }

    /** The markdown, then the record on a line of its own; the markdown alone where no owner has been mentioned. */
    private function recorded(string $markdown): string
    {
        $owners = array_map(static fn(Owner $owner): string => $owner->value(), $this->mentioned);
        $record = sprintf(self::RECORD, implode(' ', $owners));

        return match (true) {
            $this->mentioned === [] => $markdown,
            str_ends_with($markdown, "\n") => sprintf("%s%s\n", $markdown, $record),
            default => sprintf("%s\n%s\n", $markdown, $record),
        };
    }
}

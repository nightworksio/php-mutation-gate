<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Owner;

use function array_any;
use function array_first;
use function array_reverse;
use function array_slice;
use function explode;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Bytes;
use NightWorksIO\MutationGate\Core\Format\Fit;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Verdict\Warning;
use NightWorksIO\MutationGate\Core\Verdict\Warnings;

use function preg_match_all;
use function sprintf;
use function str_contains;
use function str_starts_with;
use function trim;

/**
 * A repository's CODEOWNERS, read as GitHub reads it (ADR-0022): one rule a
 * line, a pattern then its owners, the last rule matching a file deciding
 * its owners. A blank line, and one or the rest of one from a `#`, says
 * nothing. A line GitHub's syntax refuses is skipped with a warning: a
 * negated pattern, a range, an escaped `#`, or an owner that is none of
 * GitHub's spellings. A file over 3 MB is not read, with a warning.
 */
final readonly class CodeOwners
{
    /** Where GitHub looks for the file, in the order it looks. */
    public const array LOCATIONS = ['.github/CODEOWNERS', 'CODEOWNERS', 'docs/CODEOWNERS'];

    /** The most bytes of a file GitHub reads. */
    public const int MOST_BYTES = 3 * Bytes::PER_MEBIBYTE;

    private const string TOO_LARGE = '%s is over 3 MB, which GitHub does not read, so no survivor has an owner.';

    private const string SKIPPED = '%s line %d is skipped: %s';

    private const string NEGATED = 'a pattern that starts with `!` negates, which CODEOWNERS does not.';

    private const string RANGE = 'CODEOWNERS has no `[ ]` ranges.';

    private const string ESCAPED = 'a pattern that starts with `\#` is escaped, which CODEOWNERS does not read.';

    private const string NO_PATH = 'the pattern names no path.';

    private const string NOT_AN_OWNER = '`%s` is no owner, which is `@user`, `@org/team` or an email address.';

    private const string COMMENT = '#';

    /** @param list<array{OwnerPattern, OwnerGroup}> $rules the last in the file first, as the last match wins */
    private function __construct(private Path|NotGiven $file, private array $rules, private Warnings $warnings)
    {
    }

    /** No file: no survivor has an owner, and none is grouped by one. */
    public static function none(): self
    {
        return new self(NotGiven::value(), [], Warnings::none());
    }

    /** No file, since it cannot be read, with why. */
    public static function unread(string $why): self
    {
        return new self(NotGiven::value(), [], Warnings::of(Warning::that($why)));
    }

    /** The rules a file holds, each line its syntax refuses skipped with a warning. */
    public static function read(Path $file, string $text): self
    {
        if (Bytes::length($text) > self::MOST_BYTES) {
            return self::unread(sprintf(self::TOO_LARGE, $file->value()));
        }

        $rules = [];
        $warnings = Warnings::none();

        foreach (explode("\n", $text) as $at => $line) {
            $rule = self::rule($line);

            if ($rule instanceof Warning) {
                $skipped = sprintf(self::SKIPPED, $file->value(), $at + 1, $rule->text());
                $warnings = $warnings->with(Warning::that($skipped));

                continue;
            }

            if (! $rule instanceof NotGiven) {
                $rules[] = $rule;
            }
        }

        return new self($file, array_reverse($rules), $warnings);
    }

    /** Whether a file was read, which a report groups survivors by. */
    public function wasRead(): bool
    {
        return $this->file instanceof Path;
    }

    /** The owners of the last rule matching a path; unowned where none does. */
    public function groupOf(Path $path): OwnerGroup
    {
        foreach ($this->rules as [$pattern, $group]) {
            if ($pattern->matches($path)) {
                return $group;
            }
        }

        return OwnerGroup::unowned();
    }

    /** Whether any rule names an organisation's team. */
    public function namesTeam(): bool
    {
        return array_any(
            $this->rules,
            static fn(array $rule): bool => array_any([...$rule[1]], static fn(Owner $owner): bool => $owner->isTeam()),
        );
    }

    /** Each line skipped, and a file too large to read. */
    public function warnings(): Warnings
    {
        return $this->warnings;
    }

    /**
     * The rule a line holds; nothing for a blank line or a comment; or why
     * GitHub's syntax refuses it.
     *
     * @return array{OwnerPattern, OwnerGroup}|Warning|NotGiven
     */
    private static function rule(string $line): array|Warning|NotGiven
    {
        preg_match_all('#\S+#u', $line, $tokens);
        $pattern = array_first($tokens[0]) ?? self::COMMENT;
        $refused = match (true) {
            str_starts_with($pattern, self::COMMENT) => NotGiven::value(),
            str_starts_with($pattern, '!') => Warning::that(self::NEGATED),
            str_contains($pattern, '[') || str_contains($pattern, ']') => Warning::that(self::RANGE),
            str_starts_with($pattern, '\\#') => Warning::that(self::ESCAPED),
            trim($pattern, '/') === '' => Warning::that(self::NO_PATH),
            default => self::owners(array_slice($tokens[0], 1)),
        };

        return $refused instanceof OwnerGroup ? [OwnerPattern::of($pattern), $refused] : $refused;
    }

    /**
     * The owners a rule names, up to a comment; or why one of them is none.
     *
     * @param list<string> $tokens
     */
    private static function owners(array $tokens): OwnerGroup|Warning
    {
        $owners = [];

        foreach ($tokens as $token) {
            if (str_starts_with($token, self::COMMENT)) {
                break;
            }

            $owner = Owner::parse($token);

            if ($owner instanceof NotGiven) {
                return Warning::that(sprintf(self::NOT_AN_OWNER, Fit::plain($token)));
            }

            $owners[] = $owner;
        }

        return OwnerGroup::of(...$owners);
    }
}

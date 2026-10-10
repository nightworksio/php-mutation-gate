<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Php;

use function array_first;
use function in_array;
use function mb_stripos;
use function mb_substr;

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\Hold\HeldPath;
use NightWorksIO\MutationGate\Core\Hold\HoldsAttribute;
use NightWorksIO\MutationGate\Core\Hold\HoldsAttributes;
use NightWorksIO\MutationGate\Core\Hold\Standing;
use NightWorksIO\MutationGate\Core\Test\Group;

/**
 * Every `#[Holds]` a PHP file writes, read from its tokens: where it stands,
 * the path it names, its line, and PHPUnit's `#[Group]` beside it. The file is
 * never loaded.
 */
final readonly class HoldsReader
{
    /** The attribute, as the package declares it. */
    public const string HOLDS = 'NightWorksIO\MutationGate\Attribute\Holds';

    /** PHPUnit's attribute that puts a test in a group. */
    public const string GROUP = 'PHPUnit\Framework\Attributes\Group';

    /**
     * The attribute's own name, in lower case, which any file that writes it
     * spells in some case: PHP finds a class only by a name the file writes,
     * inline or where it imports it.
     */
    private const string SPELT = 'holds';

    private function __construct(private Tokens $tokens, private Scope $scope)
    {
    }

    /** Whether a file's text could write a `#[Holds]`: one that never spells its name writes none. */
    public static function mayHold(Contents $contents): bool
    {
        return mb_stripos($contents->text(), self::SPELT) !== false;
    }

    public static function in(Tokens $tokens, Scope $scope): HoldsAttributes
    {
        $reader = new self($tokens, $scope);
        $found = HoldsAttributes::none();

        foreach (AttributedMembers::in($tokens, $scope) as $member) {
            $found = $reader->heldBy($member, $found);
        }

        return $found;
    }

    /** These, with every `#[Holds]` among one run of attribute groups. */
    private function heldBy(AttributedMember $member, HoldsAttributes $found): HoldsAttributes
    {
        $declared = $this->groupsNamedBy($member->names());

        foreach ($member->names() as $at) {
            $found = $this->names($at, self::HOLDS)
                ? $found->with($this->holdsAt($at, $member->standing(), $member->holder(), $declared))
                : $found;
        }

        return $found;
    }

    /**
     * The group each of PHPUnit's `#[Group]` among some attributes names, where
     * it is one string literal.
     *
     * @param  list<int>    $names where each attribute's name stands
     * @return list<string>
     */
    private function groupsNamedBy(array $names): array
    {
        $groups = [];

        foreach ($names as $at) {
            $argument = $this->argumentOf($at);
            $groups = $this->names($at, self::GROUP) && $argument->isLiteral()
                ? [...$groups, $argument->text()]
                : $groups;
        }

        return $groups;
    }

    /** @param list<string> $declared the groups PHPUnit's `#[Group]` beside it names */
    private function holdsAt(int $at, Standing $standing, string $holder, array $declared): HoldsAttribute
    {
        $path = $this->argumentOf($at);
        $line = $this->tokens->line($at);
        $grouped = in_array(Group::holding($path->text())->name(), $declared, strict: true);

        return match ($standing) {
            Standing::TestClass => HoldsAttribute::onClass($path, $line, $holder, $grouped),
            Standing::TestMethod => HoldsAttribute::onMethod($path, $line, $holder, $grouped),
            Standing::NamedFunction => HoldsAttribute::onFunction($path, $line, $holder),
            Standing::TestClosure,
            Standing::DescribeClosure,
            Standing::HookClosure,
            Standing::DatasetClosure,
            Standing::KeptClosure,
            Standing::OtherClosure,
            Standing::Elsewhere => HoldsAttribute::at($standing, $path, $line),
        };
    }

    /**
     * The first argument of the attribute named at an index, named `path` or
     * not: one string literal, or anything else as it is written.
     */
    private function argumentOf(int $at): HeldPath
    {
        $open = $at + 1;

        if (! $this->tokens->is($open, '(')) {
            return HeldPath::expression('');
        }

        $end = array_first($this->tokens->inside($open, ',')) ?? $this->tokens->closing($open);
        $first = $open + 1;
        $from = $this->tokens->is($first + 1, ':') ? $first + 2 : $first;

        return $end - $from === 1 && $this->tokens->is($from, T_CONSTANT_ENCAPSED_STRING)
            ? HeldPath::literal(mb_substr($this->tokens->text($from), 1, -1))
            : HeldPath::expression($this->tokens->spelt($from, $end));
    }

    /** Whether the name at an index stands for this class. */
    private function names(int $at, string $class): bool
    {
        return $this->scope->resolve($this->tokens->text($at))->meet(Names::of($class));
    }
}

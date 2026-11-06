<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function array_key_exists;
use function in_array;
use function is_array;

use NightWorksIO\MutationGate\Core\Comment\Cap;
use NightWorksIO\MutationGate\Core\Comment\Detail;
use NightWorksIO\MutationGate\Core\Comment\Key;
use NightWorksIO\MutationGate\Core\Comment\Lines;
use NightWorksIO\MutationGate\Core\Comment\Section;
use NightWorksIO\MutationGate\Core\Comment\Toggle;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;

use function sprintf;

/**
 * What the pull request comment shows (ADR-0102): its sections in order, the verdict always first; those shown
 * open, the rest folded; how many of each list and how many characters of each text; which survivors and in what
 * detail; and whether it says what to try with each and how to reproduce it.
 */
final readonly class CommentShows
{
    /** The sections where the config lists none: every one, in the order the comment has always held them. */
    private const array SECTIONS = [
        Section::Verdict,
        Section::Security,
        Section::Trees,
        Section::Sets,
        Section::Heatmap,
        Section::Survivors,
        Section::Unjudged,
        Section::Ignored,
        Section::Problems,
        Section::Floors,
        Section::Report,
        Section::Costs,
    ];

    /** The sections shown open where the config lists none: the verdict, the trees and the heatmap. */
    private const array OPEN = [Section::Verdict, Section::Trees, Section::Heatmap];

    /** What this part says of, of the toggles. */
    private const array TOGGLES = [Toggle::Try, Toggle::Reproduce];

    /**
     * @param list<Section>|Absent $sections
     * @param list<Section>|Absent $open
     * @param array<string, int>   $caps     by the cap's name, those set
     * @param array<string, bool>  $toggles  by the toggle's name, those set
     */
    private function __construct(
        private array|Absent $sections,
        private array|Absent $open,
        private array $caps,
        private array $toggles,
        private Lines|Absent $lines,
        private Detail|Absent $detail,
    ) {
    }

    /**
     * @param list<Section>|Absent $sections
     * @param list<Section>|Absent $open
     * @param array<string, int>   $caps     by the cap's name
     * @param array<string, bool>  $toggles  by the toggle's name: `try` and `reproduce`
     */
    public static function of(
        array|Absent $sections = new Absent(),
        array|Absent $open = new Absent(),
        array $caps = [],
        array $toggles = [],
        Lines|Absent $lines = new Absent(),
        Detail|Absent $detail = new Absent(),
    ): self {
        return new self($sections, $open, $caps, $toggles, $lines, $detail);
    }

    /** Every default but `comment.open`, which shows every section open, as a summary does. */
    public static function unfolded(): self
    {
        return self::of(open: self::SECTIONS);
    }

    /** This, with what a later layer sets laid over it, key by key. */
    public function over(self $later): self
    {
        return new self(
            $later->sections instanceof Absent ? $this->sections : $later->sections,
            $later->open instanceof Absent ? $this->open : $later->open,
            [...$this->caps, ...$later->caps],
            [...$this->toggles, ...$later->toggles],
            $later->lines instanceof Absent ? $this->lines : $later->lines,
            $later->detail instanceof Absent ? $this->detail : $later->detail,
        );
    }

    /** @return list<Section> `comment.sections`: the sections the comment shows, in order, the verdict first */
    public function sections(): array
    {
        return is_array($this->sections) ? $this->sections : self::SECTIONS;
    }

    /** `comment.open`: whether a section is shown open, not folded; the verdict, trees and heatmap are by default. */
    public function isOpen(Section $section): bool
    {
        return in_array($section, is_array($this->open) ? $this->open : self::OPEN, strict: true);
    }

    /** `comment.<cap>.most`: how many of a list the comment shows, or how many characters of one text. */
    public function most(Cap $cap): int
    {
        return array_key_exists($cap->value, $this->caps) ? $this->caps[$cap->value] : $cap->standard();
    }

    /** `comment.try` and `comment.reproduce`: whether the comment says it; each is on by default. */
    public function allows(Toggle $toggle): bool
    {
        return ! array_key_exists($toggle->value, $this->toggles) || $this->toggles[$toggle->value];
    }

    /** `comment.survivors.lines`: which survivors the comment lists. */
    public function lines(): Lines
    {
        return $this->lines instanceof Lines ? $this->lines : Lines::Changed;
    }

    /** `comment.survivors.detail`: how the comment shows each survivor. */
    public function detail(): Detail
    {
        return $this->detail instanceof Detail ? $this->detail : Detail::Full;
    }

    /** @return list<Member> the keys a config writes of it, those set */
    public function members(): array
    {
        $members = [
            Member::of('sections', CommentWritten::listed($this->sections)),
            Member::of('open', CommentWritten::listed($this->open)),
        ];

        foreach (Cap::cases() as $cap) {
            $set = array_key_exists($cap->value, $this->caps) ? $this->caps[$cap->value] : new Absent();
            $keys = [Member::of('most', $set), ...$cap === Cap::Survivors ? $this->survivorKeys() : []];
            $members[] = Member::unlessEmpty($cap->value, Json::object(...$keys));
        }

        return [...$members, ...CommentWritten::toggles($this->toggles, self::TOGGLES)];
    }

    /** @return list<string> the PHP builder's calls for the keys set */
    public function calls(): array
    {
        $calls = [
            ...CommentWritten::casesCall(Key::Sections, $this->sections),
            ...CommentWritten::casesCall(Key::Open, $this->open),
            ...CommentWritten::caseCall(Key::Lines, $this->lines),
            ...CommentWritten::caseCall(Key::Detail, $this->detail),
        ];

        foreach ($this->caps as $cap => $most) {
            $calls[] = sprintf('Comment::most(Cap::%s, %d)', Cap::from($cap)->name, $most);
        }

        return [...$calls, ...CommentWritten::togglesCall($this->toggles)];
    }

    /** @return list<Member> `comment.survivors.lines` and `comment.survivors.detail`, where they are set */
    private function survivorKeys(): array
    {
        return [
            Member::of('lines', $this->lines instanceof Lines ? $this->lines->value : $this->lines),
            Member::of('detail', $this->detail instanceof Detail ? $this->detail->value : $this->detail),
        ];
    }
}

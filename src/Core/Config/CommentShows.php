<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function in_array;
use function is_int;

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
     * @param Listed<Section>|Absent $sections
     * @param Listed<Section>|Absent $open
     */
    private function __construct(
        private Listed|Absent $sections,
        private Listed|Absent $open,
        private CommentCaps $caps,
        private CommentToggles $toggles,
        private Lines|Absent $lines,
        private Detail|Absent $detail,
    ) {
    }

    /**
     * @param Listed<Section>|Absent $sections
     * @param Listed<Section>|Absent $open
     */
    public static function of(
        Listed|Absent $sections = new Absent(),
        Listed|Absent $open = new Absent(),
        CommentCaps|Absent $caps = new Absent(),
        CommentToggles|Absent $toggles = new Absent(),
        Lines|Absent $lines = new Absent(),
        Detail|Absent $detail = new Absent(),
    ): self {
        return new self(
            $sections,
            $open,
            $caps instanceof CommentCaps ? $caps : CommentCaps::none(),
            $toggles instanceof CommentToggles ? $toggles : CommentToggles::none(),
            $lines,
            $detail,
        );
    }

    /** Every default but `comment.open`, which shows every section open, as a summary does. */
    public static function unfolded(): self
    {
        return self::of(open: Listed::of(...self::SECTIONS));
    }

    /** This, with what a later layer sets laid over it, key by key. */
    public function over(self $later): self
    {
        return new self(
            $later->sections instanceof Absent ? $this->sections : $later->sections,
            $later->open instanceof Absent ? $this->open : $later->open,
            $this->caps->over($later->caps),
            $this->toggles->over($later->toggles),
            $later->lines instanceof Absent ? $this->lines : $later->lines,
            $later->detail instanceof Absent ? $this->detail : $later->detail,
        );
    }

    /** @return Listed<Section> `comment.sections`: the sections the comment shows, in order, the verdict first */
    public function sections(): Listed
    {
        return $this->sections instanceof Listed ? $this->sections : Listed::of(...self::SECTIONS);
    }

    /** `comment.open`: whether a section is shown open, not folded; the verdict, trees and heatmap are by default. */
    public function isOpen(Section $section): bool
    {
        return in_array($section, $this->open instanceof Listed ? [...$this->open] : self::OPEN, strict: true);
    }

    /** `comment.<cap>.most`: how many of a list the comment shows, or how many characters of one text. */
    public function most(Cap $cap): int
    {
        return $this->caps->most($cap);
    }

    /** `comment.try` and `comment.reproduce`: whether the comment says it; each is on by default. */
    public function allows(Toggle $toggle): bool
    {
        return $this->toggles->allows($toggle);
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

    /** The keys a config writes of it, those set. */
    public function json(): Json
    {
        $members = [
            Member::of('sections', CommentWritten::listed($this->sections)),
            Member::of('open', CommentWritten::listed($this->open)),
        ];

        foreach (Cap::cases() as $cap) {
            $survivors = $cap === Cap::Survivors ? $this->survivorKeys() : [];
            $keys = [Member::of('most', $this->caps->set($cap)), ...$survivors];
            $members[] = Member::unlessEmpty($cap->value, Json::object(...$keys));
        }

        return Json::object(...$members, ...CommentWritten::toggles($this->toggles, self::TOGGLES));
    }

    /** The PHP builder's calls for the keys set. */
    public function php(): PhpCalls
    {
        $calls = [
            ...CommentWritten::casesCall(Key::Sections, $this->sections),
            ...CommentWritten::casesCall(Key::Open, $this->open),
            ...CommentWritten::caseCall(Key::Lines, $this->lines),
            ...CommentWritten::caseCall(Key::Detail, $this->detail),
        ];

        foreach (Cap::cases() as $cap) {
            $most = $this->caps->set($cap);
            $calls = is_int($most) ? [...$calls, sprintf('Comment::most(Cap::%s, %d)', $cap->name, $most)] : $calls;
        }

        return PhpCalls::inWith(...$calls, ...CommentWritten::togglesCall($this->toggles, self::TOGGLES));
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

<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function array_key_exists;
use function array_keys;
use function mb_strlen;

use NightWorksIO\MutationGate\Core\Config\Definition\At;
use NightWorksIO\MutationGate\Core\File\Glob;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;

use function sprintf;
use function usort;

/**
 * `paths` (ADR-0074): the entries that set mutators, timeouts and the static check by glob. A later layer's
 * entries follow an earlier one's, and an entry equal to an earlier one is dropped.
 */
final readonly class PathRules
{
    /** The key a config writes the entries under. */
    public const string KEY = 'paths';

    /** @param Listed<PathRule>|Absent $rules */
    private function __construct(private Listed|Absent $rules)
    {
    }

    public static function of(PathRule ...$rules): self
    {
        return new self(Listed::of(...$rules));
    }

    public static function none(): self
    {
        return new self(Absent::setting());
    }

    public static function standard(): self
    {
        return self::of();
    }

    /** These, with a later layer's entries after them, but for those equal to one of these. */
    public function over(self $later): self
    {
        return match (true) {
            $later->rules instanceof Absent => $this,
            $this->rules instanceof Absent => $later,
            default => new self($this->rules->and(
                $later->rules,
                static fn(PathRule $rule): string => $rule->written(ProjectRoot::origin())->line(),
            )),
        };
    }

    /** @return Listed<PathRule> every entry, in the order the layers write them */
    public function rules(): Listed
    {
        return $this->rules instanceof Listed ? $this->rules : Listed::of();
    }

    /**
     * The entries whose glob matches a file, least specific first: by the length of the glob's literal prefix,
     * then in the order they are written, so the most specific is laid last.
     */
    public function for(Path $file): MatchedRules
    {
        $matched = [];

        foreach ($this->rules() as $index => $rule) {
            if ($rule->matches($file)) {
                $matched[$index] = $rule;
            }
        }

        $order = array_keys($matched);
        usort(
            $order,
            static fn(int $one, int $other): int => [mb_strlen($matched[$one]->prefix()), $one]
                <=> [mb_strlen($matched[$other]->prefix()), $other],
        );
        $ranked = [];

        foreach ($order as $index) {
            $ranked[] = $matched[$index];
        }

        return MatchedRules::of(...$ranked);
    }

    /**
     * The globs whose files run otherwise here than under the entries these were laid since: those of each entry
     * not where it was, as it was, in both, so an entry added, removed, changed or moved among them reaches the
     * files it matches, under either.
     *
     * @return Listed<Glob>
     */
    public function movedSince(self $then): Listed
    {
        $now = [...$this->rules()];
        $before = [...$then->rules()];
        $moved = [];

        foreach ([[$now, $before], [$before, $now]] as [$these, $those]) {
            foreach ($these as $index => $rule) {
                $kept = array_key_exists($index, $those)
                    && $those[$index]->written(ProjectRoot::origin())->line()
                        === $rule->written(ProjectRoot::origin())->line();
                $moved = $kept ? $moved : [...$moved, $rule->glob()];
            }
        }

        return Listed::of(...$moved);
    }

    /**
     * A problem for each entry whose timeouts, laid over these settings, leave `timeouts.most` under
     * `timeouts.seconds`, at the entry's path; nothing where none does.
     */
    public function unbounded(Layer $settled): Invalid|Absent
    {
        $problems = [];

        foreach ($this->rules() as $index => $rule) {
            $refusal = $settled->over($rule->rules())->judging()->triage()->refusal();

            foreach ($refusal instanceof Invalid ? $refusal : [] as $problem) {
                $problems[] = Problem::at(
                    sprintf('%s.%s', At::index(self::KEY, $index), $problem->path()),
                    $problem->message(),
                );
            }
        }

        return $problems === [] ? Absent::setting() : Invalid::because(...$problems);
    }

    public function written(PathOrigin $origin): Json
    {
        $entries = [];

        foreach ($this->rules() as $rule) {
            $entries[] = $rule->written($origin);
        }

        return $this->rules instanceof Absent
            ? Json::object()
            : Json::object(Member::of(self::KEY, Json::items(...$entries)));
    }

    public function php(PathOrigin $origin): PhpCalls
    {
        $calls = [];

        foreach ($this->rules() as $rule) {
            $calls[] = $rule->php($origin);
        }

        return PhpCalls::inWith(...$calls);
    }
}

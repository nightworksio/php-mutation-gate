<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Suggest;

use function array_filter;
use function array_key_exists;
use function array_keys;
use function array_values;
use function ceil;
use function count;
use function max;
use function min;

use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\PathRule;
use NightWorksIO\MutationGate\Core\Config\PathRules;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Config\Setup;
use NightWorksIO\MutationGate\Core\Config\Triage;
use NightWorksIO\MutationGate\Core\File\Glob;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Proof\NewestProofs;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function sprintf;
use function usort;

/**
 * The directories whose mutants ran out of time in the newest proof of each
 * unit (ADR-0076): a timeout, or a mutant skipped as too slow to run, by the
 * directory of its file. Each that holds enough gets a `paths` entry that
 * doubles its files' `timeouts.seconds`, setting `timeouts.most` where the
 * doubled limit would pass the most its files or the project allow, to the
 * larger of the two, so the entry is valid on its own and over the entries
 * that match its files, the directory with the most timeouts first.
 *
 * @internal
 */
final readonly class SlowDirectories
{
    private const string SLOW
        = '`%s/`: %d mutants of the newest proofs ran out of time at %ds. Give its files a timeout of %ds.';

    /** How many times its timeout a slow directory's files are given. */
    private const int RAISED = 2;

    /** @param array<string, non-empty-list<Path>> $timedOut each timed-out mutant's file, by its directory */
    private function __construct(private array $timedOut)
    {
    }

    public static function in(NewestProofs $proofs): self
    {
        $timedOut = [];

        foreach ($proofs as $proof) {
            foreach (self::ranOutOfTime($proof) as $mutant) {
                $file = $mutant->location()->file();
                $directory = $file->directory()->value();
                $held = array_key_exists($directory, $timedOut) ? $timedOut[$directory] : [];
                $timedOut[$directory] = [...$held, $file];
            }
        }

        return new self($timedOut);
    }

    /**
     * A `paths` entry for each directory where at least this many mutants ran out of time.
     *
     * @return list<Suggestion>
     */
    public function suggested(Settings $settings, int $cluster): array
    {
        $slow = [];

        foreach (array_keys($this->timedOut) as $directory) {
            $named = $directory;
            $slow = count($this->timedOut[$named]) >= $cluster ? [...$slow, $named] : $slow;
        }

        usort($slow, fn(string $one, string $other): int => $this->rank($one) <=> $this->rank($other));
        $suggestions = [];

        foreach ($slow as $directory) {
            $suggestions[] = $this->raised($directory, $settings);
        }

        return $suggestions;
    }

    /** @return list<Mutant> the mutants of a proof that ran out of time */
    private static function ranOutOfTime(Proof $proof): array
    {
        return array_values(array_filter(
            [...$proof->reported()],
            static fn(Mutant $mutant): bool => $mutant->status()->ranOutOfTime(),
        ));
    }

    /** @return array{int, string} where a directory ranks: the most timeouts first, then by name */
    private function rank(string $directory): array
    {
        return [-count($this->timedOut[$directory]), $directory];
    }

    private function raised(string $directory, Settings $settings): Suggestion
    {
        $files = $this->timedOut[$directory];
        $effective = $settings->effective();
        $triage = $effective->over($effective->setup()->paths()->for($files[0])->layer())->triage();
        $now = (int) ceil($triage->limit()->seconds());
        $raised = $now * self::RAISED;
        $most = $triage->most()->seconds();
        $bounded = $raised <= min($most, $effective->triage()->most()->seconds());
        $timeouts = Triage::of(
            limit: Seconds::of($raised),
            most: $bounded ? Absent::setting() : Seconds::of(max($raised, $most)),
        );

        return Suggestion::of(
            sprintf(self::SLOW, $directory, count($files), $now, $raised),
            Layer::of(Setup::of(paths: PathRules::of(PathRule::of(
                Glob::of($directory === Path::root()->value() ? Glob::DEEP : sprintf('%s/%s', $directory, Glob::DEEP)),
                Layer::of($timeouts),
            )))),
        );
    }
}

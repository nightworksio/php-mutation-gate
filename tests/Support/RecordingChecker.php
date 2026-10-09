<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function array_map;
use function file_get_contents;
use function is_file;

use NightWorksIO\MutationGate\Core\Analysis\AnalyserIdentity;
use NightWorksIO\MutationGate\Core\Analysis\AnalyserSettings;
use NightWorksIO\MutationGate\Core\Analysis\CheckAnswers;
use NightWorksIO\MutationGate\Core\Analysis\Findings;
use NightWorksIO\MutationGate\Core\Analysis\MutantCheck;
use NightWorksIO\MutationGate\Core\Analysis\MutantChecks;
use NightWorksIO\MutationGate\Core\Analysis\OutOfScope;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Runner\ProcessCount;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;
use NightWorksIO\MutationGate\Port\StaticChecker;
use NightWorksIO\MutationGate\Tests\Fakes\StaticCheckerFake;

use function sprintf;

/**
 * A static analyser that answers as the fake it wraps does, and records what
 * it was asked: each warm-up, and each check with the text it read in place
 * of the original, from the project it checks in.
 */
final class RecordingChecker implements StaticChecker
{
    /** @var list<Paths> the files each warm-up had to hold */
    private array $warmUps = [];

    /** @var list<array{string, string, string}> each check's original, the file read in its place, and that file's text */
    private array $checks = [];

    /** @var list<list<string>> the dependents each check listed */
    private array $dependents = [];

    /** @var list<Seconds|Unlimited> */
    private array $limits = [];

    public function __construct(private readonly StaticCheckerFake $answers, private readonly string $project)
    {
    }

    public function identity(Withheld $withheld): AnalyserIdentity|CannotJudge
    {
        return $this->answers->identity($withheld);
    }

    public function configuration(Withheld $withheld): AnalyserSettings|CannotJudge
    {
        return $this->answers->configuration($withheld);
    }

    public function findings(Paths $files, Withheld $withheld): Findings|CannotJudge
    {
        $this->warmUps[] = $files;

        return $this->answers->findings($files, $withheld);
    }

    public function readsDependents(): bool
    {
        return $this->answers->readsDependents();
    }

    public function check(MutantCheck $check): Findings|OutOfScope|CannotJudge
    {
        $file = sprintf('%s/%s', $this->project, $check->mutant()->value());
        $this->checks[] = [
            $check->original()->value(),
            $check->mutant()->value(),
            is_file($file) ? sprintf('%s', file_get_contents($file)) : '',
        ];
        $this->dependents[] = array_map(static fn(Path $dependent): string => $dependent->value(), [...$check->dependents()]);
        $this->limits[] = $check->limit();

        return $this->answers->check($check);
    }

    public function checks(MutantChecks $checks, ProcessCount $side): CheckAnswers
    {
        $answers = [];

        foreach ($checks as $check) {
            $answers[] = $this->check($check);
        }

        return CheckAnswers::of(...$answers);
    }

    /** @return list<Paths> */
    public function warmUps(): array
    {
        return $this->warmUps;
    }

    /** @return list<array{string, string, string}> */
    public function asked(): array
    {
        return $this->checks;
    }

    /** @return list<list<string>> */
    public function dependents(): array
    {
        return $this->dependents;
    }

    /** @return list<Seconds|Unlimited> how long each check was allowed, in order */
    public function limits(): array
    {
        return $this->limits;
    }
}

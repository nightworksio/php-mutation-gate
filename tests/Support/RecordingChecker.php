<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function file_get_contents;
use function is_file;

use NightWorksIO\MutationGate\Core\Analysis\AnalyserIdentity;
use NightWorksIO\MutationGate\Core\Analysis\Findings;
use NightWorksIO\MutationGate\Core\Analysis\MutantCheck;
use NightWorksIO\MutationGate\Core\Analysis\OutOfScope;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
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

    public function __construct(private readonly StaticCheckerFake $answers, private readonly string $project)
    {
    }

    public function identity(Withheld $withheld): AnalyserIdentity|CannotJudge
    {
        return $this->answers->identity($withheld);
    }

    public function findings(Paths $files, Withheld $withheld): Findings|CannotJudge
    {
        $this->warmUps[] = $files;

        return $this->answers->findings($files, $withheld);
    }

    public function check(MutantCheck $check): Findings|OutOfScope|CannotJudge
    {
        $file = sprintf('%s/%s', $this->project, $check->mutant()->value());
        $this->checks[] = [
            $check->original()->value(),
            $check->mutant()->value(),
            is_file($file) ? sprintf('%s', file_get_contents($file)) : '',
        ];

        return $this->answers->check($check);
    }

    /** @return list<Paths> */
    public function warmUps(): array
    {
        return $this->warmUps;
    }

    /** @return list<array{string, string, string}> */
    public function checks(): array
    {
        return $this->checks;
    }
}

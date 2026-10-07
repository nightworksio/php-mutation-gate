<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function array_diff_key;
use function array_keys;
use function array_map;
use function array_unique;
use function array_values;

use Closure;

use function copy;
use function file_get_contents;
use function file_put_contents;
use function is_file;
use function iterator_to_array;
use function ksort;

use NightWorksIO\MutationGate\Adapter\Infection\Patch as InfectionPatch;
use NightWorksIO\MutationGate\Adapter\Pest\Patching;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Evidences;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Runner\LimitBounds;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Runner\Narrowing;
use NightWorksIO\MutationGate\Core\Runner\TighterSilence;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Contract\Runner\Library;

use function sort;
use function sprintf;
use function unlink;

/** The libraries every runner contract runs against, and what the contracts read of their runs. */
final readonly class RunnerContracts
{
    /** What a run of the Infection library warns of while its Infection is not patched. */
    public const string INFECTION_UNPATCHED = 'Unpatched, Infection gave each mutant its own limit, with no floor. Run mutation-gate infection:patch.';
    /**
     * Each library a contract runs against, by its name: the fake, and each
     * adapter whose library its job has installed.
     *
     * @return array<string, Closure(): Library>
     */
    public static function libraries(): array
    {
        $libraries = ['the fake' => Library::fake(...)];

        if (Library::isInstalled()) {
            $libraries['pest'] = fn(): Library => Library::pest(Patching::off());
        }

        if (Library::isInfectionInstalled()) {
            $libraries['infection'] = fn(): Library => Library::infection(Seconds::of(10.0));
        }

        if (Library::isPhpUnitInstalled()) {
            $libraries['phpunit'] = fn(): Library => Library::phpunit();
        }

        return $libraries;
    }

    /**
     * The libraries whose runner reads a map from disk: every one but the fake.
     *
     * @return array<string, Closure(): Library>
     */
    public static function onDisk(): array
    {
        return array_diff_key(self::libraries(), ['the fake' => true]);
    }

    /**
     * Which of the fixtures' marks a run leaves: whether it loaded MoneySpec,
     * loaded it through a user-space `file://` wrapper, and ran one of its tests.
     * Symfony's Process hands a child the variables in $_ENV, whatever putenv() did.
     *
     * @param  Closure(): void                                    $run
     * @return array{loaded: bool, wrapped: bool, ran: bool}
     */
    public static function marks(Closure $run): array
    {
        $marks = ['loaded' => 'CONTRACT_LOADED', 'wrapped' => 'CONTRACT_WRAPPED', 'ran' => 'CONTRACT_RAN'];
        $directory = Scratch::directory();

        foreach ($marks as $mark => $variable) {
            $_ENV[$variable] = sprintf('%s/%s', $directory, $mark);
        }

        $run();
        $left = [];

        foreach ($marks as $mark => $variable) {
            $left[$mark] = is_file(sprintf('%s/%s', $directory, $mark));
            unset($_ENV[$variable]);
        }

        return $left;
    }

    /** Money's four mutants, as a library's runner reports them. */
    public static function money(Library $library): MutationResult|CannotJudge
    {
        return $library->mutate(
            'money',
            MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests())
            ->narrowedTo(Paths::of(Path::of('src/Money.php')), Narrowing::none()->toMutators($library->mutators('adds', 'large', 'unused', 'drains'))),
        );
    }

    /** @return list<string> */
    public static function files(Mutants $mutants): array
    {
        return array_values(array_unique(array_map(
            static fn(Mutant $mutant): string => $mutant->location()->file()->value(),
            iterator_to_array($mutants, preserve_keys: false),
        )));
    }

    /** @return array<string, list<string>> each covered line of a map, by its file and line, with its tests sorted */
    public static function lines(CoverageMap $map): array
    {
        $lines = [];

        foreach ($map->lines() as $line) {
            $tests = [...$line];
            sort($tests);
            $lines[sprintf('%s:%d', $line->file()->value(), $line->line())] = $tests;
        }

        ksort($lines);

        return $lines;
    }

    /**
     * The crash fixture's mutant as a runner over the library in this directory
     * reports it: its source and its spec copied in for the run, and taken out
     * again once it is done.
     *
     * @param  Closure(): (MutationResult|CannotJudge) $run
     * @param  array<string, string>                   $files each file copied in, by where in the library, from where in crash/
     * @return array{list<Mutant>, Evidences}
     */
    public static function crashed(string $directory, array $files, Closure $run): array
    {
        foreach ($files as $into => $from) {
            copy(Tree::at(sprintf('tests/Contract/Runner/crash/%s', $from)), Tree::at(sprintf('%s/%s', $directory, $into)));
        }

        try {
            $result = $run();
        } finally {
            foreach (array_keys($files) as $into) {
                unlink(Tree::at(sprintf('%s/%s', $directory, $into)));
            }
        }

        return $result instanceof MutationResult
            ? [iterator_to_array($result->mutants(), preserve_keys: false), $result->evidence()]
            : [[], Evidences::none()];
    }

    /** The request for the crash fixture's one mutant. */
    public static function crashRequest(Library $library): MutationRequest
    {
        return MutationRequest::of(Paths::of(Path::of('src/Crash.php')), WholeSuite::tests())
            ->narrowedTo(Paths::of(Path::of('src/Crash.php')), Narrowing::none()->toMutators($library->mutators('large')));
    }

    /**
     * What each mutant of the change named comes to, run on the library under a
     * floor of ten seconds, where timeouts.tighter gives the mutator that change
     * is made by, by its short name, a floor of seven.
     *
     * @return list<array{MutantStatus, float, string}> each mutant's status, seconds and reason
     */
    public static function tighterSilenced(MutationResult|CannotJudge $result): array
    {
        return array_map(
            static fn(Mutant $mutant): array => [
                $mutant->status(),
                $mutant->duration() instanceof Seconds ? $mutant->duration()->seconds() : 0.0,
                $mutant->reason() instanceof Reason ? $mutant->reason()->text() : '',
            ],
            $result instanceof MutationResult ? iterator_to_array($result->mutants(), preserve_keys: false) : [],
        );
    }

    /** The bounds of a floor of ten seconds, the silence limit of a mutator by this short name kept above seven. */
    public static function tighterBounds(string $mutator): LimitBounds
    {
        return LimitBounds::between(Seconds::of(10.0), Seconds::of(300.0))
            ->tighterFor(TighterSilence::of(Seconds::of(7.0), $mutator));
    }

    /**
     * What this does, with the Infection library's Infection patched by
     * infection:patch, and its files as they were again once it is done.
     *
     * @param Closure(): (MutationResult|CannotJudge) $then
     */
    public static function withPatchedInfection(Closure $then): MutationResult|CannotJudge
    {
        $vendor = Tree::at(sprintf('%s/vendor', Library::INFECTION_DIRECTORY));
        $files = [
            ...array_map(static fn(string $file): string => sprintf('%s/infection/infection/src/%s', $vendor, $file), InfectionSource::FILES),
            sprintf('%s/infection/include-interceptor/src/%s', $vendor, InfectionSource::INTERCEPTOR),
        ];
        $kept = array_map(static fn(string $file): string => (string) file_get_contents($file), $files);

        try {
            $patched = InfectionPatch::applyIn($vendor);

            return $patched instanceof CannotJudge ? $patched : $then();
        } finally {
            foreach ($files as $at => $file) {
                file_put_contents($file, $kept[$at]);
            }
        }
    }
}

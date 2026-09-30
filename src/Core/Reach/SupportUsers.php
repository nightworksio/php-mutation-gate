<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Reach;

use function array_combine;
use function array_diff_key;
use function array_filter;
use function array_key_exists;
use function array_values;
use function ksort;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Php\Names;
use NightWorksIO\MutationGate\Core\Php\PhpFile;

use function sprintf;

/**
 * The tests that use changed test support: every file of test cases that
 * names a class or function it declares, directly or through other support
 * that names it in turn. Support that runs code when it is loaded acts on
 * tests that never name it, and so does whatever else names it, so either
 * reaches every unit of the package.
 *
 * Every PHP file on disk is read once, and each name any of them mentions
 * leads straight to the files that mention it.
 */
final readonly class SupportUsers
{
    /** Why support that runs code when loaded reaches every unit of its package. */
    private const string RUNS = '`%s` runs code when it is loaded, so every unit of %s is reached.';

    /** Why a file that names support and is not a test reaches every unit of the package. */
    private const string ELSEWHERE = '`%s` names the changed support and is no test, so every unit of %s is reached.';

    /**
     * @param list<array{Path, PhpFile, Role}> $files      every PHP file on disk, read, with what it is to the
     *                                                    tests, at its place
     * @param array<string, int>               $places     each file's place, by its path
     * @param array<string, list<int>>         $mentioning the places of the files that mention each name, by the name
     */
    private function __construct(private array $files, private array $places, private array $mentioning)
    {
    }

    public static function in(Layout $layout, Packages $packages, Sources $sources): self
    {
        $files = [];
        $places = [];
        $mentioning = [];

        foreach ($sources->php() as $place => [$path, $file]) {
            $files[] = [$path, $file, self::roleOf($layout, $packages, $path)];
            $places[$path->value()] = $place;

            foreach ($file->mentioned()->all() as $name) {
                $mentioning[$name][] = $place;
            }
        }

        return new self($files, $places, $mentioning);
    }

    /**
     * The files of test cases that use a changed piece of support, which
     * declares these names; or why every unit of its package is reached.
     */
    public function of(Path $support, Names $declared, string $package): Paths|Reason
    {
        [$naming, $names] = $this->supportNaming($support, $declared);
        $running = $this->runningAny($naming);

        return $running instanceof Path
            ? Reason::that(sprintf(self::RUNS, $running->value(), $package))
            : $this->testsNaming($naming, $names, $package);
    }

    /**
     * The support that names a changed piece of support, through any support
     * that names it in turn, by place, and every name all of it declares.
     *
     * @return array{array<int, int>, array<string, string>}
     */
    private function supportNaming(Path $changed, Names $declared): array
    {
        $support = array_key_exists($changed->value(), $this->places)
            ? [$this->places[$changed->value()] => $this->places[$changed->value()]]
            : [];
        $unread = $declared->all();
        $names = array_combine($unread, $unread);

        while ($unread !== []) {
            $found = array_filter(
                array_diff_key($this->placesMentioning($unread), $support),
                fn(int $place): bool => $this->files[$place][2] === Role::Support,
            );
            $support += $found;
            $unread = $this->declaredAnew($found, $names);
            $names += array_combine($unread, $unread);
        }

        return [$support, $names];
    }

    /**
     * What these files declare that is not among these names.
     *
     * @param  array<int, int>       $places
     * @param  array<string, string> $names
     * @return list<string>
     */
    private function declaredAnew(array $places, array $names): array
    {
        $anew = [];

        foreach ($places as $place) {
            $declared = $this->files[$place][1]->declares()->all();
            $anew += array_diff_key(array_combine($declared, $declared), $names);
        }

        return array_values($anew);
    }

    /**
     * The first of these, by place, that runs code when it is loaded, or the places themselves where none does.
     *
     * @param  array<int, int>       $support
     * @return Path|array<int, int>
     */
    private function runningAny(array $support): Path|array
    {
        ksort($support);

        foreach ($support as $place) {
            [$path, $file] = $this->files[$place];

            if (! $file->onlyDeclares()) {
                return $path;
            }
        }

        return $support;
    }

    /**
     * Every file of test cases outside this support that names any of these, or why every unit is reached.
     *
     * @param array<int, int>       $support
     * @param array<string, string> $names
     */
    private function testsNaming(array $support, array $names, string $package): Paths|Reason
    {
        $naming = array_diff_key($this->placesMentioning($names), $support);
        ksort($naming);
        $tests = [];

        foreach ($naming as $place) {
            [$path, , $role] = $this->files[$place];

            if ($role === Role::Other) {
                return Reason::that(sprintf(self::ELSEWHERE, $path->value(), $package));
            }

            if ($role === Role::TestFile) {
                $tests[] = $path;
            }
        }

        return Paths::of(...$tests);
    }

    /**
     * The places of the files that mention any of these names.
     *
     * @param  array<string> $names
     * @return array<int, int>
     */
    private function placesMentioning(array $names): array
    {
        $places = [];

        foreach ($names as $name) {
            foreach (array_key_exists($name, $this->mentioning) ? $this->mentioning[$name] : [] as $place) {
                $places[$place] = $place;
            }
        }

        return $places;
    }

    private static function roleOf(Layout $layout, Packages $packages, Path $path): Role
    {
        $inPackage = $path->relativeTo($packages->holding($path)->path());

        return match (true) {
            $layout->isTest($inPackage) => Role::TestFile,
            $layout->isSupport($inPackage) => Role::Support,
            default => Role::Other,
        };
    }
}

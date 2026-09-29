<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Reach;

use function count;

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
 */
final readonly class SupportUsers
{
    /** Why support that runs code when loaded reaches every unit of its package. */
    private const string RUNS = '`%s` runs code when it is loaded, so every unit of %s is reached.';

    /** Why a file that names support and is not a test reaches every unit of the package. */
    private const string ELSEWHERE = '`%s` names the changed support and is no test, so every unit of %s is reached.';

    /** @param list<array{Path, PhpFile}> $files every PHP file on disk, read */
    private function __construct(private Layout $layout, private Packages $packages, private array $files)
    {
    }

    public static function in(Layout $layout, Packages $packages, Sources $sources): self
    {
        return new self($layout, $packages, $sources->php());
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
     * that names it in turn, and every name all of it declares.
     *
     * @return array{Paths, Names}
     */
    private function supportNaming(Path $changed, Names $names): array
    {
        $support = Paths::of($changed);

        do {
            $found = count($support);

            foreach ($this->files as [$path, $file]) {
                if ($this->roleOf($path) === Role::Support && $file->mentions($names)) {
                    $support = $support->with($path);
                    $names = $names->merge($file->declares());
                }
            }
        } while (count($support) > $found);

        return [$support, $names];
    }

    /** The first of these that runs code when it is loaded, or the paths themselves where none does. */
    private function runningAny(Paths $support): Path|Paths
    {
        foreach ($this->files as [$path, $file]) {
            if ($support->has($path) && ! $file->onlyDeclares()) {
                return $path;
            }
        }

        return $support;
    }

    /** Every file of test cases outside this support that names any of these, or why every unit is reached. */
    private function testsNaming(Paths $support, Names $names, string $package): Paths|Reason
    {
        $tests = Paths::none();

        foreach ($this->files as [$path, $file]) {
            $role = $support->has($path) || ! $file->mentions($names) ? Role::Unrelated : $this->roleOf($path);

            if ($role === Role::Other) {
                return Reason::that(sprintf(self::ELSEWHERE, $path->value(), $package));
            }

            $tests = $role === Role::Test ? $tests->with($path) : $tests;
        }

        return $tests;
    }

    private function roleOf(Path $path): Role
    {
        $inPackage = $path->relativeTo($this->packages->holding($path)->path());

        return match (true) {
            $this->layout->isTest($inPackage) => Role::Test,
            $this->layout->isSupport($inPackage) => Role::Support,
            default => Role::Other,
        };
    }
}

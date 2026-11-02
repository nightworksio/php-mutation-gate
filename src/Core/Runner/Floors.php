<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Composer\Package;
use NightWorksIO\MutationGate\Core\Config\BuiltinRunner;

use function sprintf;
use function version_compare;

/**
 * The packages a native runner whose framework's extension measures
 * coverage itself drives, each with the first release it drives: the
 * framework at its floor, and php-code-coverage at the release whose map the
 * native runner reads. A version that is not a release, such as a branch,
 * is taken as it is.
 *
 * @internal
 */
final readonly class Floors
{
    /** The first php-code-coverage whose serialized map the native runner reads. */
    private const string CODE_COVERAGE = '14.0.0';

    private const string TOO_OLD = 'The %s runner needs %s %s or later, and %s holds %s.';

    private function __construct(private BuiltinRunner $runner, private string $floor)
    {
    }

    /** The runner's framework, from this release on, measured with php-code-coverage. */
    public static function of(BuiltinRunner $runner, string $floor): self
    {
        return new self($runner, $floor);
    }

    /** @return list<string> the packages whose versions decide how the runner selects, runs and measures tests */
    public function driven(): array
    {
        return [$this->runner->package()->value, Package::CodeCoverage->value];
    }

    /** These versions, which this manifest lists, where none is a release below its floor; or why not. */
    public function held(Versions $versions, string $manifest): Versions|CannotJudge
    {
        foreach ($versions as $version) {
            $floor = $version->package() === Package::CodeCoverage->value ? self::CODE_COVERAGE : $this->floor;

            if ($version->isRelease() && version_compare($version->release(), $floor, '<')) {
                return CannotJudge::because(sprintf(
                    self::TOO_OLD,
                    $this->runner->value,
                    $version->package(),
                    $floor,
                    $manifest,
                    $version->release(),
                ));
            }
        }

        return $versions;
    }
}

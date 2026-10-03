<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Verdict;

use function array_any;
use function array_key_exists;
use function array_map;
use function array_push;
use function count;
use function explode;
use function implode;

use NightWorksIO\MutationGate\Core\Change\Change;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\File\ByPath;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Php\NamedFiles;
use NightWorksIO\MutationGate\Core\Php\PhpFile;
use NightWorksIO\MutationGate\Core\Reach\FileRoles;
use NightWorksIO\MutationGate\Core\Reach\Reason;
use NightWorksIO\MutationGate\Core\Reach\Reasons;

use function sprintf;

/**
 * What a change since the commit a result was established at reaches of the
 * kills it holds (ADR-0008, decision 1): every file that names, as
 * {@see NamedFiles} links files, what a changed file declares, then or now,
 * and every file that names what those declare in turn. A kill whose unit,
 * or a test that killed it, is among them does not stand.
 *
 * A file that is not PHP is named by the words of its name, as a test that
 * reads `fixtures/rates.json` names it, and so, besides what it declares, is
 * a PHP file that runs code when it is loaded, as a file that requires it
 * spells its path. A change the gate cannot follow by name reaches every
 * kill: one to a file that decides how the gate runs, and one to a file
 * Composer's autoloader loads in every process ({@see FileRoles}). What a
 * killing test reads is also judged by its digests, and what every test
 * reads by the mutation digest.
 */
final readonly class ChangeReach
{
    private const string LOADED = <<<'LOADED'
        `%s` is loaded in every process by Composer's autoloader, so what it changes cannot be followed by name.
        LOADED;

    private const string DECIDES = '`%s` decides how the gate runs, so nothing judged before it stands.';

    /** @param array<string, true> $inside every path reached, and every directory one is inside, by its path */
    private function __construct(private Paths $reached, private array $inside, private Reasons $everything)
    {
    }

    /**
     * What these changes reach, each changed file read as it was at the
     * commit and as it is now, over what the files on disk name.
     *
     * @param ByPath<Contents|Missing> $before each changed PHP file as it was at the commit
     * @param ByPath<Contents|Missing> $now    each changed PHP file as it is on disk
     */
    public static function of(
        Changes $changes,
        ByPath $before,
        ByPath $now,
        NamedFiles $graph,
        FileRoles $roles,
    ): self {
        $names = [];
        $everything = Reasons::of();
        $changed = [];

        foreach ($changes as $change) {
            array_push($changed, ...self::pathsOf($change));
        }

        foreach ($changed as $path) {
            $versions = [$before->at($path, Missing::at($path)), $now->at($path, Missing::at($path))];
            $read = self::read($path, $versions, $roles);
            $everything = $read instanceof Reason ? $everything->with($read) : $everything;
            array_push($names, ...($read instanceof Reason ? [] : $read));
        }

        $reached = Paths::of(...$changed, ...array_map(Path::of(...), $graph->naming(...$names)));

        return new self($reached, self::inside($reached), $everything);
    }

    /** Whether the change reaches a unit, a file within the path it holds, or one of these test files. */
    public function reaches(Path $unit, Paths $tests): bool
    {
        return count($this->everything) > 0
            || array_key_exists($unit->value(), $this->inside)
            || array_any([...$tests], fn(Path $test): bool => $this->reached->has($test));
    }

    /** Why the change reaches every kill; none where it follows each by name. */
    public function everything(): Reasons
    {
        return $this->everything;
    }

    /**
     * Every path reached, every directory one is inside, and the root where
     * any is reached: each path a unit can hold that a reached path is within.
     *
     * @return array<string, true>
     */
    private static function inside(Paths $reached): array
    {
        $inside = count($reached) > 0 ? [Path::root()->value() => true] : [];

        foreach ($reached as $path) {
            $directory = [];

            foreach (explode('/', $path->value()) as $segment) {
                $directory[] = $segment;
                $inside[implode('/', $directory)] = true;
            }
        }

        return $inside;
    }

    /** @return list<Path> the path a change touched, and the one it was renamed from */
    private static function pathsOf(Change $change): array
    {
        return $change->previousPath()->equals($change->path())
            ? [$change->path()]
            : [$change->previousPath(), $change->path()];
    }

    /**
     * The names a changed file goes by, then or now, or why what it changes
     * cannot be followed by name.
     *
     * @param  list<Contents|Missing> $versions
     * @return list<string>|Reason
     */
    private static function read(Path $path, array $versions, FileRoles $roles): array|Reason
    {
        return match (true) {
            $roles->decides($path) => Reason::that(sprintf(self::DECIDES, $path->value())),
            $roles->isLoadedEverywhere($path) => Reason::that(sprintf(self::LOADED, $path->value())),
            ! $path->isPhp() => NamedFiles::nameOf($path),
            default => self::declared($path, $versions),
        };
    }

    /**
     * What a PHP file declares, then or now, and the words of its name where
     * either version runs code when it is loaded, as a file that loads it
     * spells its path.
     *
     * @param  list<Contents|Missing> $versions
     * @return list<string>
     */
    private static function declared(Path $path, array $versions): array
    {
        $names = [];
        $runs = false;

        foreach ($versions as $version) {
            if (! $version instanceof Contents) {
                continue;
            }

            $php = PhpFile::read($version);
            $runs = $runs || ! $php->onlyDeclares();
            array_push($names, ...NamedFiles::declaredIn($php));
        }

        return $runs ? [...$names, ...NamedFiles::nameOf($path)] : $names;
    }
}

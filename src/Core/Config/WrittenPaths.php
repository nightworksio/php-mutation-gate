<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function array_map;
use function is_string;

use NightWorksIO\MutationGate\Core\File\Glob;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;

/**
 * Paths a layer holds from the project, as the layer at an origin writes
 * them: `src/Gen/**` from `ci/` is `../src/Gen/**`.
 */
final readonly class WrittenPaths
{
    /**
     * @param  iterable<Glob> $globs
     * @return list<string>
     */
    public static function globs(Origin $origin, iterable $globs): array
    {
        $written = [];

        foreach ($globs as $glob) {
            $written[] = $origin->written(Path::of($glob->value()));
        }

        return $written;
    }

    /**
     * A choice as a layer at this origin writes it: each of these options, which hold paths from the project,
     * a path or a list of them, named from the origin.
     */
    public static function choice(Choice $choice, Origin $origin, string ...$paths): Choice
    {
        $options = $choice->options();
        $from = $options->written();

        foreach ($paths as $name) {
            $path = $options->text(Key::of($name));
            $list = $options->paths(Key::of($name));
            $from = match (true) {
                is_string($path) => $from->with(Member::of($name, $origin->written(Path::of($path)))),
                $list instanceof Paths => $from->with(Member::of(
                    $name,
                    Json::items(...array_map(
                        $origin->written(...),
                        [...$list],
                    )),
                )),
                default => $from,
            };
        }

        return Choice::of($choice->use()->value(), Options::of($from));
    }
}

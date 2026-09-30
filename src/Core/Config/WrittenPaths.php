<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function array_map;

use NightWorksIO\MutationGate\Core\File\Glob;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Kind;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Format\Node;

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
        $options = Node::config($choice->options()->line());
        $from = $choice->options();

        foreach ($paths as $key) {
            $at = $options->field($key);
            $from = match ($at->kind()) {
                Kind::Text => $from->with(Member::of($key, $origin->written(Path::of($at->text())))),
                Kind::List => $from->with(Member::of(
                    $key,
                    Json::items(...array_map(
                        static fn(Node $path): string => $origin->written(Path::of($path->text())),
                        $at->items(),
                    )),
                )),
                Kind::Map, Kind::Empty, Kind::Integer, Kind::Number, Kind::Boolean, Kind::Null, Kind::Nothing => $from,
            };
        }

        return Choice::of($choice->use(), $from);
    }
}

<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use Iterator;
use IteratorAggregate;
use NightWorksIO\MutationGate\Core\Config\Definition\Items;
use NightWorksIO\MutationGate\Core\Config\Definition\Location;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Kind;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\NotGiven;

use function sprintf;

/**
 * The options a config writes beside an adapter, under `with`, read one key
 * at a time as the type it should hold. Each answers its value, `NotGiven`
 * where the key is left out, or the `Problem` at its path where it holds
 * something else. A path is named from where the layer that writes it is, as
 * every path a config writes is, and one that lands outside the project is
 * such a problem. A built-in adapter's options arrive with every default the
 * definition gives them filled in, and their paths named from the project.
 *
 * @implements IteratorAggregate<int, Key>
 */
final readonly class Options implements IteratorAggregate
{
    /** @param list<Problem> $problems */
    private function __construct(
        private Json $with,
        private Node $read,
        private array $problems,
        private PathOrigin $origin,
    ) {
    }

    public static function none(): self
    {
        return self::of(Json::object());
    }

    /** These options, as a JSON object, their paths named from the project; anything else is a problem of theirs. */
    public static function of(Json $with): self
    {
        return self::at($with, ProjectRoot::origin());
    }

    /** These options, as a layer at this origin writes them, their paths named from it. */
    public static function at(Json $with, PathOrigin $origin): self
    {
        return self::read($with, Node::config($with->line()), $origin);
    }

    public function text(Key $key): string|NotGiven|Problem
    {
        $at = $this->read->field($key->value());

        return match ($at->kind()) {
            Kind::Nothing => NotGiven::value(),
            Kind::Text => $at->text(),
            Kind::Map, Kind::List, Kind::Empty, Kind::Integer, Kind::Number, Kind::Boolean, Kind::Null => $at->mismatch(
                'text',
            ),
        };
    }

    public function flag(Key $key): bool|NotGiven|Problem
    {
        $at = $this->read->field($key->value());

        return match ($at->kind()) {
            Kind::Nothing => NotGiven::value(),
            Kind::Boolean => $at->boolean(),
            Kind::Map, Kind::List, Kind::Empty, Kind::Text, Kind::Integer, Kind::Number, Kind::Null => $at->mismatch(
                'true or false',
            ),
        };
    }

    public function integer(Key $key): int|NotGiven|Problem
    {
        $at = $this->read->field($key->value());

        return match ($at->kind()) {
            Kind::Nothing => NotGiven::value(),
            Kind::Integer => $at->integer(),
            Kind::Map, Kind::List, Kind::Empty, Kind::Text, Kind::Number, Kind::Boolean, Kind::Null => $at->mismatch(
                'a whole number',
            ),
        };
    }

    public function number(Key $key): float|NotGiven|Problem
    {
        $at = $this->read->field($key->value());

        return match ($at->kind()) {
            Kind::Nothing => NotGiven::value(),
            Kind::Integer, Kind::Number => $at->number(),
            Kind::Map, Kind::List, Kind::Empty, Kind::Text, Kind::Boolean, Kind::Null => $at->mismatch('a number'),
        };
    }

    /** A path, named from where the layer that writes it is. */
    public function path(Key $key): Path|NotGiven|Problem
    {
        $at = $this->read->field($key->value());

        $reading = Location::path($this->origin)->read($at);
        $path = $reading->value();

        return match (true) {
            ! $at->isPresent() => NotGiven::value(),
            $path instanceof Path => $path,
            default => $reading->problems()[0],
        };
    }

    /** A list of paths, each named from where the layer that writes it is. */
    public function paths(Key $key): Paths|NotGiven|Problem
    {
        $at = $this->read->field($key->value());

        return match ($at->kind()) {
            Kind::Nothing => NotGiven::value(),
            Kind::Empty => Paths::none(),
            Kind::List => $this->pathsIn($at),
            Kind::Map, Kind::Text, Kind::Integer, Kind::Number, Kind::Boolean, Kind::Null => $at->mismatch(
                'a list of paths',
            ),
        };
    }

    /** @return Listed<string>|NotGiven|Problem a list of text */
    public function texts(Key $key): Listed|NotGiven|Problem
    {
        $at = $this->read->field($key->value());

        return match ($at->kind()) {
            Kind::Nothing => NotGiven::value(),
            Kind::Empty => Listed::of(),
            Kind::List => $this->textsIn($at),
            Kind::Map, Kind::Text, Kind::Integer, Kind::Number, Kind::Boolean, Kind::Null => $at->mismatch(
                'a list of text',
            ),
        };
    }

    /** The options under a key that holds an object. */
    public function object(Key $key): self|NotGiven|Problem
    {
        $at = $this->read->field($key->value());
        $under = Json::object();

        foreach ($this->with as $written => $value) {
            $under = $written === $key->value() ? $value : $under;
        }

        return match ($at->kind()) {
            Kind::Nothing => NotGiven::value(),
            Kind::Map, Kind::Empty => self::read($under, $at, $this->origin),
            Kind::List, Kind::Text, Kind::Integer, Kind::Number, Kind::Boolean, Kind::Null => $at->mismatch(
                'an object',
            ),
        };
    }

    /**
     * These options, with a path from the project laid beneath them at this key, spelt as the layer that writes
     * them spells it, so `path()` names it from the project again.
     */
    public function overPath(Key $key, Path $path): self
    {
        return $this->over(Json::object(Member::of($key->value(), $this->origin->written($path))));
    }

    /** These options, with these laid beneath them: each key they leave out takes its value there. */
    public function over(Json $beneath): self
    {
        return self::at($beneath->merged($this->with), $this->origin);
    }

    /** @return Listed<Problem> what is wrong with these options as a whole: that they are not an object */
    public function problems(): Listed
    {
        return Listed::of(...$this->problems);
    }

    /** The options as they are written, as a JSON object. */
    public function written(): Json
    {
        return $this->with;
    }

    /** @return Iterator<int, Key> the key of each option, in the order they are written */
    public function getIterator(): Iterator
    {
        foreach ($this->with as $key => $value) {
            yield Key::of(sprintf('%s', $key));
        }
    }

    private static function read(Json $with, Node $at, PathOrigin $origin): self
    {
        return match ($at->kind()) {
            Kind::Map, Kind::Empty, Kind::Nothing => new self($with, $at, [], $origin),
            Kind::List, Kind::Text, Kind::Integer, Kind::Number, Kind::Boolean, Kind::Null
                => new self(Json::object(), $at, [$at->mismatch('an object')], $origin),
        };
    }

    private function pathsIn(Node $at): Paths|Problem
    {
        $reading = Items::of(Location::path($this->origin))->read($at);
        $paths = $reading->value();

        return $paths instanceof Listed ? Paths::of(...$paths) : $reading->problems()[0];
    }

    /** @return Listed<string>|Problem */
    private function textsIn(Node $at): Listed|Problem
    {
        $texts = [];

        foreach ($at->items() as $item) {
            if ($item->kind() !== Kind::Text) {
                return $item->mismatch('text');
            }

            $texts[] = $item->text();
        }

        return Listed::of(...$texts);
    }
}

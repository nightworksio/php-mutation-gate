<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function array_key_exists;
use function array_keys;
use function ksort;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Kind;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Format\Node;

use function sprintf;
use function str_starts_with;

/**
 * The settings that affect results, serialised canonically (ADR-0007): one
 * line of JSON with every object's keys in byte order, holding nothing that
 * only judges or reports. It is the configuration part of a proof's key.
 */
final readonly class Canonical
{
    /** How a list's entries are named in a setting's path: `trees[].path`. */
    private const string EACH = '[]';

    /** The key an adapter chosen by an object names it by. */
    private const string USE = 'use';

    /** @param array<string, Effect> $effects every setting, by its path, with what it can change */
    private function __construct(private array $effects)
    {
    }

    /**
     * The canonical form of a config as it writes every setting.
     *
     * @param array<string, Effect> $effects every setting, by its path, with what it can change
     */
    public static function of(Json $written, array $effects): string
    {
        $kept = new self($effects)->kept(Node::config($written->line()), $written, '');

        return $kept instanceof Json ? $kept->line() : Json::object()->line();
    }

    /**
     * The part of a value at a path that affects results: all of it where its setting does, but for the
     * settings under it that only judge, and else each part under it that does.
     */
    private function kept(Node $at, Json $as, string $path): Json|string|int|float|bool|Absent
    {
        return match (true) {
            $this->effect($path) === Effect::AffectsResults => $this->whole($at, $as, $path),
            ! $this->holds($path) => Absent::setting(),
            default => $this->parts($at, $as, $path),
        };
    }

    /**
     * A value whose setting affects results, without the settings under it that only judge. An empty object
     * stays an object, and an empty list a list.
     */
    private function whole(Node $at, Json $as, string $path): Json|string|int|float|bool|Absent
    {
        return match ($at->kind()) {
            Kind::Map => $this->chosen($this->members($at, $as, $path, whole: true)),
            Kind::List => Json::items(...$this->items($at, $as, $path, whole: true)),
            Kind::Empty => $as->line() === Json::object()->line() ? Json::object() : Json::items(),
            Kind::Text => $at->text(),
            Kind::Integer => $at->integer(),
            Kind::Number => $at->number(),
            Kind::Boolean => $at->boolean(),
            Kind::Null, Kind::Nothing => Absent::setting(),
        };
    }

    /** The parts of a value under its path that affect results, or nothing where none does. */
    private function parts(Node $at, Json $as, string $path): Json|Absent
    {
        $parts = $at->kind() === Kind::List
            ? Json::items(...$this->items($at, $as, $path, whole: false))
            : $this->members($at, $as, $path, whole: false);

        return $parts->isEmpty() ? Absent::setting() : $parts;
    }

    /** An object of settings, its keys in byte order. */
    private function members(Node $at, Json $as, string $path, bool $whole): Json
    {
        $entries = $at->entries();
        ksort($entries, SORT_STRING);
        $members = Json::object();
        $written = [];

        foreach ($as as $key => $value) {
            $written[sprintf('%s', $key)] = $value;
        }

        foreach ($entries as $key => $member) {
            $under = $path === '' ? $key : sprintf('%s.%s', $path, $key);
            $judged = $this->effect($under) === Effect::JudgesOrReportsOnly;
            $kept = match (true) {
                $whole && $judged => Absent::setting(),
                $whole => $this->whole($member, $written[$key], $under),
                default => $this->kept($member, $written[$key], $under),
            };
            $members = $members->with(Member::of($key, $kept));
        }

        return $members;
    }

    /** @return list<Json|string|int|float|bool> */
    private function items(Node $at, Json $as, string $path, bool $whole): array
    {
        $each = sprintf('%s%s', $path, self::EACH);
        $items = [];
        $written = [...$as];

        foreach ($at->items() as $index => $item) {
            $kept = $whole ? $this->whole($item, $written[$index], $each) : $this->kept($item, $written[$index], $each);

            if (! $kept instanceof Absent) {
                $items[] = $kept;
            }
        }

        return $items;
    }

    /** An adapter chosen by an object that holds only its name is chosen by its name. */
    private function chosen(Json $object): Json|string
    {
        $chosen = Node::config($object->line());
        $use = $chosen->field(self::USE);

        return array_keys($chosen->entries()) === [self::USE] && $use->kind() === Kind::Text ? $use->text() : $object;
    }

    private function effect(string $path): Effect|Absent
    {
        return array_key_exists($path, $this->effects) ? $this->effects[$path] : Absent::setting();
    }

    /** Whether some setting under a path affects results. */
    private function holds(string $path): bool
    {
        foreach ($this->effects as $setting => $effect) {
            $under = $path === ''
                || str_starts_with($setting, sprintf('%s.', $path))
                || str_starts_with($setting, sprintf('%s%s', $path, self::EACH));

            if ($under && $effect === Effect::AffectsResults) {
                return true;
            }
        }

        return false;
    }
}

<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Composer;

use function array_map;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\JsonObject;
use NightWorksIO\MutationGate\Core\Format\Lenient;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Score\Exempt;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Undeclared;

use function sprintf;

/**
 * A manifest's `extra.mutation-gate` entry: the floor it declares for the
 * trees below it and for their new lines (ADR-0005), and the extension
 * classes it names (ADR-0001).
 */
final readonly class GateEntry
{
    /** Where Composer keeps what other tools read from a manifest. */
    private const string EXTRA = 'extra';

    /** The gate's own key under it. */
    private const string KEY = 'mutation-gate';

    private const string FLOOR = 'floor';

    private const string REASON = 'floorReason';

    private const string NEW_CODE = 'newCodeFloor';

    private const string EXTENSIONS = 'extensions';

    /** The lowest floor there is, which needs a reason. */
    private const int NONE = 0;

    /** The highest floor there is. */
    private const int WHOLE = 100;

    private const string NOT_A_FLOOR = '%s: extra.mutation-gate.%s is not a number from 0 to 100.';

    private const string NO_REASON = '%s declares extra.mutation-gate.floor as 0 without a floorReason beside it.';

    private const string NOT_CLASS_NAMES
        = '%s names extra.mutation-gate.extensions, and it is not a list of class names.';

    private function __construct(private Node $entry, private Path $file, private string $origin)
    {
    }

    /**
     * The entry in a decoded manifest, which the file it was read from and
     * the package it names, or that file where it names none, say in messages.
     */
    public static function in(Node $manifest, Path $file, string $origin): self
    {
        return new self($manifest->field(self::EXTRA)->field(self::KEY), $file, $origin);
    }

    /**
     * A decoded manifest's JSON with this entry taken out, and every other
     * member as it was read.
     */
    public static function removedFrom(Node $manifest): string
    {
        $members = [];

        foreach (Lenient::entries($manifest) as $key => $member) {
            $members[$key] = $key === self::EXTRA ? self::extraWithout($member) : $member->json();
        }

        return JsonObject::of($members);
    }

    /** The floor `floor` declares, and a floor of 0 exempt for the `floorReason` beside it. */
    public function floor(): Floor|Exempt|Undeclared|CannotJudge
    {
        $floor = $this->floorAt(self::FLOOR);
        $reason = Lenient::text($this->entry->field(self::REASON));

        return match (true) {
            ! $floor instanceof Floor || $floor->hundredths() !== self::NONE => $floor,
            $reason !== '' => Exempt::because($reason),
            default => CannotJudge::because(sprintf(self::NO_REASON, $this->file->value())),
        };
    }

    /** The floor `newCodeFloor` declares for the new lines of the trees below the manifest. */
    public function newCodeFloor(): Floor|Undeclared|CannotJudge
    {
        return $this->floorAt(self::NEW_CODE);
    }

    /** The extension classes `extensions` names, in its order. */
    public function extensions(): Names|CannotJudge
    {
        $listed = $this->entry->field(self::EXTENSIONS);

        try {
            return Names::of(...array_map(static fn(Node $class): string => $class->text(), $listed->items()));
        } catch (NotInShape) {
            return $listed->isPresent()
                ? CannotJudge::because(sprintf(self::NOT_CLASS_NAMES, $this->origin))
                : Names::of();
        }
    }

    private function floorAt(string $key): Floor|Undeclared|CannotJudge
    {
        $place = $this->entry->field($key);

        try {
            $percent = $place->number();
        } catch (NotInShape) {
            return $place->isPresent() ? $this->notAFloor($key) : Undeclared::floor();
        }

        return $percent >= self::NONE && $percent <= self::WHOLE ? Floor::of($percent) : $this->notAFloor($key);
    }

    private function notAFloor(string $key): CannotJudge
    {
        return CannotJudge::because(sprintf(self::NOT_A_FLOOR, $this->file->value(), $key));
    }

    /** A manifest's `extra` without the gate's key, or as it was where it is not a map. */
    private static function extraWithout(Node $extra): string
    {
        try {
            $members = $extra->entries();
        } catch (NotInShape) {
            return $extra->json();
        }

        unset($members[self::KEY]);

        return JsonObject::of(array_map(static fn(Node $member): string => $member->json(), $members));
    }
}

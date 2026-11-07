<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function array_map;

use NightWorksIO\MutationGate\Core\Composer\DecidingPackages;
use NightWorksIO\MutationGate\Core\File\Glob;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;

use function sprintf;

/**
 * `reach.lockMoves` and `reach.lockPackages`: what a package's move in a `composer.lock` reaches (ADR-0030, ADR-0115).
 *
 * @internal
 */
final readonly class LockReach
{
    /** @param Listed<string>|Absent $packages */
    private function __construct(private Listed|Absent $packages, private LockMoveReach|Absent $moves)
    {
    }

    /**
     * What these keys say; a layer that lists packages and says nothing else reaches by their names.
     *
     * @param Listed<string>|Absent $packages
     */
    public static function of(Listed|Absent $packages, LockMoveReach|Absent $moves): self
    {
        return new self(
            $packages,
            $moves instanceof Absent && $packages instanceof Listed ? LockMoveReach::Named : $moves,
        );
    }

    public function over(self $later): self
    {
        return new self(
            match (true) {
                $later->packages instanceof Absent => $this->packages,
                $this->packages instanceof Absent => $later->packages,
                default => $this->packages->and($later->packages, static fn(string $name): string => $name),
            },
            Absent::laid($this->moves, $later->moves),
        );
    }

    /** What a move decides: every package; those the config names; or none alone, a move reaching what loaded it. */
    public function deciding(): DecidingPackages
    {
        return match ($this->moves) {
            LockMoveReach::Named => DecidingPackages::named(...array_map(
                Glob::of(...),
                $this->packages instanceof Listed ? [...$this->packages] : [],
            )),
            LockMoveReach::Loaded => DecidingPackages::byLoads(),
            default => DecidingPackages::every(),
        };
    }

    /** @return list<Member> the keys a config writes */
    public function members(): array
    {
        return [
            Member::of('lockMoves', $this->moves instanceof LockMoveReach ? $this->moves->value : $this->moves),
            Member::of(
                'lockPackages',
                $this->packages instanceof Listed ? Json::items(...$this->packages) : $this->packages,
            ),
        ];
    }

    /** @return list<string> the builder's calls that set them */
    public function php(): array
    {
        return [
            ...($this->moves instanceof LockMoveReach
                ? [sprintf('Reach::lockMoves(LockMoveReach::%s)', $this->moves->name)]
                : []),
            ...($this->packages instanceof Listed
                ? [sprintf('Reach::lockPackages(%s)', PhpCalls::literals(...$this->packages))]
                : []),
        ];
    }
}

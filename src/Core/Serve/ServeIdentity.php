<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Serve;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;

/**
 * What a daemon of `mutation-gate serve` was started from, and what a
 * client asks it with (ADR-0078): the project's root by its real path, the
 * gate's version, and the digests of composer.lock and of the config file.
 * A daemon serves only its own root, and stops once the version, the lock
 * or the config differ from those it started from.
 *
 * @internal
 */
final readonly class ServeIdentity
{
    private const string VERSION = 'The gate\'s version';

    private const string LOCK = 'The project\'s composer.lock';

    private const string CONFIG = 'The project\'s config';

    private function __construct(
        private string $root,
        private string $version,
        private string $lock,
        private string $config,
    ) {
    }

    /** By the root's real path, the gate's version, and the digests of the lock and the config, empty where absent. */
    public static function of(string $root, string $version, string $lock, string $config): self
    {
        return new self($root, $version, $lock, $config);
    }

    /** @throws NotInShape */
    public static function read(Node $at): self
    {
        return new self(
            $at->field('root')->text(),
            $at->field('version')->text(),
            $at->field('lock')->text(),
            $at->field('config')->text(),
        );
    }

    public function root(): string
    {
        return $this->root;
    }

    /** Whether it is of the same project root as another. */
    public function isOfRootOf(self $other): bool
    {
        return $this->root === $other->root;
    }

    /** What changed since a daemon started from the other, of its root; nothing where nothing did. */
    public function changedSince(self $started): string
    {
        return match (true) {
            $this->version !== $started->version => self::VERSION,
            $this->lock !== $started->lock => self::LOCK,
            $this->config !== $started->config => self::CONFIG,
            default => '',
        };
    }

    public function written(): Json
    {
        return Json::object(
            Member::of('root', $this->root),
            Member::of('version', $this->version),
            Member::of('lock', $this->lock),
            Member::of('config', $this->config),
        );
    }
}

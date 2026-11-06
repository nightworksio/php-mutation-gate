<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof\Key;

use function hash_update;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\Format\Kind;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\ThisPackage;

use function sprintf;
use function str_starts_with;

/**
 * The digest of what Composer installed, as `vendor/composer/installed.json`
 * lists it: each package it lists, less the gate's own, whose code a key
 * reads by {@see VerdictCode} (ADR-0084); the text as it is where it lists
 * none, and nothing where it is not there.
 *
 * @internal
 */
final readonly class InstalledPackages
{
    public static function digestOf(Contents|Missing|CannotJudge $installed): Digest
    {
        if (! $installed instanceof Contents) {
            return Digest::sha256Of('');
        }

        try {
            $packages = Node::decode($installed->text())->field('packages')->items();
        } catch (NotInShape) {
            return Digest::sha256Of($installed->text());
        }

        $context = Digest::hashing();

        foreach ($packages as $package) {
            $name = $package->field('name');
            $theGates = $name->kind() === Kind::Text && self::isTheGates($name->text());
            hash_update($context, $theGates ? '' : sprintf("%s\n", $package->json()));
        }

        return Digest::finished($context);
    }

    private static function isTheGates(string $name): bool
    {
        return $name === ThisPackage::COMPOSER || str_starts_with($name, VerdictCode::PLUGINS);
    }
}

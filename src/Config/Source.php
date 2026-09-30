<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use function array_values;

use NightWorksIO\MutationGate\Core\Config\Definition\Json;

/** `treeSource`: where the trees come from when the config lists none (ADR-0005). */
final readonly class Source
{
    private function __construct(private string $json)
    {
    }

    /**
     * The `<source>` of `phpunit.xml`, and without one, these paths; with no paths, the `autoload` paths of
     * `composer.json`.
     */
    public static function phpunit(string ...$fallback): self
    {
        return $fallback === []
            ? new self(Json::encode('phpunit'))
            : new self(Json::encode(['use' => 'phpunit', 'with' => ['fallback' => array_values($fallback)]]));
    }

    /** One tree per `autoload` path of `composer.json`. */
    public static function composer(): self
    {
        return self::uses('composer');
    }

    /** A tree source another extension registers by name, or a class, with its options. */
    public static function uses(string $source, Option ...$options): self
    {
        return new self(Option::choice($source, ...$options));
    }

    public function written(): string
    {
        return $this->json;
    }
}

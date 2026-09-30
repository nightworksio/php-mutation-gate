<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use function array_values;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;

/** `treeSource`: where the trees come from when the config lists none (ADR-0005). */
final readonly class Source
{
    private function __construct(private Json|string $json)
    {
    }

    /**
     * The `<source>` of `phpunit.xml`, and without one, these paths; with no paths, the `autoload` paths of
     * `composer.json`.
     */
    public static function phpunit(string ...$fallback): self
    {
        return $fallback === []
            ? new self('phpunit')
            : new self(Json::object(Member::of('use', 'phpunit'))->with(
                Member::of(
                    'with',
                    Json::object(Member::of('fallback', Json::items(...array_values($fallback)))),
                ),
            ));
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

    public function written(): Json|string
    {
        return $this->json;
    }
}

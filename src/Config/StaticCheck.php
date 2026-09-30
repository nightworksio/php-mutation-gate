<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use NightWorksIO\MutationGate\Core\Config\BuiltinAnalyser;
use NightWorksIO\MutationGate\Core\Config\StaticCheck as CoreStaticCheck;
use NightWorksIO\MutationGate\Core\Format\Json;

/**
 * `staticCheck`: the static analyser that may kill a mutant before or after
 * its tests, and the config it reads (ADR-0020).
 */
final readonly class StaticCheck implements Setting
{
    private function __construct(private Json $json)
    {
    }

    /** The first analyser installed and configured, Mago, then PHPStan, then Psalm: the default. */
    public static function auto(): self
    {
        return self::uses(CoreStaticCheck::AUTO);
    }

    /** No analyser: every mutant goes to its tests alone. */
    public static function none(): self
    {
        return self::uses(CoreStaticCheck::NONE);
    }

    public static function mago(): self
    {
        return self::uses(BuiltinAnalyser::Mago->value);
    }

    public static function phpstan(): self
    {
        return self::uses(BuiltinAnalyser::PhpStan->value);
    }

    public static function psalm(): self
    {
        return self::uses(BuiltinAnalyser::Psalm->value);
    }

    /** An analyser another extension registers by name, or a class, with its options. */
    public static function uses(string $analyser, Option ...$options): self
    {
        return new self(Json::at('staticCheck.tool', Option::choice($analyser, ...$options)));
    }

    /** `staticCheck.config`: the config the analyser reads, where it is not the one it finds itself. */
    public static function config(string $path): self
    {
        return new self(Json::at('staticCheck.config', $path));
    }

    public function written(): Json
    {
        return $this->json;
    }
}

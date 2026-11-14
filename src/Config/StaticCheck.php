<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use NightWorksIO\MutationGate\Core\Config\BuiltinAnalyser;
use NightWorksIO\MutationGate\Core\Config\StaticCheck as CoreStaticCheck;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;

/**
 * `staticCheck`: the static analyser that may kill a mutant, the config it
 * reads and how it runs (ADR-0020); when it checks is CheckTiming's.
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

    /** `staticCheck.seconds`: how long one check may take, and Psalm's language server to start. */
    public static function seconds(int $seconds): self
    {
        return new self(Json::at('staticCheck.seconds', $seconds));
    }

    /** `staticCheck.opcache.batch`: the programs one child compiles; 100. */
    public static function opcacheBatch(int $programs): self
    {
        return new self(Json::at('staticCheck.opcache.batch', $programs));
    }

    /** `staticCheck.opcache.limit`: how long one child may take to compile its programs, such as `1m`. */
    public static function opcacheLimit(string $duration): self
    {
        return new self(Json::at('staticCheck.opcache.limit', $duration));
    }

    /** `staticCheck.dependents`: how many dependents of a changed file an analyser reads at most, 25 by default. */
    public static function dependents(int $files): self
    {
        return new self(Json::at('staticCheck.dependents', $files));
    }

    /**
     * `staticCheck.php`: the ini settings each check runs under, in place of the default's pcov off and opcache on
     * with a file cache alone; none of them changes a finding.
     */
    public static function php(Option ...$settings): self
    {
        return new self(Json::object(
            Member::of('staticCheck', Json::object(Member::of('php', Option::object(...$settings)))),
        ));
    }

    /** `staticCheck.turbo`, `true`: PHPStan's checks load its turbo extension where it ships one, as by default. */
    public static function turbo(): self
    {
        return new self(Json::at('staticCheck.turbo', value: true));
    }

    /** `staticCheck.turbo`, `false`: PHPStan's checks load no turbo extension. */
    public static function withoutTurbo(): self
    {
        return new self(Json::at('staticCheck.turbo', value: false));
    }

    public function written(): Json
    {
        return $this->json;
    }
}

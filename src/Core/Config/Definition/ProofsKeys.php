<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use NightWorksIO\MutationGate\Core\Config\ByteLimits;
use NightWorksIO\MutationGate\Core\Config\Effect;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\PathOrigin;
use NightWorksIO\MutationGate\Core\Config\Proofs;
use NightWorksIO\MutationGate\Core\Config\Retained;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Proof\Compression;
use NightWorksIO\MutationGate\Core\Proof\Copies;
use NightWorksIO\MutationGate\Core\Proof\LedgerCodec;
use NightWorksIO\MutationGate\Core\Proof\Writing;

/**
 * How the keys a config writes are read into its `Proofs` part (ADR-0002).
 *
 * @internal
 */
final readonly class ProofsKeys
{
    /** @return list<Field<Layer>> */
    public static function fields(PathOrigin $origin): array
    {
        $decides = Effect::DecidesHowTheGateRuns;
        $store = Field::optional('store', Adapter::choosing(Builtins::stores($origin)), $decides);
        $ignore = Field::optional('ignore', Items::of(Pattern::glob($origin)), $decides);
        $write = Field::optional('write', Enumerated::of(Writing::cases()), $decides);
        $copies = Field::optional('copies', Enumerated::of(Copies::cases()), $decides);
        $ownScope = Field::optional('ownScope', Flag::boolean(), Effect::AffectsResults);
        $judges = Effect::JudgesOrReportsOnly;
        $compression = Field::optional('compression', Enumerated::of(Compression::cases()), $judges);
        $level = Field::optional('compressionLevel', Integer::between(1, LedgerCodec::MOST_LEVEL), $judges);
        $keep = Field::section('keep', self::keep());
        $limits = Field::section('limits', self::limits());

        return [Field::section(
            'proofs',
            Section::of(
                static function (Node $proofs) use (
                    $store,
                    $ignore,
                    $write,
                    $copies,
                    $ownScope,
                    $compression,
                    $level,
                    $keep,
                    $limits,
                ): Layer|Invalid {
                    $kept = $store->read($proofs);
                    $left = $ignore->read($proofs);
                    $writing = $write->read($proofs);
                    $chosen = $copies->read($proofs);
                    $trusted = $ownScope->read($proofs);
                    $packed = $compression->read($proofs);
                    $packedAt = $level->read($proofs);
                    $retained = $keep->read($proofs);
                    $limited = $limits->read($proofs);

                    return Reading::built(
                        static fn(): Layer => Layer::of(Proofs::of(
                            $kept->value(),
                            $left->value(),
                            $writing->value(),
                            copies: $chosen->value(),
                            ownScope: $trusted->value(),
                            compression: $packed->value(),
                            compressionLevel: $packedAt->value(),
                            retained: $retained->must()->over($limited->must()),
                        )),
                        $kept,
                        $left,
                        $writing,
                        $chosen,
                        $trusted,
                        $packed,
                        $packedAt,
                        $retained,
                        $limited,
                    );
                },
                $store,
                $ignore,
                $write,
                $copies,
                $ownScope,
                $compression,
                $level,
                $keep,
                $limits,
            ),
        )];
    }

    /** @return Section<Retained> */
    private static function keep(): Section
    {
        $judges = Effect::JudgesOrReportsOnly;
        $killers = Field::optional('killers', new CountOrAll(), $judges);
        $bases = Field::optional('bases', Integer::atLeast(1), $judges);
        $proofs = Field::optional('proofs', Integer::atLeast(1), $judges);
        $functions = Field::optional('functions', Integer::atLeast(1), $judges);

        return Section::of(
            static function (Node $keep) use ($killers, $bases, $proofs, $functions): Retained|Invalid {
                $mutants = $killers->read($keep);
                $seen = $bases->read($keep);
                $newest = $proofs->read($keep);
                $learned = $functions->read($keep);

                return Reading::built(
                    static fn(): Retained => Retained::of(
                        $mutants->value(),
                        $seen->value(),
                        $newest->value(),
                        $learned->value(),
                    ),
                    $mutants,
                    $seen,
                    $newest,
                    $learned,
                );
            },
            $killers,
            $bases,
            $proofs,
            $functions,
        );
    }

    /** @return Section<Retained> */
    private static function limits(): Section
    {
        $ledger = Field::section('ledger', self::bytes());
        $map = Field::section('map', self::bytes());
        $handoff = Field::section('handoff', self::bytes());

        return Section::of(
            static function (Node $limits) use ($ledger, $map, $handoff): Retained|Invalid {
                $ledgers = $ledger->read($limits);
                $maps = $map->read($limits);
                $handed = $handoff->read($limits);

                return Reading::built(
                    static fn(): Retained => Retained::of(
                        ledgerPacked: $ledgers->must()->packed(),
                        ledgerUnpacked: $ledgers->must()->unpacked(),
                        mapPacked: $maps->must()->packed(),
                        mapUnpacked: $maps->must()->unpacked(),
                        handoffPacked: $handed->must()->packed(),
                        handoffUnpacked: $handed->must()->unpacked(),
                    ),
                    $ledgers,
                    $maps,
                    $handed,
                );
            },
            $ledger,
            $map,
            $handoff,
        );
    }

    /** @return Section<ByteLimits> */
    private static function bytes(): Section
    {
        $judges = Effect::JudgesOrReportsOnly;
        $packed = Field::optional('packed', Integer::atLeast(1), $judges);
        $unpacked = Field::optional('unpacked', Integer::atLeast(1), $judges);

        return Section::of(
            static function (Node $bytes) use ($packed, $unpacked): ByteLimits|Invalid {
                $compressed = $packed->read($bytes);
                $whole = $unpacked->read($bytes);

                return Reading::built(
                    static fn(): ByteLimits => ByteLimits::of($compressed->value(), $whole->value()),
                    $compressed,
                    $whole,
                );
            },
            $packed,
            $unpacked,
        );
    }
}

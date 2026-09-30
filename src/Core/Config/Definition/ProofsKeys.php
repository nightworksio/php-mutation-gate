<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use NightWorksIO\MutationGate\Core\Config\Effect;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\PathOrigin;
use NightWorksIO\MutationGate\Core\Config\Proofs;
use NightWorksIO\MutationGate\Core\Config\ProofWriting;
use NightWorksIO\MutationGate\Core\Format\Node;

/** How the keys a config writes are read into its `Proofs` part (ADR-0002). */
final readonly class ProofsKeys
{
    /** @return list<Field<Layer>> */
    public static function fields(PathOrigin $origin): array
    {
        $judges = Effect::JudgesOrReportsOnly;
        $store = Field::optional('store', Adapter::choosing(Builtins::stores($origin)), $judges);
        $ignore = Field::optional('ignore', Items::of(Pattern::glob($origin)), $judges);
        $write = Field::optional('write', Enumerated::of(ProofWriting::cases()), $judges);

        return [Field::section(
            'proofs',
            Section::of(
                static function (Node $proofs) use ($store, $ignore, $write): Layer|Invalid {
                    $kept = $store->read($proofs);
                    $left = $ignore->read($proofs);
                    $writing = $write->read($proofs);

                    return Reading::built(
                        static fn(): Layer => Layer::of(Proofs::of(
                            $kept->value(),
                            $left->value(),
                            $writing->value(),
                        )),
                        $kept,
                        $left,
                        $writing,
                    );
                },
                $store,
                $ignore,
                $write,
            ),
        )];
    }
}

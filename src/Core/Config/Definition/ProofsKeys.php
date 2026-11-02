<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use NightWorksIO\MutationGate\Core\Config\Effect;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\PathOrigin;
use NightWorksIO\MutationGate\Core\Config\Proofs;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Proof\Copies;
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

        return [Field::section(
            'proofs',
            Section::of(
                static function (Node $proofs) use ($store, $ignore, $write, $copies): Layer|Invalid {
                    $kept = $store->read($proofs);
                    $left = $ignore->read($proofs);
                    $writing = $write->read($proofs);
                    $chosen = $copies->read($proofs);

                    return Reading::built(
                        static fn(): Layer => Layer::of(Proofs::of(
                            $kept->value(),
                            $left->value(),
                            $writing->value(),
                            copies: $chosen->value(),
                        )),
                        $kept,
                        $left,
                        $writing,
                        $chosen,
                    );
                },
                $store,
                $ignore,
                $write,
                $copies,
            ),
        )];
    }
}

<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Outbreak;

use function array_map;

use Closure;

use function json_encode;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\JsonText;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Proof\CacheRequest;
use stdClass;

/**
 * What the gate asks `outbreak cache pull` and `outbreak cache push` of the
 * proof cache's packs (ADR-0131), as compact JSON on the binary's input: the
 * store, its registry where one is named, the base, whether only the newest
 * entry is kept, the limits, and each class's pack and where the Actions
 * cache restored it, at their paths on disk.
 *
 * @internal
 */
final readonly class CacheAsked
{
    /** @param Closure(Path): string $absolute where a path of the project is on disk */
    public static function of(CacheRequest $request, Closure $absolute): string
    {
        $registry = $request->registry();
        $packs = array_map($absolute, $request->packs());
        $restored = array_map($absolute, $request->restored());

        return json_encode([
            'store' => $request->store()->value,
            ...$registry instanceof NotGiven ? [] : ['registry' => $registry],
            'base' => $request->base()->value(),
            ...$request->isNewest() ? ['newest' => true] : [],
            'limits' => ['packed' => $request->limits()->packed(), 'unpacked' => $request->limits()->unpacked()],
            'packs' => $packs === [] ? new stdClass() : $packs,
            'restored' => $restored === [] ? new stdClass() : $restored,
        ], JsonText::FLAGS);
    }
}

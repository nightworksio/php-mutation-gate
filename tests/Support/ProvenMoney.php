<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use NightWorksIO\MutationGate\Cli\Flow\StaticEquivalence;
use NightWorksIO\MutationGate\Config\Setting;

/** The money fixture a static check proves, and what it proves of it, as the tests of static equivalence read them. */
final readonly class ProvenMoney
{
    public const string EQUIVALENT = "<?php\n\nfinal  class Money\n{\n}\n";

    /** @return list<string> the native ids of the mutants of src/Money.php a check proves equivalent */
    public static function proven(ScriptedRunner $runner, string $project = '', Setting ...$settings): array
    {
        $project = $project === '' ? Flows::project() : $project;
        $mutants = Flows::mutantsOf('src/Money.php');
        $proven = new StaticEquivalence(Flows::adapters($project, [], $runner), Flows::settings(...$settings))->proven($mutants);
        $native = [];

        foreach ($mutants as $mutant) {
            $native = $proven->proven->has($mutant->id()) ? [...$native, $mutant->nativeId()] : $native;
        }

        return $native;
    }
}

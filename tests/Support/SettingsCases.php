<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

/** The configs the tests of Settings read: the defaults, and one that writes every setting. */
final readonly class SettingsCases
{
    /** The effective config of `{"runner": "pest"}`: every other setting at its default. */
    public const string DEFAULTS = <<<'JSON'
    {
        "extensions": [],
        "runner": {
            "use": "pest",
            "memory": "1G"
        },
        "mutators": {
            "sets": [],
            "except": []
        },
        "treeSource": {
            "use": "phpunit",
            "with": {
                "fallback": []
            }
        },
        "newCode": {
            "floor": 100
        },
        "uncovered": "count",
        "baseline": {
            "path": "mutation-gate.baseline.json",
            "improvement": "require"
        },
        "packages": [],
        "reach": {
            "everything": []
        },
        "holds": {
            "hotPath": 0.8
        },
        "run": {
            "full": false
        },
        "pruning": {
            "enabled": true,
            "window": 500,
            "audit": "7d"
        },
        "shards": {
            "seconds": 600,
            "max": 20,
            "setup": "1m"
        },
        "costs": {
            "secondsPerLine": {
                "": 0.2
            }
        },
        "ci": {
            "check": "mutation / verdict",
            "trustMergedPullRequests": false,
            "gitlab": {
                "template": ".gitlab/mutation-gate.yml"
            },
            "buildkite": {
                "step": {},
                "definition": ".buildkite/pipeline.yml"
            },
            "azure": {
                "definition": "azure-pipelines.yml"
            },
            "bitbucket": {
                "definition": "bitbucket-pipelines.yml"
            },
            "jenkins": {
                "definition": "Jenkinsfile"
            }
        },
        "proofs": {
            "store": {
                "use": "directory",
                "with": {
                    "path": ".mutation-gate/ledger"
                }
            },
            "ignore": [],
            "write": "auto"
        },
        "coverage": {
            "incremental": true
        },
        "timeouts": {
            "mode": "confirm",
            "seconds": 10,
            "most": 300,
            "tighter": {
                "mutators": [
                    "RemoveArrayItem",
                    "DecrementInteger",
                    "IncrementInteger",
                    "ForeachEmptyIterable",
                    "UnwrapArrayValues",
                    "InstanceOfToTrue",
                    "InstanceOfToFalse",
                    "TernaryNegated",
                    "ArrayItemRemoval",
                    "Foreach_",
                    "InstanceOf_",
                    "Ternary"
                ],
                "floor": 7
            }
        },
        "flaky": {
            "confirmSurvivors": true
        },
        "tests": {
            "order": "killers-first"
        },
        "survivorsFirst": {
            "max": 20
        },
        "ignores": {
            "entries": [],
            "native": "refuse"
        },
        "equivalence": {
            "static": true
        },
        "reports": [],
        "badge": {
            "colors": {
                "brightgreen": 90,
                "green": 80,
                "yellow": 70,
                "orange": 60
            }
        },
        "pest": {
            "patch": false,
            "canary": "mutation-canary"
        },
        "staticCheck": {
            "tool": "auto",
            "seconds": 60,
            "before": false
        },
        "local": {
            "watchBudget": "1m",
            "prePushBudget": "5m"
        }
    }
    JSON;

    /** A config that sets every setting away from its default. */
    public const array EVERYTHING = [
        '$schema' => 'resources/mutation-gate.schema.json',
        'extensions' => ['Acme\\GateSlack\\SlackExtension'],
        'preset' => ['laravel', 'acme'],
        'runner' => ['use' => 'infection', 'with' => [], 'withhold' => ['DEPLOY_*', 'COMPOSER_AUTH'], 'memory' => '512m', 'workers' => 'fresh'],
        'treeSource' => ['use' => 'phpunit', 'with' => ['fallback' => ['app', 'lib']]],
        'mutators' => ['sets' => ['acme', 'acme-auth'], 'except' => ['acme/RemoveAudit']],
        'trees' => [
            ['path' => 'app/Domain', 'floor' => 100],
            ['path' => 'app/Http', 'floor' => 83.419],
            ['path' => './app/Generated/', 'floor' => 0, 'reason' => 'Generated on every build'],
            ['path' => 'app/Legacy'],
        ],
        'newCode' => ['floor' => 90],
        'security' => ['floor' => 97.5],
        'uncovered' => 'exclude',
        'baseline' => ['path' => 'build/baseline.json', 'improvement' => 'report'],
        'packages' => ['packages/*'],
        'reach' => ['everything' => ['config/**', 'routes/**']],
        'holds' => ['hotPath' => 1],
        'shards' => ['seconds' => 900, 'max' => 8],
        'costs' => ['secondsPerLine' => ['' => 0.25, 'src/Legacy' => 2]],
        'ci' => [
            'plan' => 'gitlab',
            'defaultBranch' => 'trunk',
            'check' => 'gate / verdict',
            'trustMergedPullRequests' => true,
            'gitlab' => ['template' => '.gitlab/gate.yml'],
            'buildkite' => ['step' => ['agents' => ['queue' => 'mutation']], 'definition' => '.buildkite/mutation.yml'],
            'azure' => ['definition' => 'ci/azure.yml'],
            'bitbucket' => ['definition' => 'ci/bitbucket.yml'],
            'jenkins' => ['definition' => 'ci/Jenkinsfile'],
        ],
        'proofs' => [
            'store' => ['use' => 's3', 'with' => ['bucket' => 'proofs', 'endpoint' => 'https://r2.example.com']],
            'ignore' => ['docs/**'],
            'write' => 'never',
        ],
        'coverage' => ['incremental' => false],
        'budget' => '1h30m',
        'timeouts' => ['mode' => 'unjudged', 'seconds' => 30, 'most' => 120],
        'flaky' => ['confirmSurvivors' => false],
        'tests' => ['suites' => ['Unit', 'Plugins', 'Unit'], 'holding' => ['Process']],
        'survivorsFirst' => ['max' => 5],
        'ignores' => [
            'entries' => [
                ['mutant' => '3f9a1c2b7d04', 'reason' => 'Both branches build the same list', 'expires' => '2026-12-29'],
                [
                    'path' => 'src/Log/**',
                    'mutator' => 'MethodCallRemoval',
                    'reason' => 'Logging is asserted elsewhere',
                    'expires' => '2026-10-01',
                ],
            ],
            'maxDays' => 90,
            'native' => 'allow',
        ],
        'reports' => [
            ['use' => 'sarif', 'path' => 'build/mutation.sarif'],
            ['use' => 'Acme\\GateSlack\\SlackReporter', 'with' => ['channel' => '#ci']],
        ],
        'badge' => ['colors' => ['green' => 95]],
        'pest' => ['patch' => true, 'canary' => 'canary'],
        'staticCheck' => ['tool' => 'phpstan', 'config' => 'phpstan.dist.neon', 'seconds' => 45, 'before' => true],
        'pruning' => ['enabled' => false, 'window' => 200, 'audit' => '3d'],
        'local' => ['watchBudget' => '2m', 'prePushBudget' => '90s'],
    ];
}

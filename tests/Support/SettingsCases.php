<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

/** The configs the tests of Settings read: the defaults, and one that writes every setting. */
final readonly class SettingsCases
{
    /** The options the Pest runner reads where a config sets none of them. */
    public const string PEST_DEFAULTS = '{"patch": false, "canary": "mutation-canary", "bootTail": false, "workerOpcache": true, "schemata": true, "bootMutants": true, "bootChunks": false, "narrowedKills": "vouch", "longFilter": "files", "preloaded": "fresh", "splitStream": {"enabled": false, "children": 1, "changeCheck": true, "killersFirst": true, "killerStreams": false, "bootOnce": false, "reconnect": false}, "outbreak": {"enabled": true, "timeout": 300.0, "equivalence": true, "forkStates": false, "recheckTouchedKills": true, "lines": true, "steps": true, "stepFloor": 10000000, "cache": {"store": "auto", "trust": "default-branch"}}, "duplicates": "judge-once", "trials": true, "valueReaders": true, "narrow": true, "shardScan": true, "longestFirst": true, "remember": true, "overlapSteps": true, "pcovOff": true, "xdebugOff": true}';

    /** The effective config of `{"runner": "pest"}`: every other setting at its default. */
    public const string DEFAULTS = <<<'JSON'
    {
        "extensions": [],
        "runner": {
            "use": "pest",
            "withhold": [],
            "memory": "1G",
            "workers": "fork",
            "pathRules": "partitioned",
            "recycleAfter": 16,
            "warmSlack": "1m",
            "poll": 0.01
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
        "paths": [],
        "newCode": {
            "floor": 100
        },
        "uncovered": "count",
        "baseline": {
            "path": "mutation-gate.baseline.json",
            "improvement": "require"
        },
        "owners": {
            "floors": {},
            "mention": "first"
        },
        "packages": [],
        "reach": {
            "everything": [],
            "changedTests": "narrow",
            "fixtures": [],
            "defaultBranchSince": "judged",
            "lockMoves": "everything"
        },
        "holds": {
            "hotPath": 0.8,
            "fewestTests": 20,
            "coverage": "measure"
        },
        "run": {
            "full": false,
            "failFast": "mark",
            "failFastWaits": [
                "out-of-time",
                "failed"
            ]
        },
        "pruning": {
            "enabled": true,
            "window": 500,
            "audit": "7d"
        },
        "sampling": {
            "confidence": 95,
            "margin": 2.0,
            "minUnits": 30,
            "auto": "off",
            "autoRuns": [
                "pullRequests"
            ]
        },
        "shards": {
            "seconds": 600,
            "max": 20,
            "learnedFrom": "max",
            "setup": "1m",
            "cutAt": 2.0,
            "jobLimit": "5h40m",
            "pull": "auto"
        },
        "costs": {
            "secondsPerLine": {
                "": 0.2
            },
            "savingsWindow": "30d",
            "testsBeforeAKill": 5,
            "surviving": 0.02,
            "shardOverhead": 2.5
        },
        "plan": {
            "chunkMinutes": 15,
            "provingRange": 20
        },
        "timing": {
            "newest": 0.9,
            "startUpSamples": 3
        },
        "ci": {
            "check": "mutation / verdict",
            "schedule": "0 3 * * 1",
            "trustMergedPullRequests": false,
            "mergedOwnScopeProofs": "reach",
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
            "write": "auto",
            "copies": "newest",
            "compression": "auto",
            "compressionLevel": 9,
            "keep": {
                "killers": "all",
                "bases": 5,
                "proofs": 20000,
                "functions": 5000,
                "kills": 2000,
                "runs": 10
            },
            "limits": {
                "ledger": {
                    "packed": 11000000,
                    "unpacked": 38000000
                },
                "map": {
                    "packed": 4200000,
                    "unpacked": 85000000
                },
                "handoff": {
                    "packed": 256000000
                },
                "read": "1m"
            }
        },
        "coverage": {
            "incremental": true,
            "compressionLevel": 6
        },
        "timeouts": {
            "mode": "confirm",
            "headroom": 0.1,
            "seconds": 10,
            "most": 300,
            "multiple": 3,
            "startUp": "5s",
            "startUpMultiple": 3,
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
            },
            "learned": {
                "percentile": 0.99,
                "least": 2.0,
                "most": 10.0
            },
            "perTest": 3,
            "testFloor": 3,
            "heavyFill": true,
            "parts": true
        },
        "flaky": {
            "confirmSurvivors": true,
            "killControls": "order"
        },
        "tests": {
            "order": "killers-first"
        },
        "survivorsFirst": {
            "max": 20,
            "budget": "20m",
            "shards": 4
        },
        "ignores": {
            "entries": [],
            "native": "refuse",
            "notice": 14
        },
        "equivalence": {
            "static": true
        },
        "reports": [],
        "suggest": {
            "enabled": true,
            "emptyHolding": true,
            "idle": {
                "judged": 500,
                "survivors": 0,
                "share": 5
            },
            "ignored": {
                "share": 80,
                "mutants": 50
            },
            "timeouts": {
                "cluster": 3
            },
            "looseValues": true
        },
        "heatmap": {
            "files": 10,
            "methods": true
        },
        "digest": {
            "tests": 5
        },
        "outputs": {
            "verdictOnly": false,
            "automatic": [
                "github-annotations",
                "github-summary",
                "github-comment",
                "badge"
            ],
            "actionReports": [
                "json",
                "html",
                "digest"
            ],
            "costs": true
        },
        "badge": {
            "colors": {
                "brightgreen": 90,
                "green": 80,
                "yellow": 70,
                "orange": 60
            },
            "trendKept": 500
        },
        "dashboard": {
            "topic": "mutation-gate"
        },
        "pest": {
            "patch": false,
            "canary": "mutation-canary",
            "bootTail": false,
            "workerOpcache": true,
            "schemata": true,
            "bootMutants": true,
            "bootChunks": false,
            "narrowedKills": "vouch",
            "longFilter": "files",
            "preloaded": "fresh",
            "trials": true,
            "valueReaders": true,
            "narrow": true,
            "shardScan": true,
            "longestFirst": true,
            "remember": true,
            "overlapSteps": true,
            "pcovOff": true,
            "xdebugOff": true
        },
        "splitStream": {
            "enabled": false,
            "children": 1,
            "changeCheck": true,
            "killersFirst": true,
            "sharding": "tests",
            "killerStreams": false,
            "early": "no-match",
            "bootOnce": false,
            "reconnect": false
        },
        "staticCheck": {
            "tool": "auto",
            "seconds": 60,
            "before": true,
            "testsTime": "learned",
            "dependents": 25,
            "preCheckAfter": 50,
            "php": {
                "pcov.enabled": "0",
                "xdebug.mode": "off",
                "opcache.enable_cli": "1",
                "opcache.file_cache_only": "1",
                "opcache.validate_timestamps": "0"
            },
            "opcache": {
                "batch": 100,
                "limit": "1m"
            },
            "after": true,
            "turbo": true
        },
        "triage": {
            "fewest": 2
        },
        "local": {
            "watchBudget": "1m",
            "prePushBudget": "5m",
            "serveIdle": "30m",
            "serveStart": "watch",
            "serveStartWait": "10s",
            "inferSubsumed": true,
            "watchPoll": "2s",
            "memoryHeadroom": 2,
            "serveRequest": "5s",
            "quick": true,
            "cancelByUnit": true,
            "cpu": "balanced",
            "onBattery": "reduce",
            "childWait": "1s",
            "watchStatus": true,
            "coverageAlone": true,
            "sizedPools": true,
            "listOnce": true,
            "streamBatches": true,
            "recordLines": true,
            "siteHits": true
        },
        "controls": {
            "kills": true,
            "memory": true
        },
        "mutants": {
            "duplicates": "judge-once"
        }
    }
    JSON;

    /** A config that sets every setting away from its default. */
    public const array EVERYTHING = [
        '$schema' => 'resources/mutation-gate.schema.json',
        'extensions' => ['Acme\\GateSlack\\SlackExtension'],
        'preset' => ['laravel', 'acme'],
        'runner' => ['use' => 'infection', 'with' => [], 'withhold' => ['DEPLOY_*', 'COMPOSER_AUTH'], 'memory' => '512m', 'workers' => 'fresh', 'recycleAfter' => 8, 'warmSlack' => '2m', 'poll' => 0.05],
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
        'owners' => ['floors' => ['@acme/payments' => 90, 'jo@acme.example' => 75.5], 'mention' => 'never'],
        'packages' => ['packages/*'],
        'reach' => [
            'everything' => ['config/**', 'routes/**'],
            'lockMoves' => 'named',
            'lockPackages' => ['pestphp/*', 'phpunit/phpunit'],
            'changedTests' => 'file',
            'fixtures' => ['tests/fixtures/*'],
        ],
        'holds' => ['hotPath' => 1, 'fewestTests' => 5],
        'shards' => ['seconds' => 900, 'max' => 8, 'cutAt' => 3.5],
        'plan' => ['chunkMinutes' => 30, 'provingRange' => 5],
        'timing' => ['newest' => 0.5, 'startUpSamples' => 7],
        'triage' => ['fewest' => 3],
        'costs' => ['secondsPerLine' => ['' => 0.25, 'src/Legacy' => 2], 'savingsWindow' => '14d'],
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
            'copies' => 'merged',
            'compression' => 'zstd',
            'compressionLevel' => 15,
        ],
        'coverage' => ['incremental' => false],
        'budget' => '1h30m',
        'timeouts' => ['learned' => ['percentile' => 0.9, 'least' => 3, 'most' => 8], 'mode' => 'unjudged', 'seconds' => 30, 'most' => 120, 'perTest' => false, 'testFloor' => 2, 'startUp' => '9s'],
        'flaky' => ['confirmSurvivors' => false],
        'tests' => ['suites' => ['Unit', 'Plugins', 'Unit'], 'holding' => ['Process']],
        'survivorsFirst' => ['max' => 5, 'budget' => '45m', 'shards' => 6],
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
        'badge' => ['colors' => ['green' => 95], 'trendKept' => 200],
        'digest' => ['tests' => 3],
        'comment' => [
            'sections' => ['verdict', 'survivors', 'trees'],
            'open' => ['verdict', 'survivors'],
            'states' => ['verdict'],
            'on' => ['pull-request', 'default-branch'],
            'mode' => 'new',
            'passing' => 'always',
            'try' => false,
            'reproduce' => true,
            'drafts' => false,
            'survivors' => ['most' => 10, 'lines' => 'all', 'detail' => 'row'],
            'text' => ['most' => 900],
        ],
        'dashboard' => ['topic' => 'mutation-tested', 'repositories' => ['api', 'web']],
        'pest' => ['patch' => true, 'canary' => 'canary', 'bootTail' => true, 'workerOpcache' => false, 'schemata' => false, 'bootMutants' => false],
        'splitStream' => ['enabled' => true, 'children' => 2, 'changeCheck' => false, 'killersFirst' => false, 'sharding' => 'source'],
        'staticCheck' => ['dependents' => 9, 'preCheckAfter' => 7, 'tool' => 'phpstan', 'config' => 'phpstan.dist.neon', 'seconds' => 45, 'before' => true, 'opcache' => ['batch' => 50, 'limit' => '30s']],
        'pruning' => ['enabled' => false, 'window' => 200, 'audit' => '3d'],
        'sampling' => [
            'confidence' => 99,
            'margin' => 1.5,
            'minUnits' => 50,
            'seed' => 'release-2026',
            'auto' => '2h',
            'autoRuns' => ['pullRequests', 'mergeGroups'],
        ],
        'local' => ['memoryHeadroom' => 3, 'serveRequest' => '9s', 'watchBudget' => '2m', 'prePushBudget' => '90s', 'serveIdle' => '10m', 'serveStart' => 'never', 'inferSubsumed' => false, 'watchPoll' => '5s', 'cpu' => 'quiet', 'workers' => 3, 'priority' => 'background', 'macosClass' => 'maintenance', 'linuxNice' => 15, 'windowsClass' => 'idle', 'onBattery' => 'pause', 'childWait' => '3s', 'watchStatus' => false, 'coverageAlone' => false, 'sizedPools' => false, 'listOnce' => false, 'streamBatches' => false],
    ];
}

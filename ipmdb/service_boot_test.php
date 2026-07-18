<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| /httpdocs/ipmdb/service_boot_test.php
|--------------------------------------------------------------------------
| IPMdb Site3 Service Boot Test
|--------------------------------------------------------------------------
|
| Confirms that the core utility and service files:
| - exist,
| - parse,
| - load in dependency order,
| - declare their expected classes,
| - instantiate without immediate constructor failure.
|
| This test performs no database writes.
|--------------------------------------------------------------------------
*/

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

header('Content-Type: text/html; charset=UTF-8');

$base = __DIR__;

$tests = [
    [
        'file' => '/includes/core/GraphUtilities.php',
        'symbol' => 'GraphUtilities',
        'type' => 'trait',
        'instantiate' => false,
    ],
    [
        'file' => '/includes/services/Service.php',
        'symbol' => 'Service',
        'type' => 'class',
        'instantiate' => false,
    ],
    [
        'file' => '/includes/services/ValidationService.php',
        'symbol' => 'ValidationService',
        'type' => 'class',
        'instantiate' => true,
    ],
    [
        'file' => '/includes/services/ProvenanceService.php',
        'symbol' => 'ProvenanceService',
        'type' => 'class',
        'instantiate' => true,
    ],
    [
        'file' => '/includes/services/VersionService.php',
        'symbol' => 'VersionService',
        'type' => 'class',
        'instantiate' => true,
    ],
    [
        'file' => '/includes/services/EventService.php',
        'symbol' => 'EventService',
        'type' => 'class',
        'instantiate' => true,
    ],
    [
        'file' => '/includes/services/RelationshipService.php',
        'symbol' => 'RelationshipService',
        'type' => 'class',
        'instantiate' => true,
    ],
    [
        'file' => '/includes/services/GraphTraversalService.php',
        'symbol' => 'GraphTraversalService',
        'type' => 'class',
        'instantiate' => true,
    ],
    [
        'file' => '/includes/services/PathService.php',
        'symbol' => 'PathService',
        'type' => 'class',
        'instantiate' => true,
    ],
    [
        'file' => '/includes/services/GraphAnalyticsService.php',
        'symbol' => 'GraphAnalyticsService',
        'type' => 'class',
        'instantiate' => true,
    ],
    [
        'file' => '/includes/services/GraphRepairService.php',
        'symbol' => 'GraphRepairService',
        'type' => 'class',
        'instantiate' => true,
    ],
    [
        'file' => '/includes/services/GraphSearchService.php',
        'symbol' => 'GraphSearchService',
        'type' => 'class',
        'instantiate' => true,
    ],
    [
        'file' => '/includes/services/RelationshipSuggestionService.php',
        'symbol' => 'RelationshipSuggestionService',
        'type' => 'class',
        'instantiate' => true,
    ],
    [
        'file' => '/includes/services/InferenceService.php',
        'symbol' => 'InferenceService',
        'type' => 'class',
        'instantiate' => true,
    ],
    [
        'file' => '/includes/services/ConsistencyService.php',
        'symbol' => 'ConsistencyService',
        'type' => 'class',
        'instantiate' => true,
    ],
    [
        'file' => '/includes/services/RuleEngineService.php',
        'symbol' => 'RuleEngineService',
        'type' => 'class',
        'instantiate' => true,
    ],
    [
        'file' => '/includes/services/SimilarityService.php',
        'symbol' => 'SimilarityService',
        'type' => 'class',
        'instantiate' => true,
    ],
    [
        'file' => '/includes/services/RecommendationService.php',
        'symbol' => 'RecommendationService',
        'type' => 'class',
        'instantiate' => true,
    ],
    [
        'file' => '/includes/services/KnowledgeGraphService.php',
        'symbol' => 'KnowledgeGraphService',
        'type' => 'class',
        'instantiate' => true,
    ],
    [
        'file' => '/includes/services/GraphImportService.php',
        'symbol' => 'GraphImportService',
        'type' => 'class',
        'instantiate' => true,
    ],
    [
        'file' => '/includes/services/GraphExportService.php',
        'symbol' => 'GraphExportService',
        'type' => 'class',
        'instantiate' => true,
    ],
    [
        'file' => '/includes/services/AssetService.php',
        'symbol' => 'AssetService',
        'type' => 'class',
        'instantiate' => true,
    ],
    [
        'file' => '/includes/services/IdeaService.php',
        'symbol' => 'IdeaService',
        'type' => 'class',
        'instantiate' => true,
    ],
    [
        'file' => '/includes/services/DecisionService.php',
        'symbol' => 'DecisionService',
        'type' => 'class',
        'instantiate' => true,
    ],
    [
        'file' => '/includes/services/WorkflowService.php',
        'symbol' => 'WorkflowService',
        'type' => 'class',
        'instantiate' => true,
    ],
    [
        'file' => '/includes/services/LedgerService.php',
        'symbol' => 'LedgerService',
        'type' => 'class',
        'instantiate' => true,
    ],
];

$results = [];
$passed = 0;
$failed = 0;

foreach ($tests as $test) {
    $relativeFile = $test['file'];
    $absoluteFile = $base . $relativeFile;
    $symbol = $test['symbol'];
    $type = $test['type'];
    $instantiate = $test['instantiate'];

    $result = [
        'file' => $relativeFile,
        'symbol' => $symbol,
        'exists' => false,
        'loaded' => false,
        'declared' => false,
        'instantiated' => null,
        'status' => 'FAIL',
        'message' => '',
    ];

    if (!is_file($absoluteFile)) {
        $result['message'] = 'File missing.';
        $results[] = $result;
        $failed++;
        continue;
    }

    $result['exists'] = true;

    try {
        require_once $absoluteFile;
        $result['loaded'] = true;
    } catch (Throwable $exception) {
        $result['message'] =
            get_class($exception)
            . ': '
            . $exception->getMessage();

        $results[] = $result;
        $failed++;
        continue;
    }

    if ($type === 'trait') {
        $declared = trait_exists($symbol, false);
    } else {
        $declared = class_exists($symbol, false);
    }

    $result['declared'] = $declared;

    if (!$declared) {
        $result['message'] =
            ucfirst($type)
            . ' was not declared after loading the file.';

        $results[] = $result;
        $failed++;
        continue;
    }

    if ($instantiate) {
        try {
            $reflection = new ReflectionClass($symbol);

            if ($reflection->isAbstract()) {
                $result['instantiated'] = false;
                $result['message'] = 'Class is abstract.';
                $results[] = $result;
                $failed++;
                continue;
            }

            $instance = $reflection->newInstance();

            $result['instantiated'] =
                is_object($instance);
        } catch (Throwable $exception) {
            $result['instantiated'] = false;

            $result['message'] =
                get_class($exception)
                . ': '
                . $exception->getMessage();

            $results[] = $result;
            $failed++;
            continue;
        }
    }

    $result['status'] = 'PASS';
    $result['message'] = $instantiate
        ? 'File loaded and class instantiated.'
        : ucfirst($type) . ' loaded successfully.';

    $results[] = $result;
    $passed++;
}

$total = count($results);
$allPassed = $failed === 0;

function e(mixed $value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >
    <title>IPMdb Service Boot Test</title>

    <style>
        :root {
            color-scheme: dark;
            font-family:
                Inter,
                system-ui,
                -apple-system,
                BlinkMacSystemFont,
                "Segoe UI",
                sans-serif;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 24px;
            background: #07111f;
            color: #edf6ff;
        }

        main {
            width: min(1200px, 100%);
            margin: 0 auto;
        }

        h1 {
            margin: 0 0 8px;
            font-size: clamp(28px, 5vw, 48px);
        }

        .summary {
            display: grid;
            grid-template-columns:
                repeat(auto-fit, minmax(150px, 1fr));
            gap: 12px;
            margin: 24px 0;
        }

        .card {
            padding: 18px;
            border: 1px solid #29435f;
            border-radius: 14px;
            background: #0d1c2e;
        }

        .card strong {
            display: block;
            font-size: 28px;
        }

        .pass {
            color: #79f2ad;
        }

        .fail {
            color: #ff9292;
        }

        .result-banner {
            margin: 18px 0;
            padding: 18px;
            border-radius: 14px;
            font-size: 20px;
            font-weight: 800;
            background:
                <?= $allPassed
                    ? '#123b2b'
                    : '#4a1f26' ?>;
        }

        .table-wrap {
            overflow-x: auto;
            border: 1px solid #29435f;
            border-radius: 14px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: #0d1c2e;
        }

        th,
        td {
            padding: 12px;
            border-bottom: 1px solid #223a53;
            text-align: left;
            vertical-align: top;
        }

        th {
            background: #12263d;
        }

        tr:last-child td {
            border-bottom: 0;
        }

        code {
            font-size: 13px;
            overflow-wrap: anywhere;
        }

        .status {
            font-weight: 900;
        }

        footer {
            margin-top: 20px;
            color: #9eb5ca;
        }
    </style>
</head>

<body>
<main>
    <h1>IPMdb Service Boot Test</h1>

    <p>
        Site3 service loading and constructor verification.
    </p>

    <div class="summary">
        <div class="card">
            <span>Total</span>
            <strong><?= e($total) ?></strong>
        </div>

        <div class="card">
            <span>Passed</span>
            <strong class="pass"><?= e($passed) ?></strong>
        </div>

        <div class="card">
            <span>Failed</span>
            <strong class="fail"><?= e($failed) ?></strong>
        </div>
    </div>

    <div class="result-banner">
        <?= $allPassed
            ? 'PASS — Site3 service layer boots.'
            : 'FAIL — Review the first failed service.' ?>
    </div>

    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>Status</th>
                <th>Service</th>
                <th>File</th>
                <th>Result</th>
            </tr>
            </thead>

            <tbody>
            <?php foreach ($results as $result): ?>
                <tr>
                    <td
                        class="status <?= $result['status'] === 'PASS'
                            ? 'pass'
                            : 'fail' ?>"
                    >
                        <?= e($result['status']) ?>
                    </td>

                    <td>
                        <?= e($result['symbol']) ?>
                    </td>

                    <td>
                        <code><?= e($result['file']) ?></code>
                    </td>

                    <td>
                        <?= e($result['message']) ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <footer>
        Generated <?= e(gmdate('c')) ?>.
        No database writes were performed.
    </footer>
</main>
</body>
</html>
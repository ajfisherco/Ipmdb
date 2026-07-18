<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| /httpdocs/ipmdb/relationship_export.php
|--------------------------------------------------------------------------
| IPMdb Relationship Export
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/relationship_types.php';

$config = ipmdb_config();

try {

    $pdo = new PDO(
        $config['db']['dsn'],
        $config['db']['user'],
        $config['db']['pass'],
        [
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES=>false,
        ]
    );

    $format=strtolower(trim((string)($_GET['format'] ?? 'json')));

    $stmt=$pdo->query("
        SELECT
            r.id,
            r.source_asset_id,
            r.target_asset_id,
            r.relationship_type,
            r.note,
            r.created_at,

            s.title AS source_title,
            t.title AS target_title

        FROM ipmdb_relationships r

        LEFT JOIN ipmdb_assets s
            ON s.asset_id=r.source_asset_id

        LEFT JOIN ipmdb_assets t
            ON t.asset_id=r.target_asset_id

        ORDER BY r.id
    ");

    $rows=$stmt->fetchAll();

    switch($format){

        case 'csv':

            header('Content-Type:text/csv');
            header('Content-Disposition:attachment; filename="relationships.csv"');

            $fp=fopen('php://output','w');

            fputcsv($fp,[
                'id',
                'source_asset_id',
                'source_title',
                'target_asset_id',
                'target_title',
                'relationship_type',
                'note',
                'created_at'
            ]);            foreach ($rows as $row) {

                fputcsv($fp,[
                    $row['id'],
                    $row['source_asset_id'],
                    $row['source_title'],
                    $row['target_asset_id'],
                    $row['target_title'],
                    $row['relationship_type'],
                    $row['note'],
                    $row['created_at']
                ]);

            }

            fclose($fp);
            exit;

        case 'json':

            header('Content-Type: application/json; charset=utf-8');

            echo json_encode(
                $rows,
                JSON_PRETTY_PRINT
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
            );

            exit;

        case 'mermaid':

            header('Content-Type:text/plain');

            echo "graph TD\n";

            foreach($rows as $row){

                $a=preg_replace('/[^A-Za-z0-9_]/','_',$row['source_asset_id']);
                $b=preg_replace('/[^A-Za-z0-9_]/','_',$row['target_asset_id']);

                echo "{$a}[\"".addslashes($row['source_title'])."\"]";
                echo "-->|";
                echo addslashes($row['relationship_type']);
                echo "|";
                echo "{$b}[\"".addslashes($row['target_title'])."\"]";
                echo "\n";

            }

            exit;

        case 'graphml':

            header('Content-Type:application/xml');

            echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
            echo "<graphml>\n";
            echo "<graph edgedefault=\"directed\">\n";

            $nodes=[];

            foreach($rows as $row){

                if(!isset($nodes[$row['source_asset_id']])){

                    echo "<node id=\"".
                        htmlspecialchars($row['source_asset_id']).
                        "\"/>\n";

                    $nodes[$row['source_asset_id']]=true;

                }

                if(!isset($nodes[$row['target_asset_id']])){

                    echo "<node id=\"".
                        htmlspecialchars($row['target_asset_id']).
                        "\"/>\n";

                    $nodes[$row['target_asset_id']]=true;

                }

                echo "<edge source=\"".
                    htmlspecialchars($row['source_asset_id']).
                    "\" target=\"".
                    htmlspecialchars($row['target_asset_id']).
                    "\"/>\n";

            }

            echo "</graph>\n";
            echo "</graphml>\n";

            exit;        case 'cytoscape':

            header('Content-Type:application/json');

            $graph=[
                'nodes'=>[],
                'edges'=>[]
            ];

            $seen=[];

            foreach($rows as $row){

                foreach([
                    [
                        $row['source_asset_id'],
                        $row['source_title']
                    ],
                    [
                        $row['target_asset_id'],
                        $row['target_title']
                    ]
                ] as $node){

                    if(isset($seen[$node[0]])){
                        continue;
                    }

                    $seen[$node[0]]=true;

                    $graph['nodes'][]=[
                        'data'=>[
                            'id'=>$node[0],
                            'label'=>$node[1]
                        ]
                    ];

                }

                $graph['edges'][]=[
                    'data'=>[
                        'id'=>$row['id'],
                        'source'=>$row['source_asset_id'],
                        'target'=>$row['target_asset_id'],
                        'label'=>$row['relationship_type']
                    ]
                ];

            }

            echo json_encode(
                $graph,
                JSON_PRETTY_PRINT
            );

            exit;

        default:

            http_response_code(400);

            echo 'Unsupported export format.';

            exit;

    }

}
catch(Throwable $e){

    http_response_code(500);
    error_log('IPMdb relationship export failed: ' . $e->getMessage());
    echo 'Relationship export is temporarily unavailable.';

}

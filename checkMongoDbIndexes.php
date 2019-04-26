<?php

require __DIR__ . '/vendor/autoload.php';

$uri = 'mongodb://localhost:27017';
$dbName = 'test';

$collections = [
    'test' => [
        'indexes' => [
            [
                'keys' => [
                    'bar' => 1,
                    'foo' => 1
                ],
                'options' => [
                    'unique' => true
                ]
            ],
            [
                'keys' => [
                    'baz' => 1
                ],
                'options' => [
                    'unique' => true
                ]
            ]
        ],
    ],
];
$client = new MongoDB\Client($uri, []);
$dataBase = $client->selectDatabase($dbName);

foreach ($collections as $collectionName => $indexes) {
    $listIndexes = $dataBase->selectCollection($collectionName)->listIndexes();

    foreach ($indexes['indexes'] as $index) {
        foreach ($listIndexes as $indexInfo) {
            if ($indexInfo->getName() === '_id_') {
                continue;
            }
            if ($indexInfo->getKey() == $index['keys']) {
                continue;
            }

            $createdIndex = $dataBase->selectCollection($collectionName)->createIndex($index['keys'], $index['options']);

            error_log("Created: {$createdIndex} on collection {$collectionName}");
        }
    }
}




<?php
namespace Stanford\VeraSupport;
/** @var VeraSupport $module */


// TEST PAGE FOR WORKING ON NEW QUERIES..

// So far I'm unabel to do a query across partitions where I don't know the ID and am looking for some value like email

$module->initialize();


$host = $module->getProjectSetting('host');
$key = $module->getProjectSetting('key');
$db = $module->getProjectSetting('database');

$collection = "Profile";
$conn = new \Jupitern\CosmosDb\CosmosDb($host, $key, false);
// $conn->setHttpClientOptions(['verify' => false]); # optional: set guzzle client options.
$db = $conn->selectDB($db);
$collection = $db->selectCollection($collection);
var_dump($collection);

$partition = 'stanford-catch-study';
$id = "stanford-catch-study:0005022e-988a-465d-a217-003b9b777e58";
$id = "0005022e-988a-465d-a217-003b9b777e58";

$query = "SELECT * FROM c where c.id = '$id'";
$params = [];
$response = $collection->query($query, $params, true, "stanford-catch-study");
var_dump($response);

echo "\n\n1 is DONE";

// # query a document and return it as an array
$res = \Jupitern\CosmosDb\QueryBuilder::instance()
    ->setCollection($collection)
    ->setPartitionValue($id)
    ->select("*")
    ->where("c.id = @id")
    ->params(['@id' => $id])
    ->find(false)
    // ->findAll(true) # pass true if is cross partition query
    ->toArray();

var_dump($res);


// // NOT WORKING...
// private function lookupParticipantByEmail($email) {
//     $this->emDebug("Looking up $email");
//
//     // Start with participantId
//     $id = $email;
//     $this->initDb();
//     $collection = $this->db->selectCollection($this->profileCollection);
//     $res = \Jupitern\CosmosDb\QueryBuilder::instance()
//         ->setCollection($collection)
//         // ->setPartitionKey([2])
//         // ->setPartitionValue(3)
//         ->select("*")
//         ->where("c.email = @email")
//         ->params(['@email' => $email])
//         // ->find(true)
//         ->findAll(false) # pass true if is cross partition query
//         ->toArray();
//     $this->emDebug($res);
//     array_filter($res);
//     $this->emDebug($res);
//     return $this->arrayToTable($res);
// }

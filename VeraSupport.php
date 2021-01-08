<?php
namespace Stanford\VeraSupport;

require_once "emLoggerTrait.php";
require('vendor/autoload.php');

use REDCap;

class VeraSupport extends \ExternalModules\AbstractExternalModule {

    use emLoggerTrait;

    /**
     * @var mixed|null
     */
    private $host;
    private $privateKey;
    private $database;
    private $profileCollection;
    private $workflowCollection;
    private $partition;
    private $surveyInstrument;
    private $phoneInstrument;
    private $db;

    private $recordId;

    public function __construct() {
		parent::__construct();
		// Other code to run when object is instantiated
	}

	public function initialize() {
        $this->host = $this->getProjectSetting('host');
        $this->privateKey = $this->getProjectSetting('private-key');
        $this->database = $this->getProjectSetting('database');
        $this->profileCollection = $this->getProjectSetting('profile-collection');
        $this->workflowCollection = $this->getProjectSetting('workflow-collection');
        $this->partition = $this->getProjectSetting('partition');
        $this->surveyInstrument = $this->getProjectSetting('survey-instrument');
        $this->phoneInstrument = $this->getProjectSetting('phone-instrument');
    }


    public function redcap_save_record( $project_id, $record, $instrument, $event_id, $group_id, $survey_hash, $response_id, $repeat_instance = 1) {
        $this->initialize();

        // Do nothing if we aren't in the survey instrument
        // if (empty($this->surveyInstrument) || $instrument !== $this->surveyInstrument) return;

        // Get the current record
        $params = [
            'return_format' => 'json',
            'records' => [$record]
        ];

        $q = REDCap::getData($params);
        $results = json_decode($q,true);
        if (!empty($results[0])) $this->updateParticipant($results[0]);
        // $this->emDebug($results);



    }

    public function updateParticipant($record) {
        $this->emDebug('Updating participant');

        $participantId = $record['participant_id'];
        $email         = $record['email'];
        $firstName     = $record['first_name'];
        $lastName      = $record['last_name'];

        $participantLookupResults = $record['participant_lookup_results'];

        $doUpdate = false;

        // Look for Participant Details
        if (!empty($participantId) && empty($record['participant_lookup_results'])) {
            // Try to look up the user
            $detail = $this->lookupParticipantById($participantId);
            $this->emDebug($detail);

            if (empty($detail)) {
                // Try to lookup user by email
                // $detail = $this->lookupParticipantByEmail($email);
                // $this->emDebug($detail);
                $record['participant_lookup_results'] = "Unable to find participant";
            } else {
                // Save the results
                $record['participant_lookup_results'] = $detail;
            }
            $doUpdate = true;
        }

        // Get Workflow History
        if (!empty($participantId) && empty($record['workflow_history_summary']) && !empty($record['participant_lookup_results'])) {
            // Try to lookup workflow history
            $detail = $this->lookupWorkflowHistory($participantId);
            $this->emDebug($detail);
            if (empty($detail)) {
                // Didn't work
                // $record['workflow_history_summary'] = "";
            } else {
                $record['workflow_history_summary'] = $detail;
                $doUpdate = true;
            }
        }

        if ($doUpdate) {
            $q = REDCap::saveData('json', json_encode(array($record)));
            $this->emDebug($q);
        }

    }

    private function lookupParticipantById($participantId) {
        $this->emDebug("Looking up $participantId");

        // Start with participantId
        $id = $participantId;
        $this->initDb();
        $collection = $this->db->selectCollection($this->profileCollection);
        $res = \Jupitern\CosmosDb\QueryBuilder::instance()
            ->setCollection($collection)
            ->setPartitionValue($id)
            ->select("*")
            ->where("c.id = @id")
            ->params(['@id' => $id])
            ->find(false)
            ->toArray();
        // $this->emDebug($res);
        array_filter($res);
        // $this->emDebug($res);
        return $this->arrayToTable($res);
    }


    private function lookupWorkflowHistory($participantId) {
        // Start with participantId
        $id = $this->partition . ":" . $participantId;
        $this->emDebug("Looking up $id");
        $this->initDb();
        $collection = $this->db->selectCollection($this->workflowCollection);
        $res = \Jupitern\CosmosDb\QueryBuilder::instance()
            ->setCollection($collection)
            ->setPartitionValue($this->partition)
            ->select("*")
            ->where("c.id = @id")
            ->params(['@id' => $id])
            ->find(false)
            ->toArray();
        // $this->emDebug($res);
        if (!empty($res)) {
            $states = $res->stateAuditHistory;
            array_push($states, $res->currentState);
            // $this->emDebug('AFTER', $states);
        }
        $rows = [];
        $ar = "style='text-align:right; padding-left: 5px;'";
        foreach ($states as $i => $state) {
            // $enteredOn = left($state->enteredOn,19);
            $enteredOn = $state->enteredOn;
            $date = new \DateTime($enteredOn);
            $enteredOn = $date->format("m/d/y H:i:s");
            $enteredOnPst = $date->setTimezone( new \DateTimeZone ('America/Los_Angeles'))->format('D H:i:s');
            $rows[]= "<tr><td>" . ($i+1) . "</td>" .
                "<td>" . $state->executingTransition . "</td>" .
                "<td>" . $state->state . "</td>" .
                "<td>" . $enteredOn . "</td>" .
                "<td $ar>" . $enteredOnPst . "</td></tr>";
        }

        $rows = array_merge(
            ["<tr><th>#</th><th>executingTransition</th><th>state</th><th>enteredOn</th><th $ar>PST</th></tr>"],
            array_reverse($rows)
        );

        return empty($states) ? null : "<table style='width:100%; font-weight:normal;'>" . implode("", $rows) . "</table>";
    }


    // NOT WORKING...
    private function lookupParticipantByEmail($email) {
        $this->emDebug("Looking up $email");

        // Start with participantId
        $id = $email;
        $this->initDb();
        $collection = $this->db->selectCollection($this->profileCollection);
        $res = \Jupitern\CosmosDb\QueryBuilder::instance()
            ->setCollection($collection)
            // ->setPartitionKey([2])
            // ->setPartitionValue(3)
            ->select("*")
            ->where("c.email = @email")
            ->params(['@email' => $email])
            // ->find(true)
            ->findAll(false) # pass true if is cross partition query
            ->toArray();
        $this->emDebug($res);
        array_filter($res);
        $this->emDebug($res);
        return $this->arrayToTable($res);
    }




    private function arrayToTable($arr) {
        $rows = [];
        foreach ($arr as $k => $v) {
            // Skip property keys for cosmos
            if (left($k,1) == '_') continue;
            if (empty($v)) continue;
            $rows[] = "<tr><th>$k</th><td>$v</td></tr>";
        }
        return empty($rows) ? null : "<table style='width:100%; font-weight:normal;'>".implode("",$rows)."</table>";
    }


    private function initDb() {
        if (empty($this->db)) {
            $conn = new \Jupitern\CosmosDb\CosmosDb($this->host, $this->privateKey, false);
            // $conn->setHttpClientOptions(['verify' => false]); # optional: set guzzle client options.
            $this->db = $conn->selectDB($this->database);
        }
    }




}

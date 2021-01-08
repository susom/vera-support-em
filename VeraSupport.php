<?php
namespace Stanford\VeraSupport;

require_once "emLoggerTrait.php";
require('vendor/autoload.php');

use REDCap;

class VeraSupport extends \ExternalModules\AbstractExternalModule {

    use emLoggerTrait;

    private ?string $host;
    private ?string $privateKey;
    private ?string $database;
    private ?string $profileCollection;
    private ?string $profileSummaryField;
    private ?string $workflowCollection;
    private ?string $workflowSummaryField;
    private ?string $partition;
    private ?string $surveyInstrument;
    private ?string $phoneInstrument;
    private ?string $db;
    private ?bool   $forceUpdateField;
    private ?string $recordId;

    public function __construct() {
		parent::__construct();
		// Other code to run when object is instantiated
	}

	public function initialize() {
        $this->host = $this->getProjectSetting('host');
        $this->privateKey = $this->getProjectSetting('private-key');
        $this->database = $this->getProjectSetting('database');
        $this->profileCollection = $this->getProjectSetting('profile-collection');
        $this->profileSummaryField = $this->getProjectSetting('profile-summary-field');
        $this->workflowCollection = $this->getProjectSetting('workflow-collection');
        $this->workflowSummaryField = $this->getProjectSetting('workflow-summary-field');
        $this->partition = $this->getProjectSetting('partition');
        $this->surveyInstrument = $this->getProjectSetting('survey-instrument');
        $this->phoneInstrument = $this->getProjectSetting('phone-instrument');
        $this->forceUpdateField = $this->getProjectSetting('force-update-checkbox');
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
    }

    public function updateParticipant($record) {
        $participantId = $record['participant_id'];
        $forceUpdate = isset($record[$this->forceUpdateField . '___1']) && $record[$this->forceUpdateField . '___1'] == 1;

        $this->emDebug("Updating participant $participantId", $forceUpdate);
        // $email         = $record['email'];
        // $firstName     = $record['first_name'];
        // $lastName      = $record['last_name'];

        $doUpdate = false;

        // Look for Participant Details
        if (!empty($participantId) && ( empty($record[$this->profileSummaryField]) || $forceUpdate) ) {
            // Try to look up the user
            $record[$this->profileSummaryField] = $this->lookupParticipantById($participantId);
            $doUpdate = true;
        }

        // Get Workflow History
        if (!empty($participantId) && ( empty($record[$this->workflowSummaryField]) || $forceUpdate) ){
            // Try to lookup workflow history
            $detail = $this->lookupWorkflowHistory($participantId);
            // $this->emDebug($detail);

            $record[$this->workflowSummaryField] = empty($detail) ? "Unable to find workflow history" : $detail;
            $doUpdate = true;
        }

        if ($doUpdate) {
            if ($forceUpdate) $record[$this->forceUpdateField . '___1'] = 0;
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
        return empty($res) ? "Unable to find participant profile" : $this->arrayToTable($res);
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

            // Build array of workflowHistory
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
            $result = "<table style='width:100%; font-weight:normal;'>" . implode("", $rows) . "</table>";
        } else {
            $result = "Unable to find workflow state history";
        }
        return $result;
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

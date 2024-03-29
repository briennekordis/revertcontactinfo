<?php

use Civi\Core\Lock\NullLock;
use CRM_Revertcontactinfo_ExtensionUtil as E;
// use CRM_CORE_DAO;

/**
 * ContactInfo.Revertdata API specification (optional)
 * This is used for documentation and validation.
 *
 * @param array $spec description of fields supported by this API call
 *
 * @see https://docs.civicrm.org/dev/en/latest/framework/api-architecture/
 */
function _civicrm_api3_contact_info_Revertdata_spec(&$spec) {
  // Id of the Contact that is being changed
  $spec['contact_id']['api.required'] = 1;
  // The Entity that is changing, i.e. Phone, Email, Address
  $spec['entity']['api.required'] = 1;
}

/**
 * ContactInfo.Revertdata API
 *
 * @param array $params
 *
 * @return array
 *   API result descriptor
 *
 * @see civicrm_api3_create_success
 *
 * @throws API_Exception
 *
 * We want to look up the current value of the entity that is given for the contact id being passed in 
 * to compare that value to the previous value of that same entity for that contact.
 */

/**
 * Look up the entity specified for the given contact
 * and retrieve the most recent and second most recent values for that entity
 * so that the data can be reverted to the second most recent value.
 * Returns an Object
 */
function revertOneEntity($entity, $contactID) {
  // Look up both the live and logging databases (which may be the same, but in some cases may not) and the entity table.
  $liveDatabase = getLiveDatabase();
  $loggingDatabase = getLoggingDatabase();
  $capitalizedEntity = ucfirst($entity);
  $table = CRM_Core_DAO_AllCoreTables::getTableForEntityName($capitalizedEntity);
  $loggingTable = "log_$table";
  
  // Find the most recent entity[ (i.e. email) found for that contact.
  $mostRecentQuery = "SELECT id FROM $loggingDatabase.$loggingTable WHERE contact_id = $contactID ORDER BY log_date DESC LIMIT 1;";
  $entityID = CRM_Core_DAO::singleValueQuery($mostRecentQuery);
  // Take that entity's id and look up the second most recent address with that id.
  // By doing it by the id we can revert the whole row, so it does not have to be entity specific.
  $secondMostRecentQuery = "SELECT * FROM $loggingDatabase.$loggingTable WHERE id = $entityID ORDER BY log_date DESC LIMIT 1 OFFSET 1;";
  $secondMostRecentDAO = CRM_Core_DAO::executeQuery($secondMostRecentQuery);
  // Make sure that secondMostRecentDAO exists, and if not then exit (early return) with an error message to display to the user.
  if ($secondMostRecentDAO->N === 0) {
    $response['isError'] = TRUE;
    $response['msg'] = "The $entity was not reverted becuase there is no previous value.";
    return $response;
  }
  while ($secondMostRecentDAO->fetch()) {
    // Get the fields, which are also the column names in the database, for the entity and format the array so that the name of the column is the key.
    $entityFields = civicrm_api3($entity, 'getfields');
    // $entityColumns = array_column($entityFields, 'values');
    $entityColumns = array_keys($entityFields['values']);
    $entityColumns = array_flip($entityColumns);
    // Cast the $secondMostRecentDAO to an array so it is easier to work with.
    $daoArray = (array) $secondMostRecentDAO;
    // If a key matches. add that key and the value within the $daoArray to a new array that only contains the key/value pairs we want for the SQL query.
    $rowValues = array_intersect_key($daoArray, $entityColumns);
  }
  if ($rowValues) {
    // Iterate through the values and format them like a SET statement, i.e. 'columnName = valueToUpdate,'.
    foreach ($rowValues as $rowKey => $rowValue) {
      // We don't need to include the id or the contact_id, since those columns will not be updated in this use case. We also don't want to include columns with emtpy values.
      if (($rowKey !== 'id' && $rowKey !== 'contact_id') && $rowValue) {
        // If the key's type is a string, wrap the value in quotes.
        if ($entityFields['values'][$rowKey]['html']['type'] === 'Text' || $entityFields['values'][$rowKey]['html']['type'] === 'Email') {
          $rowValue = "'$rowValue'";
        }
        $setParams[] = "$rowKey = $rowValue";
      }
    }
    // Format the above array into a single string to be passed into the SQL query.
    $setParamsString = implode(', ', $setParams);
  }

  // Update the live database, passing in the properly formatted SET parameters and matching the entity's id.
  $updateQuery = "UPDATE $liveDatabase.$table SET $setParamsString WHERE id = $entityID";
  CRM_Core_DAO::singleValueQuery($updateQuery);
  $response['isError'] = FALSE;
  $response['msg'] = "The $entity was reverted successfully.";
  return $response;
}

/**
 * Helper function to get the logging database for the relevant entity.
 * Returns a String.
 */
function getLoggingDatabase() : string {
  $dao = new CRM_Core_DAO();
  $databases = $databaseList ?? [$dao->_database];

  if ($databases) {
    $dsn = CRM_Utils_SQL::autoSwitchDSN(CIVICRM_LOGGING_DSN);
    $dsn = DB::parseDSN($dsn);
    $loggingDatabase = $dsn['database'];
  }
  return $loggingDatabase;
}

/**
 * Helper function to get the live databasefor the relevant entity.
 * Returns a String.
 */
function getLiveDatabase() : string {
  $dao = new CRM_Core_DAO();
  $databases = $databaseList ?? [$dao->_database];

  if ($databases) {
    $dsn = CRM_Utils_SQL::autoSwitchDSN(CIVICRM_DSN);
    $dsn = DB::parseDSN($dsn);
    $liveDatabase = $dsn['database'];
  }
  return $liveDatabase;
}

/**
 * APIv3 call to update the value of the entity.
 */
function civicrm_api3_contact_info_Revertdata($params) {
  // Always be passing in an array, regardless of how many elements
  // For each entity in that array, call revertOneEntity().
  $params['entity'] = (array) $params['entity'];
  $revertedEntity = [];
  $error = FALSE;
  $msg = [];
  foreach ($params['entity'] as $key => $entity) {
    $revertedEntity[$key] = revertOneEntity($entity, $params['contact_id']);
    if ($revertedEntity[$key]['isError']) {
      $error = TRUE;
    }
    $msg[] = $revertedEntity[$key]['msg'];
  }
  if ($error) {
    return civicrm_api3_create_error(implode(' ', $msg));
  }
  return civicrm_api3_create_success(implode(' ', $msg));
}

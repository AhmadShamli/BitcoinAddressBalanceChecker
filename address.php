<?php

$max_addresses_per_query = 300;
$database_path = 'address.db';  // Update with your database path
$retryAttempts = 3;
$retryDelay =  100; // 500 milliseconds (0.5 seconds)
$error = [];

$response = ['status'=>false,'remark'=>'','responsedata'=>[]];

function updateAddressBalance($addresses, $balances): array
{
    global $database_path;
    global $retryAttempts;
    global $retryDelay;

    $failedAddress = [];

    // Connect to the SQLite database
    $db = new SQLite3($database_path);
    $db->busyTimeout(5000); // Set the busy timeout to 5 seconds (5000 milliseconds)

    // Prepare the UPDATE query
    $sql = "UPDATE my_table SET balance = :balance, updated = :updated, lockby = NULL, lockendtime = NULL WHERE address = :address";

    for ($attempt = 1; $attempt <= $retryAttempts; $attempt++) {
        // Prepare and bind parameters inside the loop
        $stmt = $db->prepare($sql);

        if ($stmt === false) {
            // Handle the error, perhaps log it
            continue; // Move to the next attempt
        }

        $currentTime = date('Y-m-d H:i:s');

        foreach ($addresses as $index => $address) {
            // Bind parameters inside the loop
            $stmt->bindValue(":balance", $balances[$index], SQLITE3_INTEGER);
            $stmt->bindValue(":updated", $currentTime, SQLITE3_TEXT);
            $stmt->bindValue(":address", $address, SQLITE3_TEXT);

            // Execute the statement
            if ($stmt->execute() === false) {
                $failedAddress[] = $address;
            }

            // Reset bindings for the next iteration
            $stmt->reset();
        }

        // Close the statement after each attempt
        $stmt->close();

        // Check if there were no failed addresses
        if (empty($failedAddress)) {
            $db->close();
            return ['status' => true, 'remark' => 'Processed addresses saved successfully.', 'data' => []];
        }

        // Delay before the next retry (adjust as needed)
        usleep($retryDelay * 1000); // 500,000 microseconds = 0.5 seconds
    }

    $db->close();

    // Return the response with failed addresses
    return ['status' => false, 'remark' => 'Some addresses failed to be saved.', 'data' => ['failedAddress' => $failedAddress]];
}




function getAddress($limit): array
{
    global $database_path;

    // Connect to the SQLite database
    $db = new SQLite3($database_path);
    $db->busyTimeout(5000); // Set the busy timeout to 5 seconds (5000 milliseconds)

    // Prepare SQL statement to fetch addresses
    $sql = "SELECT address FROM my_table WHERE balance IS NULL LIMIT :limit";
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);

    // Execute the SQL statement
    $result = $stmt->execute();

    //check if empty result
    if ($result->numColumns() == 0) {
        $sql = "SELECT address FROM my_table WHERE lockby IS NULL LIMIT :limit";
        $stmt = $db->prepare($sql);
        $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
        $result = $stmt->execute();
        if ($result->numColumns() == 0) {
            $sql = "SELECT address FROM my_table WHERE lockendtime < strftime('%s', 'now') LIMIT :limit";
            $stmt = $db->prepare($sql);
            $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
            $result = $stmt->execute();
            if ($result->numColumns() == 0) {
                // select address on column 'updated' equal or less than now
                $sql = "SELECT address FROM my_table WHERE updated <= strftime('%s', 'now') LIMIT :limit";
                $stmt = $db->prepare($sql);
                $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
                $result = $stmt->execute();
            }
        }

    }

    $addresses = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $addresses[] = $row['address'];
    }

    $stmt->close();
    $db->close();
    return $addresses;
}

function lockAddress($addresses, $instanceName): void
{
    global $database_path;
    global $retryAttempts;
    global $retryDelay;
    global $error;

    // Connect to the SQLite database
    $db = new SQLite3($database_path);
    $db->busyTimeout(5000); // Set the busy timeout to 5 seconds (5000 milliseconds)

    // Prepare SQL statement to update lockby and lockendtime
    $sqlUpdate = "UPDATE my_table SET lockby = :lockby, lockendtime = :lockendtime WHERE address IN (";
    $sqlUpdate .= implode(',', array_fill(0, count($addresses), '?'));
    $sqlUpdate .= ")";

    $stmtUpdate = $db->prepare($sqlUpdate);

    // Bind common parameters
    $stmtUpdate->bindValue(':lockby', $instanceName, SQLITE3_TEXT);
    $stmtUpdate->bindValue(':lockendtime', date('Y-m-d H:i:s', strtotime('+20 seconds')), SQLITE3_TEXT);

    // Bind addresses as parameters and execute the update statement
    foreach ($addresses as $index => $address) {
        $stmtUpdate->bindValue($index + 1, $address, SQLITE3_TEXT);
    }

    for ($attempt = 1; $attempt <= $retryAttempts; $attempt++) {
        try {
            if (@$stmtUpdate->execute() !== false) {
                // Close the UPDATE statement
                $stmtUpdate->close();
                // Close the database connection
                $db->close();
                return; // Success, no need for further attempts
            }
        } catch (\Exception $e) {
            // Handle the exception (log or suppress as needed)
            $error[] =  "Exception caught: " . $e->getMessage() . "\n";
        }

        // Delay before the next retry (adjust as needed)
        usleep($retryDelay * 1000); // Convert milliseconds to microseconds
    }

    // Close the UPDATE statement
    $stmtUpdate->close();
    // Close the database connection
    $db->close();
}



function cleanAddress($balanceThreshold): array
{
    global $database_path;

    // Connect to the SQLite database
    $db = new SQLite3($database_path);
    $db->busyTimeout(5000); // Set the busy timeout to 5 seconds (5000 milliseconds)

    // Prepare SQL statement to delete addresses with balance below the specified threshold
    $sqlDelete = "DELETE FROM my_table WHERE balance IS NOT NULL AND balance < :balanceThreshold";
    $stmtDelete = $db->prepare($sqlDelete);
    $stmtDelete->bindValue(':balanceThreshold', $balanceThreshold, SQLITE3_FLOAT);

    if ($stmtDelete->execute() === false) {
        $response = ['status'=>false,'remark'=>'Error deleting addresses.'];
    } else {
        $response = ['status'=>true,'remark'=>"Deleted " . $stmtDelete->changes() . " rows."];
    }

    // Close the DELETE statement
    $stmtDelete->close();

    $db->close();
    return $response;
}

function getSessionId(): bool|string
{
    $sessionId = session_id();
    if (empty($sessionId)) {
        session_start();
    }
    return session_id();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $processedAddressesJson = $_POST['blockchainData'] ?? '';

    if (!empty($processedAddressesJson)) {
        $processedAddresses = json_decode($processedAddressesJson, true);

        if ($processedAddresses[0] !== null) {
            if (empty($processedAddresses[0])) {
                $response = ['status'=>false,'remark'=>'No addresses with balance were found.','data'=>[]];
            } else {
                // Extract addresses and balances
                $addresses = array_column($processedAddresses[0], 'address');
                $balances = array_column($processedAddresses[0], 'balance');

                $response = updateAddressBalance($addresses, $balances);
            }
        } else {
            $response = ['status'=>false,'remark'=>'Invalid JSON format.','data'=>[]];
        }
    } else {
        $response = ['status'=>false,'remark'=>'No processed addresses received.','data'=>[]];
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['getaddress'])) {

        $instanceName = $_GET['instancename'] ?? getSessionId();
        $limit = $_GET['limit'] ?? $max_addresses_per_query;

        $addresses = getAddress($limit);

        // Update lockby and lockendtime for fetched addresses
        if (!empty($addresses)) {
            lockAddress($addresses, $instanceName);
        }

        // Prepare the response JSON
        $response = ['status'=>true,'remark'=>'','responsedata'=>[
            'addresses' => $addresses
        ]];
    }

} else if (isset($_GET['clean']) && isset($_GET['balance'])) {
    // Parse the 'balance' parameter as a float
    $balanceThreshold = floatval($_GET['balance']);
    $response = cleanAddress($balanceThreshold);
} else {
    $response = ['status'=>false,'remark'=>'Invalid request method.','data'=>[]];
}
if (!empty($error)) {
    $response['error'] = $error;
}
header('Content-Type: application/json; charset=utf-8');
echo json_encode($response);

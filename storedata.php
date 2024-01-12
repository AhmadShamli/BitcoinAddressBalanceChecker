<?php

function tableExists($db, $tableName): bool
{
    $result = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='{$tableName}'");
    return $result && $result->fetchArray();
}

function createDatabaseTable($db): void
{
    $tableName = 'my_table';

    // Check if the table already exists
    if (!tableExists($db, $tableName)) {
        $query = "
            CREATE TABLE {$tableName} (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                address TEXT UNIQUE,
                balance INTEGER,
                lock TEXT,
                lockby TEXT
            );
        ";

        $db->exec($query);
    }
}

function insertData($db, $address): void
{
    $query = "
        INSERT OR IGNORE INTO my_table (address) VALUES (:address)
    ";

    $statement = $db->prepare($query);

    $statement->bindValue(':address', $address, SQLITE3_TEXT);

    $statement->execute();
}

// Function to get the last processed line from the batch
function getLastProcessedLine(): int {
    $lastProcessedFile = 'last_processed_line.txt';

    if (file_exists($lastProcessedFile)) {
        return (int) file_get_contents($lastProcessedFile);
    }

    return 0;
}

// Function to save the last processed line to the batch
function saveLastProcessedLine($lineNumber): void {
    $lastProcessedFile = 'last_processed_line.txt';

    file_put_contents($lastProcessedFile, $lineNumber);
}

function runProcess($db, $file_handle): void
{
    $maxAttempts = 5; // Adjust the number of attempts as needed
    $intervalInMilliseconds = 10; // Adjust the interval between attempts in milliseconds as needed

    $attempt = 1;

    while ($attempt <= $maxAttempts) {
        // Check if the database is locked
        $isDatabaseLocked = $db->exec('BEGIN IMMEDIATE');

        if ($isDatabaseLocked) {
            if ($file_handle) {
                $batchSize = 100000;
                $currentLine = 0;

                // Get the last processed line
                $lastProcessedLine = getLastProcessedLine();

                // Move to the last processed line
                while ($currentLine < $lastProcessedLine && ($line = fgets($file_handle)) !== false) {
                    $currentLine++;
                }

                // Process the next batch of lines
                while ($currentLine < $lastProcessedLine + $batchSize && ($line = fgets($file_handle)) !== false) {
                    $address = trim($line);

                    // Insert data into the SQLite table
                    insertData($db, $address);

                    $currentLine++;
                }

                // Save the last processed line
                saveLastProcessedLine($currentLine);

                fclose($file_handle);

                // Release the database lock
                $db->exec('COMMIT');

                return; // Exit the loop and function since the process is successful
            } else {
                echo "Error opening the file.";
                return;
            }
        }

        // Database is locked, wait for the specified interval before the next attempt
        usleep($intervalInMilliseconds * 1000); // Convert milliseconds to microseconds

        $attempt++;
    }

    echo "Unable to acquire database lock after multiple attempts. Exiting.";
}

function getTotalAddressesInFile($filePath) {
    $totalAddresses = 0;
    $fileHandle = fopen($filePath, 'r');

    if ($fileHandle) {
        while (($line = fgets($fileHandle)) !== false) {
            if (!empty(trim($line))) {
                $totalAddresses++;
            }
        }

        fclose($fileHandle);
    } else {
        echo "Error opening the file.";
    }

    return $totalAddresses;
}

function getOrCalculateTotalAddresses($filePath) {
    $totalAddressesFile = 'total_addresses.txt';

    if (file_exists($totalAddressesFile)) {
        // If the file containing the total addresses exists, retrieve its value
        $totalAddresses = (int) file_get_contents($totalAddressesFile);
    } else {
        // If the file doesn't exist, calculate the total addresses and save it
        $totalAddresses = getTotalAddressesInFile($filePath);
        file_put_contents($totalAddressesFile, $totalAddresses);
    }

    return $totalAddresses;
}


$db = new SQLite3('address.db');
createDatabaseTable($db);

$file_path = 'database/data.txt-large';
$file_handle = fopen($file_path, 'r');

if (isset($_GET['run'])) {
    runProcess($db, $file_handle);
}

// Get the total addresses in the database
$result = $db->query('SELECT COUNT(*) AS count FROM my_table');
$totalAddressesInDB = $result->fetchArray()['count'];

// Get the total addresses in the text file
//$totalAddressesInFile = count(file($file_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
$totalAddressesInFile = getOrCalculateTotalAddresses($file_path);

$lastProcessedLine = getLastProcessedLine();

// Close the SQLite database connection
$db->close();

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Address Processing</title>
    <script>
        let autoreloadpage = false;
        let reloadTime = <?=$reloadtime=1;?>;
        let reloading = false;
        let completion = 0;

        // Function to auto-reload the page
        function autoReload() {
            if (autoreloadpage && !reloading) {
                location.reload();
                reloading = true;
            }
        }

        // Function to start the process
        function startProcess() {
            // You can add AJAX or other mechanisms to initiate the server-side process
            autoreloadpage = true;
            alert('Process started');

            // Change the URL with the GET parameter "run"
            window.history.replaceState({}, document.title, window.location.pathname + '?run');
        }

        // Function to stop the process
        function stopProcess() {
            // You can add AJAX or other mechanisms to stop the server-side process
            autoreloadpage = false;
            alert('Process stopped');

            // Remove the "run" GET parameter from the URL
            window.history.replaceState({}, document.title, window.location.pathname);
        }
    </script>
</head>
<body>

<h1>Address Processing</h1>

<p>Total addresses in the database: <?php echo $totalAddressesInDB; ?></p>
<p>Total addresses in the text file: <?php echo $totalAddressesInFile; ?></p>
<p>Current line: <?php echo $lastProcessedLine; ?></p>
<p>Completion: <?php echo round($completion=$lastProcessedLine/$totalAddressesInFile*100,4); ?>%</p>

<button onclick="startProcess()">Start Process</button>
<button onclick="stopProcess()">Stop Process</button>

<p><em>This page will auto-reload every <?=$reloadtime;?> seconds.</em></p>

<script>
    // Check for the "run" GET parameter in the URL
    const urlParams = new URLSearchParams(window.location.search);
    const runParameter = urlParams.get('run');
    autoreloadpage = runParameter !== null;

    // Auto-reload the page every 10 seconds
    //setInterval(autoReload, reloadTime*1000);
    document.addEventListener('DOMContentLoaded', function() {
        completion = <?=$completion;?>;
        if  (completion >= 100) {
            stopProcess();
        }
        setInterval(autoReload, reloadTime*1000);
        console.log('DOM fully loaded and parsed');
    });
</script>

</body>
</html>


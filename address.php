<?php

$max_addresses_per_query = 300;
$dir_to_save_result = "balance-large/";
$file_last_processed_line = $dir_to_save_result . 'last_processed_line.txt';
$addresses_file  = 'database/data.txt-large';
$file_total_line = $dir_to_save_result . "total_lines.txt";


function getTotalLines(): int
{
    global $addresses_file, $file_total_line;
    if (!file_exists($file_total_line)) {
        $file = new \SplFileObject($addresses_file, 'r');
        $file->setFlags(SplFileObject::READ_AHEAD | SplFileObject::SKIP_EMPTY |
            SplFileObject::DROP_NEW_LINE);
        $file->seek(PHP_INT_MAX);
        $total_lines = $file->key() + 1;
        file_put_contents($file_total_line, $total_lines);
    } else {
        $total_lines = file_get_contents($file_total_line);
        $total_lines = intval($total_lines);
    }
    return $total_lines;
}

function get_last_processed_line(): int
{
    global $file_last_processed_line;
    return file_exists($file_last_processed_line) ? intval(file_get_contents($file_last_processed_line)) : 0;
}

$total_lines =  getTotalLines($addresses_file);
$last_processed_line = get_last_processed_line();

function read_from_file($filename, $limit, $startLine): array
{
    $addresses = [];

    if (file_exists($filename)) {
        $file = fopen($filename, 'r');

        // Skip lines until the start line
        for ($i = 1; $i < $startLine && !feof($file); ++$i) {
            fgets($file);
        }

        // Read lines up to the limit
        while (!feof($file) && count($addresses) < $limit) {
            $line = trim(fgets($file));
            if (!empty($line)) {
                $addresses[] = $line;
            }
        }

        fclose($file);
    }

    return $addresses;
}

function save_last_processed_line($line): void
{
    global $file_last_processed_line;
    file_put_contents($file_last_processed_line, $line);
}

function write_to_file($filename, $addresses): void
{
    $file = fopen($filename, 'a');
    if (is_array($addresses)) {
        foreach ($addresses as $item) {
            fwrite($file, $item . "\n");
        }
    } elseif (is_string($addresses)) {
        fwrite($file, $addresses . "\n");
    } else {
        echo "Ignoring unsupported data type: " . gettype($addresses) . "\n";
    }
    fclose($file);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $processedAddressesJson = $_POST['processedAddresses'] ?? '';

    if (!empty($processedAddressesJson)) {
        $processedAddresses = json_decode($processedAddressesJson, true);

        if ($processedAddresses[0] !== null) {
            if (empty($processedAddresses[0])) {
                echo 'No addresses with balance were found.';
            } else {
                foreach ($processedAddresses[0] as $processedAddress) {
                    $address = $processedAddress['address'];
                    $category = $processedAddress['category'];

                    // Use the appropriate filename based on the category or modify as needed
                    $filename = 'balance-large/' . $category . '_blockchaininfo.txt';
                    write_to_file($filename, $address);
                }
            }

            $address_count = $processedAddresses[1]['address_count'];
            $old_last_processed_line = $processedAddresses[1]['last_processed_line'];
            // Update the last processed line
            $lastProcessedLine = $old_last_processed_line + $address_count;
            save_last_processed_line($lastProcessedLine);

            echo 'Processed addresses saved successfully.';
        } else {
            echo 'Invalid JSON format.';
        }
    } else {
        echo 'No processed addresses received.';
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['getaddress'])) {
        $limit = $_GET['limit'] ?? $max_addresses_per_query;
        $addresses = read_from_file($addresses_file, $limit, $last_processed_line + 1);

        // Prepare the response JSON
        $response = [
            'last_processed_line' => $last_processed_line,
            'address_count' => count($addresses),
            'total_addresses' => $total_lines,
            'addresses' => $addresses
        ];
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($response);
    }

} else {
    echo 'Invalid request method.';
}

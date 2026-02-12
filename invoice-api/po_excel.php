<?php
// ================= CONFIG =================
$glideToken = "0b7d5906-52bb-4e5d-bad3-fbb979f6ab45";
$appID = "QLvFmO2RfU4bduMHwCy3";
$tableID = "native-table-TBaNw2pDNpCG1B9Q0v61";
$url = "https://api.glideapp.io/api/function/queryTables"; // working endpoint

// ================= FETCH DATA =================
$postData = json_encode([
    "appID" => $appID,
    "queries" => [
        [
            "tableName" => $tableID,
            "utc" => true
        ]
    ]
]);

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: Bearer $glideToken",
    "Content-Type: application/json"
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);

$response = curl_exec($ch);
if (curl_errno($ch)) {
    die("cURL Error: " . curl_error($ch));
}
curl_close($ch);

// Decode JSON
$data = json_decode($response, true);
if (!isset($data["responses"][0]["rows"])) {
    header("Content-Type: text/plain");
    echo "❌ No data returned. Response:\n\n" . $response;
    exit;
}

$rows = $data["responses"][0]["rows"];

// ================= CSV DOWNLOAD =================
$filename = "purchase_order_items_" . date("Y-m-d_H-i-s") . ".csv";

// Set headers so it downloads automatically
header('Content-Type: text/csv; charset=utf-8');
header("Content-Disposition: attachment; filename=$filename");

// Open output stream
$output = fopen('php://output', 'w');

// Column headers (customize order below)
fputcsv($output, [
    'Row ID',
    'Invoice Row ID',
    'Item Name',
    'UOM',
    'Quantity',
    'Unit Price',
    'Discount',
    'Tax (%)',
    'Lead Time',
    'Brand & Manufacturer'
]);

// Write each row to CSV
foreach ($rows as $row) {
    fputcsv($output, [
        $row["\$rowID"] ?? "",
        $row["Name"] ?? "",
        $row["42jVA"] ?? "",
        $row["60IkG"] ?? "",
        $row["ju9vS"] ?? "",
        $row["fMfgO"] ?? "",
        $row["MBGbK"] ?? "",
        $row["yMuno"] ?? "",
        $row["YF8Ll"] ?? "",
        $row["Fn0F1"] ?? ""
    ]);
}

fclose($output);
exit;
?>

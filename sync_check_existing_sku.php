<?php
$apiKey = "apiofprestashop";
$shopUrl = "shopurl/api";
$outputFile       = __DIR__ . 'sku_shop_files.txt';

function sendCurl($url, $method = 'GET', $headers = [], $body = null) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    if ($body) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $response = curl_exec($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);
    return ['response' => $response, 'code' => $info['http_code']];
}

function logMsg($msg, $type = "info") {
    $prefix = [
        "info" => "ℹ️", "success" => "✅", "error" => "❌", "warn" => "⚠️"
    ][$type] ?? "🔸";
    echo "$prefix $msg\n";
}

function fetchAllSkusInOneRequest($shopUrl, $apiKey, $outputFile) {
    $url = "$shopUrl/api/products?ws_key=$apiKey&display=[id,reference]&limit=999999";
    logMsg("➡️ Pobieram WSZYSTKIE produkty (id + reference)...");

    $res = sendCurl($url, 'GET', ['Accept: application/xml']);
    $xml = simplexml_load_string($res['response']);
    $products = $xml->products->product ?? [];

    if (empty($products)) {
        logMsg("❌ Brak danych lub błąd pobierania", "error");
        return;
    }

    $skus = [];
    foreach ($products as $product) {
        $sku = (string)$product->reference;
        if (!empty($sku)) $skus[] = $sku;
    }

    file_put_contents($outputFile, implode(PHP_EOL, $skus));
    logMsg("✅ Zapisano " . count($skus) . " SKU do pliku $outputFile", "success");
}


fetchAllSkusInOneRequest($shopUrl, $apiKey, $outputFile);


<?php
// === KONFIGURACJA ===
$email = "email";
$password = "password";
$endpoint = "graphql";
$fileQtyNow  = __DIR__ . '/../qty_files.txt';
$dbHost = '127.0.0.1';
$dbName   = 'database';
$dbUser = 'username';
$dbPass = 'password';
$dbCharset = 'utf8mb4';
$shopUrl = "shopurl";
$imgFile =  __DIR__ . "img.txt";
$skuShopFile = __DIR__ . "sku.txt";
$placeholder = "placeholder.jpg";
$processedFile =  __DIR__ . "processed.txt";

// === FUNKCJE POMOCNICZE ===
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
    $error = curl_error($ch);
    curl_close($ch);
    return ['response' => $response, 'code' => $info['http_code'], 'error' => $error];
}

function logMsg($msg, $type = "info") {
    $prefix = [
        "info" => "ℹ️",
        "success" => "✅",
        "error" => "❌",
        "warn" => "⚠️",
    ][$type] ?? "🔸";
    echo "$prefix $msg\n";
}

function prestaGetProductIdBySku($apiUrl, $apiKey, $sku) {
    $res = sendCurl("$apiUrl?filter[reference]=$sku&ws_key=$apiKey", 'GET', ['Accept: application/xml']);
    $xml = simplexml_load_string($res['response']);
    return (int)($xml->products->product['id'] ?? 0);
}

function prestaProductHasImage($shopUrl, $apiKey, $productId) {
    $res = sendCurl("$shopUrl/api/images/products/$productId?ws_key=$apiKey", 'GET', ['Accept: application/xml']);
    $xml = simplexml_load_string($res['response']);
    return isset($xml->image);
}

function prestaAddProductImage($shopUrl, $apiKey, $productId, $imageUrl) {
    $imageData = @file_get_contents($imageUrl);
    if (!$imageData) {
        logMsg("Nie udało się pobrać zdjęcia z $imageUrl", "error");
        return false;
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_buffer($finfo, $imageData);
    finfo_close($finfo);
    $ext = explode('/', $mimeType)[1] ?? 'jpg';
    if ($ext === 'webp') $ext = 'jpg';

    $tmpFile = tempnam(sys_get_temp_dir(), 'img') . ".$ext";
    file_put_contents($tmpFile, $imageData);

    $res = sendCurl("$shopUrl/api/images/products/$productId?ws_key=$apiKey", 'POST', [
        "Accept: application/xml", "Expect:"
    ], ['image' => new CURLFile($tmpFile)]);

    unlink($tmpFile);

    if ($res['code'] === 201) {
        logMsg("Zdjęcie dodane do produktu ID: $productId", "success");
        return true;
    } else {
        logMsg("Błąd dodania zdjęcia: {$res['code']}", "error");
        return false;
    }
}

// === WCZYTANIE LISTY SKU W SKLEPIE ===
$skuShopList = array_map('trim', file($skuShopFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
$skuShopList = array_filter($skuShopList);
$skuShopSet = array_flip($skuShopList);

// === WCZYTANIE PRZETWORZONYCH SKU ===
$processedSkus = file_exists($processedFile)
    ? array_map('trim', file($processedFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES))
    : [];
$processedSet = array_flip($processedSkus);

// === GŁÓWNA PĘTLA ===
$lines = file($imgFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
foreach ($lines as $line) {
    [$sku, $url] = explode(';', trim($line));
    if (!$sku || !$url || $url === $placeholder) {
        logMsg("Pomijam SKU $sku – brak lub placeholder", "warn");
        continue;
    }

    if (!isset($skuShopSet[$sku])) {
        logMsg("Pomijam SKU $sku – nie istnieje w sklepie", "warn");
        continue;
    }

    if (isset($processedSet[$sku])) {
        logMsg("Pomijam SKU $sku – już przetworzony", "info");
        continue;
    }

    logMsg("Przetwarzam SKU: $sku");

    $productId = prestaGetProductIdBySku("$shopUrl/api/products", $psApiKey, $sku);
    if (!$productId) {
        logMsg("Nie znaleziono produktu o SKU: $sku", "error");
        continue;
    }

    if (prestaProductHasImage($shopUrl, $psApiKey, $productId)) {
        logMsg("Produkt ID $productId ma już zdjęcie", "info");
        file_put_contents($processedFile, "$sku\n", FILE_APPEND);
    } else {
        $result = prestaAddProductImage($shopUrl, $psApiKey, $productId, $url);
    }
}

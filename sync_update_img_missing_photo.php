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
// === FUNKCJE ===
function sendCurl($url, $method = 'GET', $headers = [], $body = null, $files = null) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    if ($files) curl_setopt($ch, CURLOPT_POSTFIELDS, $files);
    elseif ($body) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $response = curl_exec($ch);
    $info = curl_getinfo($ch);
    $error = curl_error($ch);
    curl_close($ch);
    return ['response' => $response, 'code' => $info['http_code'], 'error' => $error];
}

function logMsg($msg, $type = "info") {
    $prefix = [
        "info" => "ℹ️", "success" => "✅", "error" => "❌", "warn" => "⚠️"
    ][$type] ?? "🔸";
    echo "$prefix $msg\n";
}

function prestaGetProductIdBySku($shopUrl, $apiKey, $sku) {
    $res = sendCurl("$shopUrl/api/products?filter[reference]=$sku&ws_key=$apiKey", 'GET', ['Accept: application/xml']);
    $xml = @simplexml_load_string($res['response']);
    return (int)($xml->products->product['id'] ?? 0);
}

function prestaProductHasImage($shopUrl, $apiKey, $productId) {
    $res = sendCurl("$shopUrl/api/images/products/$productId?ws_key=$apiKey", 'GET', ['Accept: application/xml']);
    $xml = @simplexml_load_string($res['response']);
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
    ], null, ['image' => new CURLFile($tmpFile)]);

    unlink($tmpFile);

    if ($res['code'] === 201) {
        logMsg("✅ Zdjęcie dodane do produktu ID: $productId", "success");
        return true;
    } else {
        logMsg("❌ Błąd dodania zdjęcia (HTTP {$res['code']})", "error");
        return false;
    }
}

// === GŁÓWNE WYKONANIE ===
$stmt = $db->query("SELECT Product_ID, Reference, ZdjecieURL FROM ps_sync WHERE Sync = 'yes' AND ZdjecieURL IS NOT NULL");
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($products as $p) {
    $productId = $p['Product_ID'];
    $sku = $p['Reference'];
    $imgUrl = $p['ZdjecieURL'];

    logMsg("🔄 Przetwarzam SKU: $sku");

    if (!$imgUrl || str_contains($imgUrl, 'placeholder')) {
        logMsg("⚠️ Pomijam placeholder / brak URL", "warn");
        continue;
    }

    if (prestaProductHasImage($shopUrl, $psApiKey, $productId)) {
        logMsg("ℹ️ Produkt ma już zdjęcie, aktualizacja niepotrzebna");
        // mimo wszystko oznacz jako zsynchronizowany
        $db->prepare("UPDATE ps_sync SET Sync = 'no', ZdjecieIstnieje = 1 WHERE Reference = ?")->execute([$sku]);
        continue;
    }

    if (prestaAddProductImage($shopUrl, $psApiKey, $productId, $imgUrl)) {
        $db->prepare("UPDATE ps_sync SET Sync = 'no', ZdjecieIstnieje = 1 WHERE Reference = ?")->execute([$sku]);
    }
}

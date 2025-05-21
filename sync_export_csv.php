<?php

$$email = "email";
$password = "password";
$endpoint = "graphql";
$fileQtyNow  = __DIR__ . '/../qty_files.txt';
$dbHost = '127.0.0.1';
$dbName   = 'database';
$dbUser = 'username';
$dbPass = 'password';
$dbCharset = 'utf8mb4';
require_once 'sync_functions.php';
$skuFile = "sku.txt";
$csvFilePath = "distribution.csv";
$processedSkuFile = "done.txt";

if (!file_exists($skuFile)) {
    echo "❌ Nie znaleziono pliku SKU\n";
    exit(1);
}

$skuList = file($skuFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

// Wczytaj już przetworzone SKU
$processedSkus = file_exists($processedSkuFile) 
    ? file($processedSkuFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) 
    : [];

$skuListToProcess = array_diff($skuList, $processedSkus); // Pomijamy już przetworzone

if (empty($skuListToProcess)) {
    echo "✅ Wszystkie SKU zostały już przetworzone\n";
    exit(0);
}

// Login
$loginQuery = <<<GQL
mutation {
  generateCustomerToken(email: "$email", password: "$password") {
    token
  }
}
GQL;

$res = sendCurl($endpoint, 'POST', ['Content-Type: application/json'], json_encode(['query' => $loginQuery]));
$data = json_decode($res['response'], true);
$token = $data['data']['generateCustomerToken']['token'] ?? null;

if (!$token) {
    logMsg("❌ Błąd logowania do hurtowni", "error");
    exit(1);
}

// CSV: tylko jeśli plik nie istnieje, dodaj nagłówki
if (!file_exists($csvFilePath)) {
    file_put_contents($csvFilePath, "SKU;Nazwa;Opis;Stan magazynowy;Cena brutto;Kategoria;Link do zdjęcia\n");
}

$chunks = array_chunk($skuListToProcess, 100);
foreach ($chunks as $chunk) {
    try {
        $query = buildMultiSkuQuery($chunk);
        $res = sendCurl($endpoint, 'POST', [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token
        ], json_encode(['query' => $query]));

        $data = json_decode($res['response'], true);
        $items = $data['data']['products']['items'] ?? [];

        foreach ($items as $product) {
            writeProductToCSV($product, $csvFilePath);
            file_put_contents($processedSkuFile, $product['sku'] . "\n", FILE_APPEND); // zapisz SKU jako przetworzone
            logMsg("✅ Zapisano: " . $product['sku'], "success");
        }

        // OPCJONALNE: timeout w sekundach (np. 290 sekund = ~5 minut)
        if (time() - $_SERVER["REQUEST_TIME"] > 290) {
            logMsg("⏱️ Timeout - przerwanie skryptu i kontynuacja przy następnym uruchomieniu", "warning");
            break;
        }

    } catch (Throwable $e) {
        logMsg("❌ Błąd przy porcji SKU: " . implode(', ', $chunk) . " - " . $e->getMessage(), "error");
    }
}

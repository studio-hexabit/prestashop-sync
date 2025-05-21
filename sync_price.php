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

$batchSize     = 100;             // ile SKU na raz pobieramy i przetwarzamy
$thresholdSec  = 10 * 3600;       // próg 10 godzin w sekundach

// === FUNKCJE POMOCNICZE ===

/**
 * Wysyła żądanie HTTP przez cURL i zwraca odpowiedź jako string.
 * Kończy skrypt, jeżeli wystąpi błąd cURL.
 *
 * @param string       $url     URL żądania
 * @param string       $method  GET|POST|PUT|DELETE
 * @param string[]     $headers nagłówki HTTP
 * @param string|null  $body    ciało żądania (JSON, XML, itp.)
 * @return string
 */
function sendCurl(string $url, string $method = 'GET', array $headers = [], ?string $body = null): string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 30,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $resp = curl_exec($ch);
    if ($err = curl_error($ch)) {
        exit("❌ cURL error: $err\n");
    }
    curl_close($ch);
    return $resp;
}

/**
 * Loguje wiadomość z timestampem i ikoną (info/success/warn/error).
 *
 * @param string $msg
 * @param string $type one of: info|success|warn|error
 * @return void
 */
function logMsg(string $msg, string $type = 'info'): void {
    $icons = ['info'=>'ℹ️','success'=>'✅','warn'=>'⚠️','error'=>'❌'];
    $icon  = $icons[$type] ?? '';
    echo date('[Y-m-d H:i:s] ') . "$icon $msg\n";
}

/**
 * Zapisuje mapę ostatnich aktualizacji do pliku JSON (atomowo).
 *
 * @param array<string,int> $lastUpdate
 * @param string            $stateFile
 * @return void
 */
function saveLastUpdate(array $lastUpdate, string $stateFile): void {
    $dir = dirname($stateFile);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    file_put_contents($stateFile, json_encode($lastUpdate, JSON_PRETTY_PRINT), LOCK_EX);
}

/**
 * Pobiera ceny brutto (regular_price) dla podanych SKU z GraphQL.
 * Dzieli na paczki po 1000, ustawia pageSize=1000.
 *
 * @param string       $endpoint
 * @param string       $token
 * @param string[]     $skus
 * @return array<string,float> mapa SKU => cena
 */
function fetchAllPrices(string $endpoint, string $token, array $skus): array {
    $map    = [];
    $chunks = array_chunk($skus, 1000);
    foreach ($chunks as $chunk) {
        $quoted = array_map(fn($s)=>addslashes($s), $chunk);
        $inList = implode('","', $quoted);
        $query  = <<<GQL
{
  products(
    filter:{ sku:{ in:["$inList"] } }
    pageSize:1000
  ) {
    items {
      sku
      price_range {
        minimum_price {
          regular_price { value }
        }
      }
    }
  }
}
GQL;
        $resp = sendCurl(
            $endpoint,
            'POST',
            [
                'Content-Type: application/json',
                "Authorization: Bearer $token"
            ],
            json_encode(['query'=>$query])
        );
        $json = json_decode($resp, true);
        if (!empty($json['errors'] ?? null)) {
            logMsg("Błąd GraphQL: ".json_encode($json['errors']), 'error');
            exit;
        }
        foreach ($json['data']['products']['items'] as $p) {
            $val = $p['price_range']['minimum_price']['regular_price']['value'] ?? null;
            if ($val !== null) {
                $map[$p['sku']] = (float)$val;
            } else {
                logMsg("⚠️ Brak price_range dla SKU: {$p['sku']}", 'warn');
            }
        }
    }
    return $map;
}

/**
 * Pobiera ID produktu w PrestaShop na podstawie SKU (reference).
 *
 * @param string $apiUrl
 * @param string $apiKey
 * @param string $sku
 * @return int
 */
function prestaGetProductIdBySku(string $apiUrl, string $apiKey, string $sku): int {
    $res = sendCurl("$apiUrl?filter[reference]=$sku&ws_key=$apiKey", 'GET', ['Accept: application/xml']);
    $xml = @simplexml_load_string($res);
    return (int)($xml->products->product['id'] ?? 0);
}

/**
 * Aktualizuje cenę produktu w PrestaShop:
 *  - id_tax_rules_group = 0 (0% VAT)
 *  - price = brutto jako netto (6 miejsc po przecinku)
 *
 * @param string $shopUrl
 * @param string $apiKey
 * @param int    $productId
 * @param float  $grossPrice
 * @return bool  true jeśli sukces
 */
function prestaUpdateProductPrice(string $shopUrl, string $apiKey, int $productId, float $grossPrice): bool {
    $url = "$shopUrl/api/products/$productId?ws_key=$apiKey";
    $res = sendCurl($url, 'GET', ['Accept: application/xml']);
    if (strpos($res, '<product') === false) {
        logMsg("Błąd GET produktu #$productId", 'error');
        return false;
    }
    $xml = simplexml_load_string($res);
    foreach (['position_in_category','manufacturer_name','quantity','new','indexed','associations'] as $f) {
        unset($xml->product->$f);
    }
    $xml->product->id_tax_rules_group = 0;
    $xml->product->price              = number_format($grossPrice, 6, '.', '');
    $resp = sendCurl($url, 'PUT', ['Content-Type: text/xml'], $xml->asXML());
    if (strpos($resp, '<prestashop') !== false) {
        logMsg("Zaktualizowano cenę #$productId: {$xml->product->price}", 'success');
        return true;
    }
    logMsg("Błąd PUT ceny #$productId", 'error');
    return false;
}

// === MAIN ===

// 1) Logowanie do GraphQL
$loginGQL = <<<GQL
mutation {
  generateCustomerToken(email:"{$email}",password:"{$password}") {
    token
  }
}
GQL;
$loginResp = sendCurl($endpoint, 'POST', ['Content-Type: application/json'], json_encode(['query'=>$loginGQL]));
$token     = json_decode($loginResp, true)['data']['generateCustomerToken']['token'] ?? null;
if (!$token) {
    exit("❌ Nie udało się zalogować do hurtowni.\n");
}
logMsg("Zalogowano do hurtowni", 'success');

// 2) Wczytaj wszystkie SKU
if (!file_exists($allSkusFile)) {
    exit("❌ Nie znaleziono pliku SKU: $allSkusFile\n");
}
$allSkus = file($allSkusFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
if (empty($allSkus)) {
    exit("ℹ️ Plik SKU jest pusty.\n");
}

// 3) Wczytaj stan ostatnich aktualizacji
$lastUpdate = [];
if (file_exists($stateFile)) {
    $lastUpdate = json_decode(file_get_contents($stateFile), true) ?: [];
}
$now          = time();
$totalBatches = ceil(count($allSkus) / $batchSize);

// 4) Przetwarzaj w batchach
foreach (array_chunk($allSkus, $batchSize) as $idx => $chunk) {
    $batchNo = $idx + 1;
    logMsg("=== Batch $batchNo/$totalBatches: przetwarzam ".count($chunk)." SKU ===");
    $prices = fetchAllPrices($endpoint, $token, $chunk);

    foreach ($chunk as $sku) {
        // 4.1) brak ceny z GraphQL
        if (!isset($prices[$sku])) {
            logMsg("Pominięto $sku — brak ceny w GraphQL", 'warn');
            continue;
        }
        // 4.2) sprawdź czy minęło 10h od ostatniej aktualizacji
        $last = $lastUpdate[$sku] ?? 0;
        if ($now - $last < $thresholdSec) {
            $h = round(($now - $last) / 3600, 1);
            logMsg("Pominięto $sku — ostatnia aktualizacja $h h temu", 'info');
            continue;
        }
        // 4.3) znajdź ID w PrestaShop
        $prodId = prestaGetProductIdBySku($psApiUrl, $psApiKey, $sku);
        if (!$prodId) {
            logMsg("Nie znaleziono produktu $sku w PrestaShop", 'warn');
            continue;
        }
        // 4.4) wykonaj update i zapisz od razu stan
        if (prestaUpdateProductPrice($shopUrl, $psApiKey, $prodId, $prices[$sku])) {
            $lastUpdate[$sku] = time();
            saveLastUpdate($lastUpdate, $stateFile);
        }
    }
}

logMsg("🏁 Synchronizacja cen zakończona.", 'success');

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

// === DB PDO ===
$db = new PDO("mysql:host=localhost;dbname=radosawm_ps;charset=utf8", "radosawm_ps", "zy1AnyD3E2hk");

// === FUNKCJE ===
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
        "info" => "ℹ️", "success" => "✅", "error" => "❌", "warn" => "⚠️"
    ][$type] ?? "🔸";
    echo "$prefix $msg\n";
}

// === LOGOWANIE DO HURTOWNI ===
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
    print_r($data);
    exit;
}

// === POBIERZ SKU Z BAZY (brak zdjęcia) ===
$stmt = $db->query("SELECT Reference FROM ps_sync WHERE ZdjecieIstnieje = 0");
$skuList = $stmt->fetchAll(PDO::FETCH_COLUMN);

if (empty($skuList)) {
    logMsg("Brak SKU do aktualizacji.", "warn");
    exit;
}

// === GRUPOWANIE I ZAPYTANIA GRAPHQL ===
foreach (array_chunk($skuList, 20) as $chunk) {
    $skuFilter = implode('", "', $chunk);
    $query = <<<GQL
    {
      products(filter: { sku: { in: ["$skuFilter"] } }) {
        items {
          sku
          image { url label }
        }
      }
    }
    GQL;

    $res = sendCurl($endpoint, 'POST', [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $token
    ], json_encode(['query' => $query]));

    $data = json_decode($res['response'], true);
    $products = $data['data']['products']['items'] ?? [];

    foreach ($products as $product) {
        $sku = $product['sku'];
        $img = $product['image']['url'] ?? '';

        if (!$img || str_contains($img, 'placeholder')) {
            logMsg("⚠️ Brak zdjęcia lub placeholder dla $sku", "warn");
            continue;
        }

        $stmt = $db->prepare("UPDATE ps_sync SET ZdjecieURL = ?, Sync = 'yes' WHERE Reference = ?");
        $stmt->execute([$img, $sku]);

        logMsg("✅ Zaktualizowano URL zdjęcia dla $sku");
    }
}
?>

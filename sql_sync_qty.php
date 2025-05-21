<?php
$email = "email";
$password = "password";
$endpoint = "graphql";
$fileQtyNow  = __DIR__ . '/../qty_files.txt';
$dbHost = '127.0.0.1';
$dbName   = 'database';
$dbUser = 'username';
$dbPass = 'password';
$dbCharset = 'utf8mb4';
function logMsg($msg, $type = "info") {
    $prefix = [
        "info" => "ℹ️",
        "success" => "✅",
        "error" => "❌",
        "warn" => "⚠️",
    ][$type] ?? "🔸";
    $now = new DateTime('now', new DateTimeZone('Europe/Warsaw'));
    $timestamp = $now->format('Y-m-d H:i:s');
    echo "[$timestamp] $prefix $msg\n";
}
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
    logMsg("Błąd logowania do hurtowni", "error");
    exit;
}
logMsg("Zalogowano do hurtowni", "success");
$out = fopen($fileQtyNow, 'w');
fwrite($out, "sku;qty\n");
$page = 1;
do {
    $query = <<<GQL
    {
      products(search: "", pageSize: 1000, currentPage: $page) {
        items {
          sku
          stock_availability { in_stock_real }
        }
        page_info {
          current_page
          total_pages
        }
      }
    }
    GQL;
    $res = sendCurl($endpoint, 'POST', [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $token
    ], json_encode(['query' => $query]));
    $data = json_decode($res['response'], true);
    $items = $data['data']['products']['items'] ?? [];
    $totalPages = $data['data']['products']['page_info']['total_pages'] ?? 1;
    foreach ($items as $item) {
        $sku = $item['sku'];
        $qty = (int)($item['stock_availability']['in_stock_real'] ?? 0);
        fwrite($out, "$sku;$qty\n");
        logMsg("Zapisano SKU: $sku | Ilość: $qty");
    }

    $page++;
} while ($page <= $totalPages);
fclose($out);
logMsg("Plik $fileQtyNow został wygenerowany", "success");
$dsn = "mysql:host=$dbHost;dbname=$dbName;charset=$dbCharset";
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];
try {
    $pdo = new PDO($dsn, $dbUser, $dbPass, $options);
    logMsg("Połączono z bazą danych", "success");
} catch (PDOException $e) {
    logMsg("Błąd połączenia z bazą: " . $e->getMessage(), "error");
    exit;
}
logMsg("Sprawdzam nowe produkty...");
$newProducts = $pdo->query("
    SELECT p.id_product, p.reference 
    FROM ps_product p 
    LEFT JOIN ps_stock_agro s ON p.id_product = s.id_product 
    WHERE s.id_product IS NULL AND p.reference IS NOT NULL AND p.reference != ''
")->fetchAll();
if (count($newProducts) > 0) {
    $insert = $pdo->prepare("
        INSERT INTO ps_stock_agro (reference, id_product, qty, old_qty, sync)
        VALUES (?, ?, 0, 0, 'no')
    ");
    foreach ($newProducts as $prod) {
        $insert->execute([$prod['reference'], $prod['id_product']]);
        logMsg("➕ Dodano nowy produkt do ps_stock_agro: SKU {$prod['reference']} | ID: {$prod['id_product']}");
    }
    logMsg("Dodano " . count($newProducts) . " nowych produktów do ps_stock_agro", "success");
} else {
    logMsg("Brak nowych produktów do dodania", "info");
}
$lines = file($fileQtyNow, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
foreach ($lines as $index => $line) {
    if ($index === 0 && str_starts_with($line, 'sku;')) continue;
    list($sku, $qty) = explode(';', trim($line));
    $qty = (int)$qty;
    $stmt = $pdo->prepare("SELECT id_product FROM ps_product WHERE reference = ?");
    $stmt->execute([$sku]);
    $product = $stmt->fetch();
    if ($product) {
        $id_product = $product['id_product'];
        $check = $pdo->prepare("SELECT qty FROM ps_stock_agro WHERE reference = ?");
        $check->execute([$sku]);
        $existing = $check->fetch();
        if ($existing) {
            $old_qty = (int)$existing['qty'];
            $sync = ($old_qty !== $qty) ? 'yes' : 'no';
            $update = $pdo->prepare("UPDATE ps_stock_agro SET old_qty = qty, qty = ?, id_product = ?, sync = ? WHERE reference = ?");
            $update->execute([$qty, $id_product, $sync, $sku]);
            logMsg("Zaktualizowano SKU $sku | Qty: $qty (Stara: $old_qty) | Sync: $sync");
        } else {
            $insert = $pdo->prepare("INSERT INTO ps_stock_agro (reference, id_product, qty, old_qty, sync) VALUES (?, ?, ?, 0, 'yes')");
            $insert->execute([$sku, $id_product, $qty]);
            logMsg("Dodano nowy SKU $sku | Qty: $qty | Sync: yes");
            $sync = 'yes';
        }
        if ($sync === 'yes') {
            $updateStock = $pdo->prepare("UPDATE ps_stock_available SET quantity = ? WHERE id_product = ? AND id_product_attribute = 0");
            $updateStock->execute([$qty, $id_product]);
            logMsg("Zsynchronizowano ps_stock_available dla ID $id_product: $qty", "success");
        }
    } else {
        logMsg("Nie znaleziono produktu dla SKU: $sku", "warn");
    }
}
<?php
/**
 * sql_sync_price.php
 *
 * 1) If ps_stock_agro_price is empty:
 *      – pull id_product, reference & current price from PrestaShop tables
 *      – insert into ps_stock_agro_price with old=new and sync='no'
 * 2) Authenticate to GraphQL
 * 3) Page-by-page (1 000 SKUs) fetch of all prices:
 *      – UPDATE ps_stock_agro_price SET new_price=?, sync=IF(old_price<>?, 'yes', sync), last_checked=NOW()
 * 4) Bulk-SQL APPLY:
 *      – for sync='yes' rows, one single UPDATE … CASE to ps_product & ps_product_shop
 *      – then UPDATE ps_stock_agro_price SET old_price=new_price, sync='no', last_checked=NOW()
 * 5) Clear PrestaShop cache directory
 *
 * ⚠️ This bypasses PrestaShop hooks & indexers. After running:
 *    • re-run your search indexer (CLI or BO)
 *    • clear any other caches (e.g. Symfony cache if on PS 1.7+)
 */

declare(strict_types=1);
date_default_timezone_set('Europe/Tirane');

// — CONFIG — 
const GQL_ENDPOINT  = 'sync-endpoint';
const GQL_EMAIL     = 'e-mail';
const GQL_PASSWORD  = 'password';

const DB_DSN        = 'mysql:host=127.0.0.1;dbname=database;charset=utf8mb4';
const DB_USER       = 'username';
const DB_PASS       = 'password';

const PS_SHOP_URL   = 'https://adressklepu.com';
const PS_API_KEY    = 'TUTAJAPI';

const LOG_FILE      = __DIR__ . '/sync_price_sql.log';
const PAGE_SIZE     = 1000;
const CACHE_DIR     = __DIR__ . '/../var/cache/prod';

// — LOGGING HELPERS —
function logMsg(string $msg, string $type = 'info'): void {
    static $icons = [
        'info'    => 'ℹ️','success'=>'✅','warn'=>'⚠️','error'=>'❌'
    ];
    $line = sprintf("[%s] %s %s\n",
        date('Y-m-d H:i:s'),
        $icons[$type] ?? '',
        $msg
    );
    echo $line;
    file_put_contents(LOG_FILE, $line, FILE_APPEND);
}

/**
 * Simple cURL wrapper: throws on HTTP >=300 or network errors
 */
function sendCurl(string $url, string $method='GET', array $headers=[], ?string $body=null): string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 60,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err) {
        logMsg("Network error on $url: $err", 'error');
        exit(1);
    }
    if ($code >= 300) {
        $snippet = substr($resp, 0, 500);
        logMsg("HTTP $code from $url — snippet:\n$snippet", 'error');
        exit(1);
    }
    return $resp;
}

// — 1) CONNECT TO DB —
try {
    $pdo = new PDO(DB_DSN, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    logMsg('Connected to MySQL database', 'success');
} catch (PDOException $e) {
    logMsg('DB connection failed: ' . $e->getMessage(), 'error');
    exit(1);
}

// — 2) INITIAL FILL IF EMPTY —
$count = (int)$pdo->query("SELECT COUNT(*) FROM ps_stock_agro_price")->fetchColumn();
if ($count === 0) {
    logMsg('ps_stock_agro_price empty → importing current PS prices', 'info');
    $inserted = $pdo->exec(<<<SQL
INSERT INTO ps_stock_agro_price
  (reference, id_product, old_price, new_price, sync)
SELECT
  p.reference,
  p.id_product,
  ps.price AS old_price,
  ps.price AS new_price,
  'no'
FROM ps_product p
JOIN ps_product_shop ps
  ON p.id_product = ps.id_product
 AND ps.id_shop = 1
WHERE p.reference <> ''
SQL
    );
    logMsg("Inserted {$inserted} rows into ps_stock_agro_price", 'success');
}

// — 3) GRAPHQL AUTHENTICATION —
$loginMutation = sprintf(
    'mutation { generateCustomerToken(email:"%s", password:"%s") { token } }',
    GQL_EMAIL, GQL_PASSWORD
);

$loginResp = sendCurl(
    GQL_ENDPOINT,
    'POST',
    ['Content-Type: application/json'],
    json_encode(['query' => $loginMutation])
);

$loginData = json_decode($loginResp, true);
$token = $loginData['data']['generateCustomerToken']['token'] ?? null;
if (!$token) {
    logMsg('Failed to obtain GraphQL token', 'error');
    exit(1);
}
logMsg('GraphQL authentication successful', 'success');

// — PREPARE UPDATE STATEMENT —
$updateStmt = $pdo->prepare(<<<'SQL'
UPDATE ps_stock_agro_price
   SET new_price    = :new_price,
       sync         = IF(old_price <> :new_price, 'yes', sync),
       last_checked = NOW()
 WHERE reference   = :ref
SQL
);

// — 4) PHASE 1: STREAMED GRAPHQL FETCH & UPDATE NEW_PRICES —
logMsg('Phase 1: fetching all prices from GraphQL & updating new_price', 'info');

$page       = 1;
$totalPages = PHP_INT_MAX;
$queryGQL   = str_replace(
    'PAGE_SIZE',
    (string)PAGE_SIZE,
    <<<'GQL'
query getPrices($page:Int!) {
  products(search:"", pageSize: PAGE_SIZE, currentPage: $page) {
    items {
      sku
      price_range { minimum_price { regular_price { value } } }
    }
    page_info { current_page total_pages }
  }
}
GQL
);

while ($page <= $totalPages) {
    logMsg("Fetching GraphQL page $page/$totalPages", 'info');

    $payload = ['query' => $queryGQL, 'variables' => ['page' => $page]];
    $resp    = sendCurl(
        GQL_ENDPOINT,
        'POST',
        [
            'Content-Type: application/json',
            "Authorization: Bearer $token"
        ],
        json_encode($payload)
    );

    $data = json_decode($resp, true)['data']['products'] ?? null;
    if (!$data) {
        logMsg("Malformed GraphQL response on page $page", 'error');
        exit(1);
    }
    if ($page === 1) {
        $totalPages = (int)$data['page_info']['total_pages'];
        logMsg("Total GraphQL pages: $totalPages", 'info');
    }

    foreach ($data['items'] as $item) {
        $sku      = $item['sku'];
        $rawGross = (float)$item['price_range']['minimum_price']['regular_price']['value'];
        $gross    = round($rawGross, 2);
        $net      = round($gross / 1.23, 6);

        $updateStmt->execute([
            ':new_price' => number_format($net, 6, '.', ''),
            ':ref'       => $sku,
        ]);

        logMsg("→ {$sku}: gross={$gross} → netto={$net}", 'info');
    }  // ← close foreach here!

    $page++;  // ← and then increment your page
}

logMsg('Phase 1 complete: new_price & sync flags set', 'success');

// — 5) PHASE 2: BULK DIRECT-SQL SYNC TO PRESTASHOP TABLES —
logMsg('Phase 2: bulk apply price changes via SQL', 'info');

$toSync = $pdo
    ->query("SELECT id_product, new_price FROM ps_stock_agro_price WHERE sync = 'yes'")
    ->fetchAll();

if (empty($toSync)) {
    logMsg('No price changes detected; nothing to apply', 'info');
} else {
    $pdo->beginTransaction();
    $ids    = array_column($toSync, 'id_product');
    $cases  = [];
    $params = [];

    foreach ($toSync as $row) {
        $cases[]   = "WHEN {$row['id_product']} THEN ?";
        $params[]  = number_format((float)$row['new_price'], 6, '.', '');
    }
    $inList  = implode(',', $ids);
    $caseSql = implode(' ', $cases);

    // Update ps_product
    $pdo->prepare("
        UPDATE ps_product
           SET price = CASE id_product
               {$caseSql}
             ELSE price END
         WHERE id_product IN ({$inList})
    ")->execute($params);
    logMsg('ps_product updated for '.count($ids).' items', 'success');

    // Update ps_product_shop (shop ID=1)
    $pdo->prepare("
        UPDATE ps_product_shop
           SET price = CASE id_product
               {$caseSql}
             ELSE price END
         WHERE id_product IN ({$inList})
           AND id_shop = 1
    ")->execute($params);
    logMsg('ps_product_shop updated for '.count($ids).' items', 'success');

    // Sync old_price ← new_price, clear flags
    $pdo->prepare("
        UPDATE ps_stock_agro_price
           SET old_price    = new_price,
               sync         = 'no',
               last_checked = NOW()
         WHERE id_product IN ({$inList})
    ")->execute();
    logMsg('ps_stock_agro_price old_price & sync flags reset', 'info');

    $pdo->commit();
    logMsg('Phase 2 complete: prices applied', 'success');
}

// — 6) CLEAR CACHE —
if (is_dir(CACHE_DIR)) {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(CACHE_DIR, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $file) {
        $file->isDir() ? @rmdir($file) : @unlink($file);
    }
    @rmdir(CACHE_DIR);
    logMsg('Cleared PrestaShop cache', 'info');
}

logMsg('🏁 All done.', 'success');

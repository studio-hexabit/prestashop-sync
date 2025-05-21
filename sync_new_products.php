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
$psLanguageId = 1;
$psRootCategoryId = 2; // Strona Główna w PrestaShop
$startTime = time();
$maxExecutionTime = 295; // sekund

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
    $timestamp = date('[Y-m-d H:i:s]', strtotime('+2 hours'));
    echo "$timestamp $prefix $msg\n";
}


$existingSkus = file_exists($existingSkusFile) ? file($existingSkusFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
$noExistSkus = file_exists('sku_no_exist.txt') ? file('sku_no_exist.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
$excludedSkus = array_unique(array_merge($existingSkus, $noExistSkus));
$allSkus = file_exists($allSkusFile) ? file($allSkusFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
$skuList = array_diff($allSkus, $excludedSkus);
if (empty($skuList)) exit("ℹ️ Brak nowych SKU\n");

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
if (!$token) exit("❌ Błąd logowania\n");

// === PRZETWARZANIE PARTII ===
foreach (array_chunk($skuList, 20) as $skuChunk) {
    // Sprawdzenie limitu czasu PRZED rozpoczęciem nowej partii
    if ((time() - $startTime) > $maxExecutionTime) {
        logMsg("⏱️ Limit czasu osiągnięty – kończę przed kolejną partią", "warn");
        exit;
    }

    $skuFilter = implode('", "', $skuChunk);
    logMsg("Pobieram pakiet: [" . implode(", ", $skuChunk) . "]");

    $productQuery = <<<GQL
    {
      products(filter: { sku: { in: ["$skuFilter"] } }) {
        items {
          sku
          name
          stock_status
          description { html }
          categories { id name }
          ... on SimpleProduct {
            weight
            price_range {
              minimum_price {
                regular_price { value currency }
              }
            }
            image { url label }
            stock_availability { in_stock_real }
          }
        }
      }
    }
    GQL;

    $res = sendCurl($endpoint, 'POST', [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $token
    ], json_encode(['query' => $productQuery]));

    $data = json_decode($res['response'], true);
    $products = $data['data']['products']['items'] ?? [];
    $returnedSkus = array_map(fn($p) => $p['sku'], $products);
    $notFoundSkus = array_diff($skuChunk, $returnedSkus);
if (!empty($notFoundSkus)) {
    file_put_contents('sku_no_exist.txt', implode(PHP_EOL, $notFoundSkus) . PHP_EOL, FILE_APPEND);
    foreach ($notFoundSkus as $nfSku) {
        logMsg("❌ SKU nie znaleziony w hurtowni: $nfSku", "error");
    }
}

if (empty($products)) continue;

    foreach ($products as $product) {
        $sku = $product['sku'];
        $quantity = (int)($product['stock_availability']['in_stock_real'] ?? 0);
        $gross = (float)($product['price_range']['minimum_price']['regular_price']['value'] ?? 0);
        $imageUrl = $product['image']['url'] ?? '';

        logMsg("$sku | Stan: $quantity | Cena: $gross");

        $productId = prestaGetProductIdBySku($psApiUrl, $psApiKey, $sku);
        if ($productId) {
            logMsg("Produkt istnieje (ID: $productId)");
        } else {
            $xml = buildPrestaProductXML($product, $psLanguageId, $psRootCategoryId);
            $productId = prestaCreateProduct($psApiUrl, $psApiKey, $xml);
            if (!$productId) continue;
            logMsg("Dodano produkt (ID: $productId)", "success");
            file_put_contents(
                $existingSkusFile,
                $sku . PHP_EOL,
                FILE_APPEND | LOCK_EX
            );
        }

        if ($imageUrl && !str_contains($imageUrl, 'brak') && !str_contains($imageUrl, 'placeholder')) {
            if (!prestaProductHasImage($shopUrl, $psApiKey, $productId)) {
                prestaAddProductImage($shopUrl, $psApiKey, $productId, $imageUrl);
            }
        } else {
            logMsg("Brak lub placeholder", "warn");
        }

        prestaUpdateProductQuantity($shopUrl, $psApiKey, $productId, $quantity);
        prestaUpdateProductPrice($shopUrl, $psApiKey, $productId, $gross);
    }
}



// === FUNKCJE PRESTASHOP ===
function prestaGetProductIdBySku($apiUrl, $apiKey, $sku) {
    $res = sendCurl("$apiUrl?filter[reference]=$sku&ws_key=$apiKey", 'GET', ['Accept: application/xml']);
    $xml = simplexml_load_string($res['response']);
    return (int)($xml->products->product['id'] ?? 0);
}
function slugify($text) {
    $text = iconv('UTF-8', 'ASCII//TRANSLIT', $text);
    $text = strtolower($text);
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    return trim($text, '-');
}

function getCategoryIdByName($shopUrl, $apiKey, $name) {
    $slug = slugify($name);
    $url = "$shopUrl/api/categories?filter[link_rewrite]=$slug&ws_key=$apiKey";
    $res = sendCurl($url, 'GET', ['Accept: application/xml']);
    if ($res['code'] !== 200) return null;
    $xml = simplexml_load_string($res['response']);
    return (int)($xml->categories->category['id'] ?? 0);
}

function prestaCreateCategory($shopUrl, $apiKey, $name, $parentId = 2) {
    $xml = new SimpleXMLElement('<prestashop xmlns:xlink="http://www.w3.org/1999/xlink"></prestashop>');
    $cat = $xml->addChild('category');
    $cat->addChild('id_parent', $parentId);
    $cat->addChild('active', 1);
    $cat->addChild('description')->addChild('language', '')->addAttribute('id', 1);
    $cat->addChild('meta_title')->addChild('language', htmlspecialchars($name))->addAttribute('id', 1);
    $cat->addChild('meta_description')->addChild('language', '')->addAttribute('id', 1);
    $cat->addChild('meta_keywords')->addChild('language', '')->addAttribute('id', 1);
    $cat->addChild('name')->addChild('language', htmlspecialchars($name))->addAttribute('id', 1);
    $cat->addChild('link_rewrite')->addChild('language', slugify($name))->addAttribute('id', 1);

    $url = "$shopUrl/api/categories?ws_key=$apiKey";
    $res = sendCurl($url, 'POST', ['Content-Type: text/xml'], $xml->asXML());

    if ($res['code'] === 201) {
        $responseXml = simplexml_load_string($res['response']);
        $newId = (int)($responseXml->category->id ?? 0);
        logMsg("✅ Utworzono kategorię '$name' (ID: $newId)", "success");
        return $newId;
    } else {
        logMsg("❌ Nie udało się utworzyć kategorii '$name' (kod: {$res['code']})", "error");
        return null;
    }
}

function ensureCategoryTreeExists($shopUrl, $apiKey, $categories, $langId = 1, $rootId = 2) {
    $lastParentId = $rootId;
    $collectedCategoryIds = [];

    foreach ($categories as $category) {
        if ((int)$category['id'] === 5) continue; // pomiń "Grupa Główna"
        $name = $category['name'];

        $existingId = getCategoryIdByName($shopUrl, $apiKey, $name);
        if ($existingId) {
            logMsg("🔁 Kategoria '$name' już istnieje (ID: $existingId)");
            $currentId = $existingId;
        } else {
            $currentId = prestaCreateCategory($shopUrl, $apiKey, $name, $lastParentId);
        }

        if ($currentId) {
            $collectedCategoryIds[] = $currentId;
            $lastParentId = $currentId;
        }
    }

    return $collectedCategoryIds;
}

function buildPrestaProductXML($product, $langId, $defaultCategoryId) {
    global $shopUrl, $psApiKey;

    $xml = new SimpleXMLElement('<prestashop xmlns:xlink="http://www.w3.org/1999/xlink"></prestashop>');
    $prod = $xml->addChild('product');

    $prod->addChild('reference', $product['sku']);
    $gross = (float)($product['price_range']['minimum_price']['individual_price']['gross'] ?? 0);
    $price = number_format($gross / 1.23, 6, '.', '');
    $prod->addChild('price', $price);
    $prod->addChild('id_tax_rules_group', 1);
    $prod->addChild('active', 1);
    $prod->addChild('state', 1);
    $prod->addChild('available_for_order', 1);
    $prod->addChild('weight', number_format((float)($product['weight'] ?? 0.00), 6, '.', ''));
    $cleanName = sanitizePrestaText($product['name']);
    $prod->addChild('name')->addChild('language', htmlspecialchars($cleanName))->addAttribute('id', $langId);
    logMsg("Nazwa oryginalna: " . $product['name']);
    logMsg("Po oczyszczeniu: " . $cleanName);
    $shortDesc = $product['description']['html'] ?? '';
    if (stripos($shortDesc, 'Produkt chwilowo niedostępny?') === false) {
        $prod->addChild('description_short')->addChild('language', '')->addAttribute('id', $langId);
    } else {
        logMsg("⚠️ Opis pominięty – zawiera 'Produkt chwilowo niedostępny?'", "warn");
    }

    // === Kategorie ===
    $categories = $product['categories'] ?? [];
    $categoryIds = ensureCategoryTreeExists($shopUrl, $psApiKey, $categories, $langId, $defaultCategoryId);
    $defaultCatId = end($categoryIds) ?: $defaultCategoryId;
    $prod->addChild('id_category_default', $defaultCatId);

    $assoc = $prod->addChild('associations');
    $catAssoc = $assoc->addChild('categories');
    foreach ($categoryIds as $catId) {
        $catAssoc->addChild('category')->addChild('id', $catId);
    }

    return $xml->asXML();
}


function prestaCreateProduct($apiUrl, $apiKey, $xml) {
    $res = sendCurl("$apiUrl?ws_key=$apiKey", 'POST', ['Content-Type: text/xml'], $xml);
    if ($res['code'] === 201) {
        $data = simplexml_load_string($res['response']);
        return (int)($data->product->id ?? 0);
    }
    return null;
}

function prestaProductHasImage($shopUrl, $apiKey, $productId) {
    $res = sendCurl("$shopUrl/api/images/products/$productId?ws_key=$apiKey", 'GET', ['Accept: application/xml']);
    $xml = simplexml_load_string($res['response']);
    return isset($xml->image);
}
function sanitizePrestaText($text, $maxLength = 128) {
    // usuń niewidoczne/unicode-kontrolne
    $text = preg_replace('/[\x00-\x1F\x7F\xA0\xAD\x{200B}-\x{200F}\x{202A}-\x{202E}]/u', '', $text);
    // usuń znaki, które mogą powodować błędy walidacji
    $text = str_replace(['=', '"', "'", '`', '@', '#', '{', '}', '[', ']'], '', $text);
    // dopuszczalne: litery, cyfry, spacje i podstawowa interpunkcja
    $text = preg_replace('/[^\p{L}\p{N}\s.,:;()\-+]/u', '', $text);
    $text = trim($text);
    return mb_substr($text, 0, $maxLength);
}
function prestaAddProductImage($shopUrl, $apiKey, $productId, $imageUrl) {
    $imageData = @file_get_contents($imageUrl);
    if (!$imageData) return logMsg("Nie udało się pobrać zdjęcia", "error");

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
    } else {
        logMsg("Błąd dodania zdjęcia: {$res['code']}", "error");
    }
}
function prestaUpdateProductPrice($shopUrl, $apiKey, $productId, $grossPrice) {
    $productUrl = "$shopUrl/api/products/$productId?ws_key=$apiKey";

    // Pobierz XML produktu
    $res = sendCurl($productUrl, 'GET', ['Accept: application/xml']);
    if ($res['code'] !== 200) {
        logMsg("Błąd pobierania produktu do aktualizacji ceny (kod: {$res['code']})", "error");
        return false;
    }

    // Załaduj XML
    $productXml = simplexml_load_string($res['response']);
    if (!$productXml || !$productXml->product) {
        logMsg("Nieprawidłowy XML produktu przy aktualizacji ceny", "error");
        return false;
    }

    // Usuń pola tylko-do-odczytu (read-only), które powodują błąd 400
    unset($productXml->product->position_in_category);
    unset($productXml->product->manufacturer_name);
    unset($productXml->product->quantity);
    unset($productXml->product->new);
    unset($productXml->product->indexed);
    unset($productXml->product->id_default_combination);
    unset($productXml->product->associations);

    // Zmień cenę netto
    $vatRate = 0.23;
    $netPrice = number_format($grossPrice / (1 + $vatRate), 6, '.', '');
    $productXml->product->price = $netPrice;

    // Wyślij PUT z pełnym i poprawionym XML-em
    $res = sendCurl($productUrl, 'PUT', ['Content-Type: text/xml'], $productXml->asXML());

    if ($res['code'] === 200) {
        logMsg("✅ Zaktualizowano cenę netto: $netPrice", "success");
        return true;
    } else {
        logMsg("❌ Błąd aktualizacji ceny (kod: {$res['code']})", "error");
        if (!empty($res['error'])) logMsg("CURL error: {$res['error']}", "warn");
        return false;
    }
}

function prestaUpdateProductQuantity($shopUrl, $apiKey, $productId, $quantity) {
    $res = sendCurl("$shopUrl/api/stock_availables?filter[id_product]=$productId&filter[id_product_attribute]=0&ws_key=$apiKey", 'GET', ['Accept: application/xml']);
    $xml = simplexml_load_string($res['response']);
    $stockId = (int)($xml->stock_availables->stock_available['id'] ?? 0);
    if (!$stockId) return logMsg("Nie znaleziono stock_available", "error");

    $stockXml = simplexml_load_string(sendCurl("$shopUrl/api/stock_availables/$stockId?ws_key=$apiKey", 'GET', ['Accept: application/xml'])['response']);
    $stockXml->stock_available->quantity = $quantity;
    $stockXml->stock_available->depends_on_stock = 0;
    $stockXml->stock_available->out_of_stock = 2;

    $res = sendCurl("$shopUrl/api/stock_availables/$stockId?ws_key=$apiKey", 'PUT', ['Content-Type: text/xml'], $stockXml->asXML());
    if ($res['code'] === 200) {
        logMsg("Zaktualizowano ilość: $quantity", "success");
    } else {
        logMsg("Błąd aktualizacji ilości (kod: {$res['code']})", "error");
    }
}
?>

<?php
function logMsg($msg, $type = "info") {
    $prefix = [
        "info" => "ℹ️",
        "success" => "✅",
        "error" => "❌",
        "warn" => "⚠️",
    ][$type] ?? "🔸";

    $formatted = "[" . date("Y-m-d H:i:s") . "] $prefix $msg";
    echo "$formatted\n";
    file_put_contents(__DIR__ . "/../logs/import.log", "$formatted\n", FILE_APPEND);
}
function removeSkuFromFile($sku, $filename) {
    $lines = file($filename, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $lines = array_filter($lines, fn($line) => trim($line) !== trim($sku));
    file_put_contents($filename, implode(PHP_EOL, $lines));
}
function appendSkuToDoneFile($sku, $doneFile = __DIR__ . "/../files/txt/sku_done.txt") {
    file_put_contents($doneFile, trim($sku) . PHP_EOL, FILE_APPEND);
}
function sendCurl($url, $method = 'GET', $headers = [], $body = null, $retries = 3) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 15,            // maksymalny czas odpowiedzi
        CURLOPT_CONNECTTIMEOUT => 5,      // maksymalny czas na połączenie
    ]);
    if ($body) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);

    $response = curl_exec($ch);
    $info = curl_getinfo($ch);
    $error = curl_error($ch);
    $code = $info['http_code'];
    curl_close($ch);

    if (!$response && $retries > 0) {
        sleep(1); // odczekaj 1 sekundę
        return sendCurl($url, $method, $headers, $body, $retries - 1);
    }

    return ['response' => $response, 'code' => $code, 'error' => $error];
}

function importProduct(string $sku, string $token): void {
    global $endpoint, $psApiUrl, $psApiKey, $psLanguageId, $psRootCategoryId, $shopUrl;

    $productQuery = <<<GQL
    {
      products(filter: { sku: { eq: "$sku" } }) {
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
                individual_price { gross net currency }
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
    $product = $data['data']['products']['items'][0] ?? null;

    if (!$product) {
        throw new Exception("Nie znaleziono produktu o SKU: $sku");
    }

    $quantity = (int)($product['stock_availability']['in_stock_real'] ?? 0);
    $gross = $product['price_range']['minimum_price']['individual_price']['gross'] ?? 0;
    $imageUrl = $product['image']['url'] ?? '';

    $productId = prestaGetProductIdBySku($psApiUrl, $psApiKey, $sku);
    if ($productId) {
        logMsg("Produkt już istnieje (ID: $productId)", "info");
    } else {
        $xml = buildPrestaProductXML($product, $psLanguageId, $psRootCategoryId);
        $productId = prestaCreateProduct($psApiUrl, $psApiKey, $xml);
        if (!$productId) {
            throw new Exception("Nie udało się utworzyć produktu $sku");
        }
        logMsg("Utworzono produkt (ID: $productId)", "success");
    }
    if ($imageUrl && !str_contains($imageUrl, 'brak') && !str_contains($imageUrl, 'placeholder')) {
        prestaAddProductImage($shopUrl, $psApiKey, $productId, $imageUrl);
    } else {
        logMsg("Pominięto zdjęcie – brak lub placeholder", "warn");
    }
    prestaUpdateProductQuantity($shopUrl, $psApiKey, $productId, $quantity);
    prestaUpdateProductPrice($shopUrl, $psApiKey, $productId, $gross);
}

// === FUNKCJE PRESTASHOP ===
function prestaGetProductIdBySku($apiUrl, $apiKey, $sku) {
    $res = sendCurl("$apiUrl?filter[reference]=$sku&display=[id]&ws_key=$apiKey", 'GET', ['Accept: application/xml']);
    $xml = simplexml_load_string($res['response']);
    $products = $xml->products->product ?? [];

    if (count($products) === 0) return 0;

    if (is_array($products)) {
        return (int)$products[0]['id']; // pierwszy pasujący
    } else {
        return (int)$products['id']; // pojedynczy produkt
    }
}
function writeProductToCSV(array $product, string $filePath): void {
    $sku = $product['sku'] ?? '';
    $name = $product['name'] ?? '';
    $desc = strip_tags($product['description']['html'] ?? '');
    $qty = (int)($product['stock_availability']['in_stock_real'] ?? 0);
    $price = $product['price_range']['minimum_price']['individual_price']['gross'] ?? 0;
    $categories = array_column($product['categories'] ?? [], 'name');
    $category = implode(" > ", $categories);
    $imageUrl = $product['image']['url'] ?? '';

    $row = implode(";", [
        $sku,
        str_replace(";", ",", $name),
        str_replace([";", "\n", "\r"], [",", " ", ""], $desc),
        $qty,
        number_format($price, 2, '.', ''),
        str_replace(";", ",", $category),
        $imageUrl
    ]) . "\n";

    file_put_contents($filePath, $row, FILE_APPEND);
}


function fetchProductFromHurtownia(string $sku, string $token): ?array {
    global $endpoint;

    $query = <<<GQL
{
  products(filter: { sku: { eq: "$sku" } }) {
    items {
      sku
      name
      description { html }
      stock_availability { in_stock_real }
      price_range {
        minimum_price {
          individual_price { gross }
        }
      }
      categories { name }
      image { url }
    }
  }
}
GQL;

    $res = sendCurl($endpoint, 'POST', [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $token
    ], json_encode(['query' => $query]));

    $data = json_decode($res['response'], true);
    
    if (!isset($data['data']['products']['items'][0])) {
        logMsg("❌ Brak danych dla SKU: $sku", "warn");
        return null;
    }

    return $data['data']['products']['items'][0];
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
    $prod->addChild('name')->addChild('language', htmlspecialchars($product['name']))->addAttribute('id', $langId);

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

function buildMultiSkuQuery(array $skuList): string {
    $skuQuoted = array_map(fn($sku) => '"' . addslashes(trim($sku)) . '"', $skuList);
    $skuIn = implode(', ', $skuQuoted);

    return <<<GQL
{
  products(filter: { sku: { in: [$skuIn] } }) {
    items {
      sku
      name
      description { html }
      stock_availability { in_stock_real }
      price_range {
        minimum_price {
          individual_price { gross }
        }
      }
      categories { name }
      image { url }
    }
  }
}
GQL;
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
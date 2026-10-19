<?php
/** Strict, side-effect-free supplier CSV validation. */
function favers_cobra_parse_csv($stream) {
    $headers = fgetcsv($stream, 0, ',', '"', '');
    if (!$headers) {
        throw new RuntimeException('The CSV is empty.');
    }
    $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]);
    $headers = array_map('trim', $headers);
    $required = array('sku', 'name', 'price', 'stock', 'description');
    if (count($headers) !== count(array_unique($headers)) || array_diff($required, $headers)) {
        throw new RuntimeException('Required unique headers: sku,name,price,stock,description.');
    }
    if (array_diff($headers, $required)) {
        throw new RuntimeException('Unsupported columns. Use only sku,name,price,stock,description.');
    }
    $rows = array();
    $seen = array();
    $line = 1;
    while (($values = fgetcsv($stream, 0, ',', '"', '')) !== false) {
        ++$line;
        if ($values === array(null)) {
            continue;
        }
        if (count($values) !== count($headers)) {
            throw new RuntimeException('Wrong column count in record ' . $line . '.');
        }
        $row = array_map('trim', array_combine($headers, $values));
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,79}$/D', $row['sku'])) {
            throw new RuntimeException('Invalid SKU in record ' . $line . '. Use 1–80 letters, numbers, dots, underscores or hyphens.');
        }
        $key = strtolower($row['sku']);
        if (isset($seen[$key])) {
            throw new RuntimeException('Duplicate SKU in record ' . $line . '.');
        }
        $seen[$key] = true;
        if ($row['name'] === '' || strlen($row['name']) > 200 || strlen($row['description']) > 20000) {
            throw new RuntimeException('Invalid name or oversized description in record ' . $line . '.');
        }
        if (!preg_match('/^(0|[1-9][0-9]{0,6})(\.[0-9]{1,2})?$/D', $row['price']) || (float) $row['price'] <= 0) {
            throw new RuntimeException('Price must be positive, with no currency symbol and at most two decimals, in record ' . $line . '.');
        }
        if (!preg_match('/^(0|[1-9][0-9]{0,6})$/D', $row['stock'])) {
            throw new RuntimeException('Stock must be a nonnegative whole number in record ' . $line . '.');
        }
        $rows[] = $row;
        if (count($rows) > 200) {
            throw new RuntimeException('Import at most 200 products per file.');
        }
    }
    if (!$rows) {
        throw new RuntimeException('No products found.');
    }
    return $rows;
}

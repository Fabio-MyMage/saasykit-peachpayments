<?php

// PaymentProviderResource path that will be updated to ensure Peachpayments route is added
$filePath = __DIR__ . '/../../app/Filament/Admin/Resources/PaymentProviderResource.php';

if (!file_exists($filePath)) {
    exit("PaymentProviderResource.php not found\n");
}

$content = file_get_contents($filePath);

// Find the getPages() method.
$methodPos = strpos($content, 'public static function getPages()');
if ($methodPos === false) {
    exit("getPages() method not found in PaymentProviderResource.php\n");
}

// Within the method, find the "return [" statement.
$returnPos = strpos($content, 'return [', $methodPos);
if ($returnPos === false) {
    exit("return [ not found in getPages() method\n");
}

// Find the closing bracket of the return array. We assume the first occurrence of "];" after "return [" is the end.
$closingPos = strpos($content, "];", $returnPos);
if ($closingPos === false) {
    exit("Closing bracket for the getPages() array not found\n");
}

// Extract the block that contains the array items.
$pagesBlock = substr($content, $returnPos, $closingPos - $returnPos);
if (strpos($pagesBlock, "'peachpayments-settings'") !== false) {
    exit("Peachpayments settings route already exists in getPages() method\n");
}

// Define the new route line (ensure correct indentation).
$insertion = "    'peachpayments-settings' => Pages\\PeachpaymentsSettings::route('/peachpayments-settings'),\n        ";

// Insert the new line before the closing bracket.
$newContent = substr_replace($content, $insertion, $closingPos, 0);

// Save the updated file.
file_put_contents($filePath, $newContent);

echo "Peachpayments settings route added to getPages() method in PaymentProviderResource.php\n";
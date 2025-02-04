<?php

$filePath = __DIR__ . '/../../app/Providers/AppServiceProvider.php';

if (!file_exists($filePath)) {
    exit("AppServiceProvider.php not found\n");
}

$content = file_get_contents($filePath);

$useStatement = 'use MyMage\SaasykitPeachpayments\PaymentProviders\PeachpaymentsProvider;';
if (strpos($content, $useStatement) === false) {
    // Try to find the last use statement. If none, put after namespace.
    if (preg_match_all('/^use\s.+;/m', $content, $matches, PREG_OFFSET_CAPTURE)) {
        $lastUse = end($matches[0]);
        $insertPos = $lastUse[1] + strlen($lastUse[0]);
        $content = substr_replace($content, "\n" . $useStatement, $insertPos, 0);
    } elseif (preg_match('/^namespace\s.+;/m', $content, $match, PREG_OFFSET_CAPTURE)) {
        $insertPos = $match[0][1] + strlen($match[0][0]);
        $content = substr_replace($content, "\n\n" . $useStatement, $insertPos, 0);
    } else {
        // If no namespace, insert at the beginning.
        $content = $useStatement . "\n" . $content;
    }
}

// Check if the provider line already exists.
if (strpos($content, "PeachpaymentsProvider::class,") !== false) {
    exit("PeachpaymentsProvider is already registered in AppServiceProvider.php\n");
}

// Find the tag registration block for payment providers.
// Find the start of the tag block
$tagStart = strpos($content, '$this->app->tag([');
if ($tagStart === false) {
    exit("Could not locate the payment providers tag registration block\n");
}

// Find the closing bracket of the array for tag registration.
// We assume the first occurrence of "]," after the tag block start is the end
$tagEnd = strpos($content, "],", $tagStart);
if ($tagEnd === false) {
    exit("Could not locate the end of the payment providers tag registration block\n");
}

// Define the new provider line to insert.
$newLine = "    PeachpaymentsProvider::class,\n        ";

// Insert the new line right before the closing bracket of the tag array.
$newContent = substr_replace($content, $newLine, $tagEnd, 0);

// Save the updated file.
file_put_contents($filePath, $newContent);

echo "PeachpaymentsProvider has been added to AppServiceProvider.php\n";
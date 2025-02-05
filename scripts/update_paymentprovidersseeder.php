<?php

// PaymentProvidersSeeder path that will be updated to ensure Peach payments entry is added
$filePath = __DIR__ . '/../../database/seeders/PaymentProvidersSeeder.php';

if (!file_exists($filePath)) {
    exit("PaymentProvidersSeeder.php not found\n");
}

$content = file_get_contents($filePath);

// Check if the Peachpayments entry already exists
if (strpos($content, "'slug' => 'peachpayments'") !== false) {
    exit("Peachpayments entry already exists in PaymentProvidersSeeder.php\n");
}

// Define the pattern that marks the insertion point for the new entry
$insertionPattern = "        ], ['slug']);";
$insertPos = strpos($content, $insertionPattern);
if ($insertPos === false) {
    exit("Insertion point not found in PaymentProvidersSeeder.php\n");
}

// Define the Peachpayments entry using a heredoc for clarity
$peachpaymentsEntry = <<<EOF
            [
                'name' => 'Peachpayments',
                'slug' => 'peachpayments',
                'type' => 'multi',
                'created_at' => now()->format('Y-m-d H:i:s'),
                'updated_at' => now()->format('Y-m-d H:i:s'),
            ],

EOF;

// Ensure the inserted text starts on a new line
if ($insertPos > 0 && $content[$insertPos - 1] !== "\n") {
    $peachpaymentsEntry = "\n" . $peachpaymentsEntry;
}

// Insert the Peachpayments entry at the found position
$newContent = substr_replace($content, $peachpaymentsEntry, $insertPos, 0);
file_put_contents($filePath, $newContent);

echo "Peachpayments entry added to PaymentProvidersSeeder.php\n";
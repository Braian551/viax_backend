<?php
echo "File being executed: " . __FILE__ . "\n";
echo "File modified time: " . date('Y-m-d H:i:s', filemtime(__FILE__)) . "\n";
echo "File size: " . filesize(__FILE__) . " bytes\n";

// Show first 20 lines of the file
$lines = file(__FILE__);
echo "\nFirst 20 lines:\n";
for ($i = 0; $i < min(20, count($lines)); $i++) {
    echo ($i + 1) . ": " . $lines[$i];
}

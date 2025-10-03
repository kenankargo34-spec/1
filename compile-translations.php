<?php
// Script to compile .po files to .mo files
// This script should be run once to generate the .mo file for Turkish translations

if (!class_exists('PO')) {
    require_once(ABSPATH . 'wp-includes/pomo/po.php');
}

// Compile the Turkish .po file to .mo
$po = new PO();
$po->import_from_file('languages/seo-content-generator-tr_TR.po');
$po->export_to_mo_file('languages/seo-content-generator-tr_TR.mo');

echo "Turkish translation files compiled successfully.";
?>

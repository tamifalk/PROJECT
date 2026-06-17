<?php
// allocation/test_priority.php

require_once 'AllocationConfig.php';
require_once 'TaxAuthorityClient.php';
require_once 'AllocationRepository.php';
require_once 'AllocationService.php';

// 1. טעינת ההגדרות והקמת השירות
$config = new AllocationConfig(__DIR__ . '/config.example.php');
$client = new TaxAuthorityClient($config);
$repository = new AllocationRepository();
$service = new AllocationService($config, $client, $repository);

// 2. נתוני חשבונית דוגמה מתוך נספח המבחן
$invoice = [
    'ID'        => 987654,
    'SERIAL'    => '123456',
    'CLIENT_ID' => 33348,
    'TIME'      => 1779312000, // תאריך יוני 2026 (לפי שעון המערכת הנוכחי)
    'TOTAL'     => 11700.00,
    'VAT'       => 1700.00,
    'COMPANY_ID'=> 1,
    'NAME'      => 'חברת דוגמה בע"מ',
    'TID'       => '56858499'
];

echo "--- משימה 4: הדגמת פורמט שורת Priority ---" . PHP_EOL . PHP_EOL;

// תרחיש א': בניית שורה ללא מספר הקצאה
$lineWithoutAllocation = $service->buildPriorityLine($invoice, null);
echo "לפני (ללא הקצאה):" . PHP_EOL;
echo str_replace("\t", "\\t", $lineWithoutAllocation) . PHP_EOL . PHP_EOL;

// תרחיש ב': בניית שורה עם מספר הקצאה מלא שחוזר מה-API
$fullConfirmationNumber = "20240627231846297178091822";
$lineWithAllocation = $service->buildPriorityLine($invoice, $fullConfirmationNumber);

echo "אחרי (עם הקצאה, מערכת חותכת ל-9 ספרות ימניות):" . PHP_EOL;
echo str_replace("\t", "\\t", $lineWithAllocation) . PHP_EOL;
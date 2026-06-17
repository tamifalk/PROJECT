<?php
// allocation/AllocationService.php 

class AllocationResult {
    public bool $approved;
    public string $confirmationNumber;
    public ?string $errorMessage;
    public int $statusCode;

    public function __construct(bool $approved, string $confirmationNumber, ?string $errorMessage = null, int $statusCode = 200) {
        $this->approved = $approved;
        $this->confirmationNumber = $confirmationNumber;
        $this->errorMessage = $errorMessage;
        $this->statusCode = $statusCode;
    }
}

class AllocationService {
    private AllocationConfig $config;
    private TaxAuthorityClient $client;
    private AllocationRepository $repository;

    public function __construct(AllocationConfig $config, TaxAuthorityClient $client, AllocationRepository $repository) {
        $this->config = $config;
        $this->client = $client;
        $this->repository = $repository;
    }

    /**
     * משימה 2 - פונקציה 1: בדיקה האם נדרשת הקצאה לחשבונית 
     */
    public function needsAllocation(array $invoice, array $customer): bool {
        // אם המערכת כבויה בקונפיגורציה [cite: 61, 62]
        if (!$this->config->get('allocation.enabled')) { [cite: 61, 62]
            return false;
        }

        // אם מוגדר שכל החשבוניות צריכות לעבור הקצאה [cite: 62]
        if ($this->config->get('allocation.request_for_all_invoices')) { [cite: 62]
            return true;
        }

        // בדיקה 1: האם ללקוח יש ח.פ. / עוסק מורשה (לקוח עסקי B2B)
        $customerVat = $customer['TID'] ?? null; [cite: 11, 34]
        if (empty($customerVat) || strlen(trim($customerVat)) < 9) {
            return false;
        }

        // חילוץ תאריך וסכום לפני מע"מ מהחשבונית
        $invoiceDateStr = isset($invoice['TIME']) ? date('Y-m-d', $invoice['TIME']) : date('Y-m-d'); [cite: 33]
        $totalIncludingVat = (float)($invoice['TOTAL'] ?? 0.00); [cite: 33]
        $vatAmount = (float)($invoice['VAT'] ?? 0.00); [cite: 33, 34]
        $amountBeforeVat = $totalIncludingVat - $vatAmount;

        // בדיקה 2: קביעת סף הסכום הרלוונטי על פי תאריך המסמך 
        $thresholds = $this->config->get('thresholds', []); 
        $applicableThreshold = 10000; // ברירת מחדל ההתחלתית של שנת 2026 [cite: 14]

        // מיון מפתחות התאריכים כדי לוודא בדיקה כרונולוגית תקינה
        krsort($thresholds);
        foreach ($thresholds as $dateLimit => $limitAmount) {
            if ($invoiceDateStr >= $dateLimit) {
                $applicableThreshold = $limitAmount;
                break;
            }
        }

        return $amountBeforeVat >= $applicableThreshold;
    }

    /**
     * משימה 2 - פונקציה 2: ביצוע בקשת מספר הקצאה בפועל מה-API 
     */
    public function requestAllocation(array $invoice, array $customer): AllocationResult {
        // מניעת בקשה כפולה - בדיקה אם כבר קיימת הקצאה מאושרת ב-DB לחשבונית זו [cite: 77]
        $existing = $this->repository->getAllocation($invoice['ID']); 
        if ($existing && !empty($existing['confirmation_number'])) {
            return new AllocationResult(true, $existing['confirmation_number']);
        }

        // חישוב שדות הסכום
        $total = (float)$invoice['TOTAL']; 
        $vat = (float)$invoice['VAT']; 
        $amountBeforeVat = $total - $vat;
        $invoiceDateStr = isset($invoice['TIME']) ? date('Y-m-d', $invoice['TIME']) : date('Y-m-d'); 

        // בניית מבנה ה-JSON הנדרש על פי התיעוד [cite: 22, 23]
        $payload = [
            'invoice_id'                    => (string)$invoice['ID'], [cite: 23, 33]
            'invoice_type'                  => 3, // 3 = חשבונית מס [cite: 23]
            'vat_number'                    => $this->config->get('company.vat_number'), [cite: 23, 61]
            'invoice_reference_number'      => (string)$invoice['SERIAL'], [cite: 23, 33]
            'customer_vat_number'           => (string)$customer['TID'], [cite: 23, 34]
            'invoice_date'                  => $invoiceDateStr, [cite: 23]
            'invoice_issuance_date'         => date('Y-m-d'), [cite: 23]
            'accounting_software_number'    => $this->config->get('company.accounting_software_number'), [cite: 23, 61]
            'payment_amount'                => number_format($amountBeforeVat, 2, '.', ''), [cite: 23]
            'vat_amount'                    => number_format($vat, 2, '.', ''), [cite: 23]
            'payment_amount_including_vat'  => number_format($total, 2, '.', ''), [cite: 23]
        ];

        try {
            $apiResult = $this->client->sendApprovalRequest($payload);
            $status = $apiResult['status'];
            $body = $apiResult['body'];

            // טיפול בתשובה מוצלחת (סטטוס 200 ואישור חיובי) [cite: 25, 26]
            if ($status === 200 && isset($body['approved']) && $body['approved'] === true) { [cite: 26]
                $confirmationNumber = $body['confirmation_number']; [cite: 26]
                
                // שמירה בבסיס הנתונים [cite: 42]
                $this->repository->saveAllocation($invoice['ID'], $confirmationNumber, $body); [cite: 33]
                return new AllocationResult(true, $confirmationNumber, null, 200);
            }

            // טיפול בשגיאות לוגיות של רשות המסים (קודים 460, 461, 462 וכו') [cite: 28, 45]
            $errorMsg = $body['message'] ?? 'Invoice was rejected or delayed by tax authority.'; [cite: 26]
            return new AllocationResult(false, "0", $errorMsg, $status); [cite: 28]

        } catch (Exception $e) {
            $this->client->log("Critical error during allocation request: " . $e->getMessage(), 'CRITICAL'); [cite: 54]
            return new AllocationResult(false, "0", $e->getMessage(), 500); [cite: 28]
        }
    }

    /**
     * משימה 2 - פונקציה 3: גזירת 9 הספרות הימניות להצגה והדפסה 
     */
    public function formatForDisplay(string $confirmationNumber): string {
        if (empty($confirmationNumber) || $confirmationNumber === "0") {
            return "";
        }
        $digitsToDisplay = $this->config->get('allocation.display_digits', 9); [cite: 62]
        return substr($confirmationNumber, -$digitsToDisplay); [cite: 17]
    }

    /**
     * משימה 2 - פונקציה 4: בניית שורת Priority מעודכנת עם מספר הקצאה [cite: 50, 64]
     */
    public function buildPriorityLine(array $invoice, ?string $allocationNumber): string {
        // פירוק שורת ה-Priority הקיימת על פי תו הטאב (\t) [cite: 11]
        // כאן אנו מדגימים שימוש בנתוני הדוגמה שסופקו במבחן [cite: 67]
        $clientName = $invoice['NAME'] ?? 'שם לקוח'; [cite: 34, 67]
        $serial = $invoice['SERIAL'] ?? '123456'; [cite: 33, 67]
        $dateStr = isset($invoice['TIME']) ? date('d/m/y', $invoice['TIME']) : '01/06/26'; [cite: 33, 67]

        $fields = [
            "1",          // אינדקס 0
            "1",          // אינדקס 1
            "821148",     // אינדקס 2
            $clientName,  // אינדקס 3 [cite: 67]
            $dateStr,     // אינדקס 4 [cite: 67]
            "D",          // אינדקס 5 [cite: 67]
            $serial,      // אינדקס 6 [cite: 67]
            "",           // אינדקס 7 (שדה ריק מקורי) [cite: 67]
            "",           // אינדקס 8 -> מיקום שדה "אסמכתא 2" על פי הקונפיגורציה [cite: 62, 67]
            "0",          // אינדקס 9 [cite: 67]
            "3",          // אינדקס 10 [cite: 67]
            "Y",          // אינדקס 11 [cite: 67]
            ""            // סיומת
        ];

        // הזרקת מספר ההקצאה המעובד (9 ספרות) ישירות לתוך אינדקס אסמכתא 2 [cite: 68, 69]
        $targetIndex = $this->config->get('priority.asmachta2_field_index', 8); [cite: 62]
        if (!empty($allocationNumber)) {
            $fields[$targetIndex] = $this->formatForDisplay($allocationNumber);
        }

        // חיבור השדות מחדש עם תווי טאב [cite: 11, 68]
        return implode("\t", $fields);
    }
}
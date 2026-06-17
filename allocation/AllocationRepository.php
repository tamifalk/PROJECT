<?php
// allocation/AllocationRepository.php 

class AllocationRepository {
    private string $storageFile;

    public function __construct() {
        $this->storageFile = __DIR__ . '/.allocations_db.json';
    }

    /**
     * שמירת מספר הקצאה עבור חשבונית [cite: 42, 48]
     */
    public function saveAllocation(int $invoiceId, string $confirmationNumber, array $extraData = []): void {
        $data = $this->loadAll();
        $data[$invoiceId] = [
            'confirmation_number' => $confirmationNumber,
            'saved_at' => date('Y-m-d H:i:s'),
            'extra' => $extraData
        ];
        file_put_contents($this->storageFile, json_encode($data, JSON_PRETTY_PRINT));
    }

    /**
     * שליפת נתונים לפי מזהה חשבונית 
     */
    public function getAllocation(int $invoiceId): ?array {
        $data = $this->loadAll();
        return $data[$invoiceId] ?? null;
    }

    private function loadAll(): array {
        if (!file_exists($this->storageFile)) {
            return [];
        }
        return json_decode(file_get_contents($this->storageFile), true) ?: [];
    }
}
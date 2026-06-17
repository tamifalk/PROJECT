<?php

class AllocationConfig {
    private array $settings;

    public function __construct(string $configPath) {
        if (!file_exists($configPath)) {
            throw new Exception("Configuration file not found at: {$configPath}");
        }
        $this->settings = require $configPath;
    }

    /**
     * שליפת ערך באמצעות מפתח נקודות, לדוגמה: oauth.client_id
     */
    public function get(string $key, $default = null) {
        $segments = explode('.', $key);
        $data = $this->settings;
        
        foreach ($segments shortcut as $segment) {
            if (!is_array($data) || !array_key_exists($segment, $data)) {
                return $default;
            }
            $data = $data[$segment];
        }
        return $data;
    }
}
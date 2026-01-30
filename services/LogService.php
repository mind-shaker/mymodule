<?php

namespace modules\mymodule\services;

use Craft;

class LogService
{
    private string $logFilePath;

    public function __construct()
    {
        // Створюємо директорію для логів модуля
        $logsDir = Craft::$app->path->getStoragePath() . '/logs/mymodule';
        
        if (!is_dir($logsDir)) {
            mkdir($logsDir, 0755, true);
        }
        
        $this->logFilePath = $logsDir . '/matching-audit.json';
    }

    /**
     * Зберігає результати матчінгу в лог
     */
    public function saveMatchingLog(array $matched, array $unmatched, bool $dryRun): void
    {
        $logEntry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'type' => 'matching',
            'dryRun' => $dryRun,
            'matched' => $matched,
            'unmatched' => $unmatched,
            'stats' => [
                'matchedCount' => count($matched),
                'unmatchedCount' => count($unmatched)
            ]
        ];

        $this->writeLog($logEntry);
    }

    /**
     * Зберігає результати очищення в лог
     */
    public function saveCleanupLog(array $moved, bool $dryRun): void
    {
        $logEntry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'type' => 'cleanup',
            'dryRun' => $dryRun,
            'moved' => $moved,
            'stats' => [
                'movedCount' => count($moved)
            ]
        ];

        $this->writeLog($logEntry);
    }

    /**
     * Отримує останній лог
     */
    public function getLatestLog(): ?array
    {
        if (!file_exists($this->logFilePath)) {
            return null;
        }

        $content = file_get_contents($this->logFilePath);
        $logs = json_decode($content, true);

        if (empty($logs)) {
            return null;
        }

        return end($logs);
    }

    /**
     * Отримує всі логи
     */
    public function getAllLogs(): array
    {
        if (!file_exists($this->logFilePath)) {
            return [];
        }

        $content = file_get_contents($this->logFilePath);
        return json_decode($content, true) ?: [];
    }

    /**
     * Очищає всі логи
     */
    public function clearLogs(): bool
    {
        if (file_exists($this->logFilePath)) {
            return unlink($this->logFilePath);
        }
        return true;
    }

    /**
     * Записує лог в файл
     */
    private function writeLog(array $logEntry): void
    {
        $logs = $this->getAllLogs();
        $logs[] = $logEntry;

        // Зберігаємо тільки останні 50 записів
        if (count($logs) > 50) {
            $logs = array_slice($logs, -50);
        }

        file_put_contents($this->logFilePath, json_encode($logs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}

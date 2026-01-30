<?php

namespace modules\mymodule\services;

use Craft;
use craft\elements\Asset;

class ThumbnailCleanupService
{
    /**
     * Нормалізує ім'я файлу для порівняння
     * 1. Видаляє розширення
     * 2. Переводить в нижній регістр
     * 3. Заміняє пробіли на дефіси
     * 4. Заміняє підкреслювання на дефіси
     */
    private function normalizeFilename(string $filename): string
    {
        // Видаляємо розширення
        $normalized = pathinfo($filename, PATHINFO_FILENAME);
        
        // Переводимо в нижній регістр
        $normalized = mb_strtolower($normalized);
        
        // Заміняємо пробіли на дефіси
        $normalized = str_replace(' ', '-', $normalized);
        
        // Заміняємо підкреслювання на дефіси
        $normalized = str_replace('_', '-', $normalized);
        
        return $normalized;
    }

    public function cleanUnusedThumbnails(bool $dryRun = true): array
    {
        Craft::info('CleanupService started. DryRun=' . ($dryRun ? 'true' : 'false'), __METHOD__);
        
        $moved = [];

        // Отримуємо всі мініатюри
        $thumbnails = Asset::find()
            ->volume('thumbnailsFs')
            ->all();

        Craft::info('Thumbnails found: ' . count($thumbnails), __METHOD__);

        // Отримуємо backup volume
        $backupVolume = Craft::$app->volumes->getVolumeByHandle('thumbnailsBackup');
        
        if (!$backupVolume) {
            Craft::error('Backup volume "thumbnailsBackup" not found!', __METHOD__);
            return [];
        }

        // Отримуємо root folder backup volume
        $backupFolder = Craft::$app->assets->getRootFolderByVolumeId($backupVolume->id);
        
        if (!$backupFolder) {
            Craft::error('Backup folder not found!', __METHOD__);
            return [];
        }

        // Генеруємо дату для всіх файлів цієї операції
        $currentDate = date('Y-m-d-His'); // Формат: 2025-01-29-143022

        foreach ($thumbnails as $thumb) {
            $normalizedThumbName = $this->normalizeFilename($thumb->filename);
            
            Craft::info('Processing thumbnail: original="' . $thumb->filename . '" normalized="' . $normalizedThumbName . '" (ID: ' . $thumb->id . ')', __METHOD__);
            
            // Перевіряємо чи є asset у будь-яких relations
            $isUsed = $this->isAssetUsedAnywhere($thumb);
            
            if (!$isUsed) {
                Craft::info('Unused thumbnail: ' . $thumb->filename . ' (ID: ' . $thumb->id . ')', __METHOD__);
                $moved[] = $thumb->filename;

                if (!$dryRun) {
                    try {
                        // ВИПРАВЛЕННЯ: Створюємо нове ім'я з датою та суфіксом "unused"
                        $fileExtension = pathinfo($thumb->filename, PATHINFO_EXTENSION);
                        $baseFilename = pathinfo($thumb->filename, PATHINFO_FILENAME);
                        $newFilename = $baseFilename . '_unused_' . $currentDate . '.' . $fileExtension;
                        
                        Craft::info('Moving unused thumbnail to backup as: ' . $newFilename, __METHOD__);
                        
                        // Перейменовуємо і переміщуємо в backup
                        $thumb->newFilename = $newFilename;
                        $thumb->newFolderId = $backupFolder->id;
                        
                        if (Craft::$app->elements->saveElement($thumb)) {
                            Craft::info('Successfully moved to backup: ' . $newFilename, __METHOD__);
                        } else {
                            Craft::error('Failed to move thumbnail: ' . $thumb->filename . ' Errors: ' . json_encode($thumb->getErrors()), __METHOD__);
                        }
                    } catch (\Throwable $e) {
                        Craft::error('Exception moving thumbnail ' . $thumb->filename . ': ' . $e->getMessage(), __METHOD__);
                    }
                }
            } else {
                Craft::info('Thumbnail is used: ' . $thumb->filename . ' (ID: ' . $thumb->id . ')', __METHOD__);
            }
        }

        Craft::info('CleanupService finished. Moved=' . count($moved), __METHOD__);

        return $moved;
    }

    /**
     * Перевіряє чи asset використовується в будь-яких relations
     */
    private function isAssetUsedAnywhere(Asset $asset): bool
    {
        // Перевірка через Relations таблицю
        $relations = (new \craft\db\Query())
            ->select(['sourceId', 'sourceSiteId'])
            ->from(['{{%relations}}'])
            ->where(['targetId' => $asset->id])
            ->all();

        if (!empty($relations)) {
            Craft::info('Asset ' . $asset->id . ' has ' . count($relations) . ' relations', __METHOD__);
            return true;
        }

        return false;
    }
}
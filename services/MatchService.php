<?php

namespace modules\mymodule\services;

use Craft;
use craft\elements\Asset;

class MatchService
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

    public function matchAll(bool $dryRun = true): array
    {
        Craft::info('MatchService started. DryRun=' . ($dryRun ? 'true' : 'false'), __METHOD__);

        $matched = [];
        $unmatched = [];

        $videos = Asset::find()
            ->volume('videos')
            ->kind('video')
            ->all();

        Craft::info('Videos found: ' . count($videos), __METHOD__);

        // Беремо мініатюри з Inbox
        $thumbnails = Asset::find()
            ->volume('thumbnailsInbox')
            ->all();

        Craft::info('Thumbnails in Inbox found: ' . count($thumbnails), __METHOD__);

        // Отримуємо цільовий volume (thumbnailsFs)
        $targetVolume = Craft::$app->volumes->getVolumeByHandle('thumbnailsFs');
        
        if (!$targetVolume) {
            Craft::error('Target volume "thumbnailsFs" not found!', __METHOD__);
            return [
                'matched' => [],
                'unmatched' => array_map(fn($v) => $v->filename, $videos)
            ];
        }

        $targetFolder = Craft::$app->assets->getRootFolderByVolumeId($targetVolume->id);
        
        if (!$targetFolder) {
            Craft::error('Target folder not found!', __METHOD__);
            return [
                'matched' => [],
                'unmatched' => array_map(fn($v) => $v->filename, $videos)
            ];
        }

        // Отримуємо backup volume
        $backupVolume = Craft::$app->volumes->getVolumeByHandle('thumbnailsBackup');
        
        if (!$backupVolume) {
            Craft::error('Backup volume "thumbnailsBackup" not found!', __METHOD__);
            return [
                'matched' => [],
                'unmatched' => array_map(fn($v) => $v->filename, $videos)
            ];
        }

        $backupFolder = Craft::$app->assets->getRootFolderByVolumeId($backupVolume->id);
        
        if (!$backupFolder) {
            Craft::error('Backup folder not found!', __METHOD__);
            return [
                'matched' => [],
                'unmatched' => array_map(fn($v) => $v->filename, $videos)
            ];
        }

        // Створюємо індекс мініатюр з нормалізованими іменами
        $thumbIndex = [];
        foreach ($thumbnails as $thumb) {
            $normalizedThumbName = $this->normalizeFilename($thumb->filename);
            $thumbIndex[$normalizedThumbName] = $thumb;
            Craft::info('Indexed thumbnail: original="' . $thumb->filename . '" normalized="' . $normalizedThumbName . '"', __METHOD__);
        }

        foreach ($videos as $i => $video) {
            $videoName = $video->filename;
            
            // Нормалізуємо ім'я відео для порівняння
            $normalizedVideoName = $this->normalizeFilename($videoName);
            
            $found = [];

            Craft::info('Processing video #' . ($i+1) . ': original="' . $videoName . '" normalized="' . $normalizedVideoName . '"', __METHOD__);

            // Порівнюємо нормалізовані імена - ТІЛЬКИ ТОЧНЕ СПІВПАДІННЯ
            foreach ($thumbIndex as $normalizedThumbName => $thumb) {
                Craft::info("Comparing normalized: video='$normalizedVideoName' with thumbnail='$normalizedThumbName'", __METHOD__);
                
                // ВИПРАВЛЕННЯ: Замінено str_contains() на === для точного співпадіння
                if ($normalizedThumbName === $normalizedVideoName) {
                    $found[] = $thumb;
                    Craft::info('MATCH FOUND: video="' . $normalizedVideoName . '" thumbnail="' . $normalizedThumbName . '" (original: "' . $thumb->filename . '")', __METHOD__);
                }
            }

            if (count($found) === 1) {
                Craft::info('FINAL MATCH (single): video="' . $videoName . '" -> thumbnail="' . $found[0]->filename . '"', __METHOD__);

                if (!$dryRun) {
                    try {
                        $newThumbnail = $found[0];
                        $currentDate = date('Y-m-d-His');
                        
                        // КРОК 0A: Виштовхуємо ПОТОЧНИЙ прив'язаний thumbnail (якщо є)
                        $currentThumbnails = $video->getFieldValue('thumbnail');
                        
                        if (!empty($currentThumbnails)) {
                            foreach ($currentThumbnails as $currentThumb) {
                                if ($currentThumb && isset($currentThumb->id)) {
                                    Craft::info('Found currently assigned thumbnail: ' . $currentThumb->filename . ' (ID: ' . $currentThumb->id . ')', __METHOD__);
                                    
                                    // Створюємо нове ім'я з датою для прив'язаного файлу
                                    $fileExtension = pathinfo($currentThumb->filename, PATHINFO_EXTENSION);
                                    $baseFilename = pathinfo($currentThumb->filename, PATHINFO_FILENAME);
                                    $newFilenameAssigned = $baseFilename . '_expired_' . $currentDate . '.' . $fileExtension;
                                    
                                    Craft::info('Moving currently assigned thumbnail to backup as: ' . $newFilenameAssigned, __METHOD__);
                                    
                                    // Перейменовуємо і переміщуємо прив'язаний thumbnail в backup
                                    $currentThumb->newFilename = $newFilenameAssigned;
                                    $currentThumb->newFolderId = $backupFolder->id;
                                    
                                    if (!Craft::$app->elements->saveElement($currentThumb)) {
                                        Craft::error('FAILED to move currently assigned thumbnail to backup: ' . $currentThumb->filename . ' - Errors: ' . json_encode($currentThumb->getErrors()), __METHOD__);
                                        $unmatched[] = $videoName . ' (failed to backup current thumbnail)';
                                        continue 2;
                                    }
                                    
                                    Craft::info('Successfully moved currently assigned thumbnail to backup as: ' . $newFilenameAssigned, __METHOD__);
                                }
                            }
                        } else {
                            Craft::info('No thumbnail currently assigned to video: ' . $videoName, __METHOD__);
                        }
                        
                        // КРОК 0B: Перевіряємо чи існує файл-дублікат з таким же ім'ям у thumbnailsFs
                        // ВИКОРИСТОВУЄМО НОРМАЛІЗАЦІЮ ДЛЯ ПОШУКУ ДУБЛІКАТІВ З ТОЧНИМ СПІВПАДІННЯМ
                        $normalizedNewThumbName = $this->normalizeFilename($newThumbnail->filename);
                        
                        $existingThumbnails = Asset::find()
                            ->volume('thumbnailsFs')
                            ->all();
                        
                        $existingDuplicate = null;
                        foreach ($existingThumbnails as $existingThumb) {
                            $normalizedExistingName = $this->normalizeFilename($existingThumb->filename);
                            
                            // ВИПРАВЛЕННЯ: Використовуємо === для точного співпадіння імен
                            if ($normalizedExistingName === $normalizedNewThumbName) {
                                $existingDuplicate = $existingThumb;
                                Craft::info('Duplicate detected: existing="' . $existingThumb->filename . '" (normalized="' . $normalizedExistingName . '") matches new="' . $newThumbnail->filename . '" (normalized="' . $normalizedNewThumbName . '")', __METHOD__);
                                break;
                            }
                        }
                        
                        if ($existingDuplicate) {
                            Craft::info('Found duplicate file in thumbnailsFs: ' . $existingDuplicate->filename . ' (ID: ' . $existingDuplicate->id . ') normalized="' . $this->normalizeFilename($existingDuplicate->filename) . '"', __METHOD__);
                            
                            // Створюємо нове ім'я з датою для дублікату
                            $fileExtension = pathinfo($existingDuplicate->filename, PATHINFO_EXTENSION);
                            $baseFilename = pathinfo($existingDuplicate->filename, PATHINFO_FILENAME);
                            $newFilenameDuplicate = $baseFilename . '_duplicate_' . $currentDate . '.' . $fileExtension;
                            
                            Craft::info('Moving duplicate file to backup as: ' . $newFilenameDuplicate, __METHOD__);
                            
                            // Перейменовуємо і переміщуємо дублікат в backup
                            $existingDuplicate->newFilename = $newFilenameDuplicate;
                            $existingDuplicate->newFolderId = $backupFolder->id;
                            
                            if (!Craft::$app->elements->saveElement($existingDuplicate)) {
                                Craft::error('FAILED to move duplicate to backup: ' . $existingDuplicate->filename . ' - Errors: ' . json_encode($existingDuplicate->getErrors()), __METHOD__);
                                $unmatched[] = $videoName . ' (failed to backup duplicate)';
                                continue;
                            }
                            
                            Craft::info('Successfully moved duplicate to backup as: ' . $newFilenameDuplicate, __METHOD__);
                        } else {
                            Craft::info('No duplicate file found in thumbnailsFs for: ' . $newThumbnail->filename, __METHOD__);
                        }
                        
                        // КРОК 1: Переміщуємо новий thumbnail з Inbox в thumbnailsFs
                        Craft::info('Moving thumbnail from Inbox to thumbnailsFs: ' . $newThumbnail->filename, __METHOD__);
                        
                        $newThumbnail->newFolderId = $targetFolder->id;
                        
                        if (!Craft::$app->elements->saveElement($newThumbnail)) {
                            Craft::error('FAILED TO MOVE thumbnail "' . $newThumbnail->filename . '" - Errors: ' . json_encode($newThumbnail->getErrors()), __METHOD__);
                            $unmatched[] = $videoName . ' (failed to move thumbnail)';
                            continue;
                        }
                        
                        Craft::info('Successfully moved thumbnail: ' . $newThumbnail->filename, __METHOD__);
                        
                        // КРОК 2: Присвоюємо новий thumbnail до відео
                        Craft::info('Assigning thumbnail to video: ' . $videoName, __METHOD__);
                        
                        $video->setFieldValue('thumbnail', [$newThumbnail->id]);
                        
                        if (Craft::$app->elements->saveElement($video)) {
                            Craft::info('Thumbnail ASSIGNED: video="' . $videoName . '" thumbnailId=' . $newThumbnail->id, __METHOD__);
                        } else {
                            Craft::error('FAILED TO ASSIGN thumbnail to video "' . $videoName . '" - Errors: ' . json_encode($video->getErrors()), __METHOD__);
                            $unmatched[] = $videoName . ' (failed to assign thumbnail)';
                            continue;
                        }

                    } catch (\Throwable $e) {
                        Craft::error('Exception processing video "' . $videoName . '": ' . $e->getMessage(), __METHOD__);
                        $unmatched[] = $videoName . ' (exception: ' . $e->getMessage() . ')';
                        continue;
                    }
                }

                $matched[] = [
                    'video' => $videoName,
                    'thumbnail' => $found[0]->filename
                ];
                
            } elseif (count($found) > 1) {
                Craft::warning('MULTIPLE MATCHES: video="' . $videoName . '" thumbnails=' .
                    implode(', ', array_map(fn($t) => $t->filename, $found)), __METHOD__);

                $unmatched[] = $videoName . ' (duplicate thumbnails: ' .
                    implode(', ', array_map(fn($t)=>$t->filename, $found)) . ')';
            } else {
                Craft::info('NO MATCH: video="' . $videoName . '"', __METHOD__);
                $unmatched[] = $videoName;
            }
        }

        Craft::info('MatchService finished. Matched=' . count($matched) . ' Unmatched=' . count($unmatched), __METHOD__);

        return [
            'matched' => $matched,
            'unmatched' => $unmatched
        ];
    }
}
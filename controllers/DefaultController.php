<?php

namespace modules\mymodule\controllers;

use Craft;
use craft\web\Controller;
use modules\mymodule\services\MatchService;
use modules\mymodule\services\ThumbnailCleanupService;
use modules\mymodule\services\LogService;
use yii\web\Response;
use Throwable;

class DefaultController extends Controller
{
    protected array|bool|int $allowAnonymous = false;

    public function actionIndex()
    {
        Craft::info('Action index called', __METHOD__);

        $videos = \craft\elements\Asset::find()
            ->volume('videos')
            ->kind('video')
            ->all();

        Craft::info('Found ' . count($videos) . ' videos', __METHOD__);

        // Отримуємо останній лог
        $logService = new LogService();
        $latestLog = $logService->getLatestLog();

        return $this->renderTemplate('mymodule/index', [
            'videos' => $videos,
            'latestLog' => $latestLog
        ]);
    }

    public function actionMatchThumbnails(): Response
    {
        Craft::info('Action match-thumbnails called', __METHOD__);

        $this->requirePostRequest();

        $dryRunParam = \Craft::$app->request->getBodyParam('dryRun', true);
        $dryRun = filter_var($dryRunParam, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;

        Craft::info('Match thumbnails - dryRun: ' . ($dryRun ? 'true' : 'false'), __METHOD__);

        try {
            $service = new MatchService();
            $result = $service->matchAll($dryRun);

            // Зберігаємо лог
            $logService = new LogService();
            $logService->saveMatchingLog(
                $result['matched'],
                $result['unmatched'],
                $dryRun
            );

            Craft::info('matchAll finished: ' . count($result['matched']) . ' matched, ' . count($result['unmatched']) . ' unmatched', __METHOD__);

            return $this->asJson([
                'success' => true,
                'dryRun' => $dryRun,
                'matched' => $result['matched'],
                'unmatched' => $result['unmatched'],
            ]);

        } catch (Throwable $e) {
            Craft::error('Exception in match-thumbnails: ' . $e->__toString(), __METHOD__);
            return $this->asJson([
                'success' => false,
                'error' => 'Unhandled exception',
                'message' => $e->getMessage()
            ])->setStatusCode(500);
        }
    }

    public function actionCleanThumbnails(): Response
    {
        $this->requirePostRequest();

        $dryRunParam = \Craft::$app->request->getBodyParam('dryRun', true);
        $dryRun = filter_var($dryRunParam, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;

        Craft::info('Clean thumbnails - dryRun: ' . ($dryRun ? 'true' : 'false'), __METHOD__);

        try {
            $service = new ThumbnailCleanupService();
            $result = $service->cleanUnusedThumbnails($dryRun);

            // Зберігаємо лог
            $logService = new LogService();
            $logService->saveCleanupLog($result, $dryRun);

            return $this->asJson([
                'success' => true,
                'dryRun' => $dryRun,
                'moved' => $result
            ]);

        } catch (\Throwable $e) {
            Craft::error('Exception in clean-thumbnails: ' . $e->__toString(), __METHOD__);
            return $this->asJson([
                'success' => false,
                'error' => 'Unhandled exception',
                'message' => $e->getMessage()
            ])->setStatusCode(500);
        }
    }

    public function actionClearLogs(): Response
    {
        $this->requirePostRequest();

        try {
            $logService = new LogService();
            $logService->clearLogs();

            return $this->asJson([
                'success' => true
            ]);

        } catch (\Throwable $e) {
            Craft::error('Exception in clear-logs: ' . $e->__toString(), __METHOD__);
            return $this->asJson([
                'success' => false,
                'error' => 'Failed to clear logs'
            ])->setStatusCode(500);
        }
    }
}

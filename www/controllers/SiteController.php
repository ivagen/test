<?php

declare(strict_types=1);

namespace app\controllers;

use yii\web\Controller;

/**
 * Serves the single page and the HTML error view.
 *
 * All item behaviour moved to {@see ApiItemController}. Per FR-015 and the T034 decision
 * recorded in README.md, the legacy `index.php?r=site/get|create|update|delete` routes are
 * REMOVED rather than kept as an adapter: they carried no business logic of their own, had
 * no external consumer, and keeping CSRF-exempt mutation endpoints alive purely for
 * nostalgia would undermine FR-010.
 */
final class SiteController extends Controller
{
    /**
     * @return array<string, array<string, string>>
     */
    public function actions(): array
    {
        return [
            'error' => [
                'class' => \yii\web\ErrorAction::class,
            ],
        ];
    }

    public function actionIndex(): string
    {
        return $this->render('index');
    }
}

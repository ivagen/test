<?php

declare(strict_types=1);

/**
 * @var yii\web\View $this
 * @var string       $content
 */

use app\components\ViteAssets;
use yii\helpers\Html;

$assets = ViteAssets::forApplication();
?>
<?php $this->beginPage(); ?>
<!DOCTYPE html>
<html lang="<?= Yii::$app->language ?>">
<head>
    <meta charset="<?= Yii::$app->charset ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php
        /*
         * The CSRF token reaches the browser through this meta tag rather than an inline
         * <script> assignment. That is what lets nginx send a strict
         * `script-src 'self'` Content-Security-Policy with no 'unsafe-inline'.
         */
        echo Html::csrfMetaTags();
    ?>
    <title><?= Html::encode($this->title) ?></title>
    <?php foreach ($assets->styles() as $href): ?>
        <link rel="stylesheet" href="<?= Html::encode($href) ?>">
    <?php endforeach; ?>
    <?php foreach ($assets->scripts() as $src): ?>
        <script type="module" src="<?= Html::encode($src) ?>" defer></script>
    <?php endforeach; ?>
    <?php $this->head(); ?>
</head>
<body>
<?php $this->beginBody(); ?>

<?php if (!$assets->isBuilt()): ?>
    <p role="alert">
        The browser bundle has not been built yet. Run
        <code>docker compose exec frontend npm run build</code> and reload this page.
    </p>
<?php endif; ?>

<?= $content ?>

<?php $this->endBody(); ?>
</body>
</html>
<?php $this->endPage(); ?>

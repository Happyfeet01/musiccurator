<?php

declare(strict_types=1);

use OCA\MusicCurator\AppInfo\Application;
use OCP\Util;

Util::addScript(Application::APP_ID, Application::APP_ID . '-main');
Util::addStyle(Application::APP_ID, Application::APP_ID . '-main');

$de = str_starts_with($l->getLanguageCode(), 'de');
?>

<div id="musiccurator"></div>

<template id="musiccurator-support-template"
    data-url="<?php p(\OCP\Server::get(\OCP\IURLGenerator::class)->linkToRoute('musiccurator.supportNotice.dismiss')); ?>"
    data-token="<?php p($_['requesttoken']); ?>">
    <aside id="musiccurator-support-notice" class="developer-support-notice" hidden aria-labelledby="musiccurator-support-title">
        <strong id="musiccurator-support-title"><?php p($de ? 'Unterstützung des Entwicklers' : 'Support the developer'); ?></strong>
        <p><?php p($de ? 'Dir gefällt die App? Ich freue mich über einen Kaffee.' : 'Enjoying the app? You can buy me a coffee.'); ?></p>
        <p class="support-links">
            <a href="https://buymeacoffee.com/sly5ir9ov" target="_blank" rel="noopener noreferrer">Buy Me a Coffee</a>
            <a href="https://paypal.me/LarsM1980" target="_blank" rel="noopener noreferrer">PayPal</a>
            <a href="https://ko-fi.com/dermueller" target="_blank" rel="noopener noreferrer">Ko-fi</a>
        </p>
        <button type="button" data-duration="session"><?php p($de ? 'Schließen' : 'Close'); ?></button>
        <button type="button" data-duration="permanent"><?php p($de ? 'Nicht wieder anzeigen' : 'Don’t show again'); ?></button>
        <p role="status" hidden><?php p($de ? 'Speichern fehlgeschlagen. Bitte erneut versuchen.' : 'Could not save. Please try again.'); ?></p>
    </aside>
</template>

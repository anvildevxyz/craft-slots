<?php

namespace anvildev\slots\assetbundles;

use craft\web\AssetBundle;

/**
 * Front-end behaviour for the self-service booking management page.
 *
 * Kept out of the wizard bundle deliberately: this page is server-rendered and
 * needs none of the wizard's state machine, so the emailed manage link pulls a
 * few kB rather than the whole booking flow.
 */
class SlotsManagePageAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = '@anvildev/slots/web';
        $this->js = [
            'js/frontend/manage-page.js',
        ];
        parent::init();
    }
}

<?php

namespace anvildev\slots\assetbundles;

use craft\web\AssetBundle;
use craft\web\View;

/**
 * Front-end booking wizard assets — the zero-dependency vanilla bundle.
 *
 * Ships the framework-free `slots-wizard.umd.js` (headless core + renderer) and
 * the wizard stylesheet. Unlike {@see SlotsAsset}, it loads no
 * legacy wizard scripts, so the page runs under a strict CSP (no `unsafe-eval`).
 * The bundle exposes the global `SlotsWizard`, deferred so the DOM is parsed
 * before the init script in the template runs.
 */
class SlotsWizardAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = '@anvildev/slots/web';
        $this->js = [
            ['js/slots-wizard.umd.js', 'defer' => true],
        ];
        $this->css = ['css/slots-wizard.css'];
        $this->jsOptions['position'] = View::POS_END;
        parent::init();
    }
}

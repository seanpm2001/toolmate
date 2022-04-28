<?php

namespace vaersaagod\toolmate;

use Craft;
use craft\base\Plugin;
use craft\events\TemplateEvent;
use craft\helpers\App;
use craft\web\Application;
use craft\web\twig\variables\CraftVariable;

use craft\web\View;
use vaersaagod\toolmate\models\Settings;
use vaersaagod\toolmate\services\CspService;
use vaersaagod\toolmate\services\EmbedService;
use vaersaagod\toolmate\services\MinifyService;
use vaersaagod\toolmate\services\ToolService;
use vaersaagod\toolmate\twigextensions\CspTwigExtension;
use vaersaagod\toolmate\twigextensions\ToolMateTwigExtension;
use vaersaagod\toolmate\variables\ToolMateVariable;

use yii\base\Event;
use yii\log\FileTarget;

/**
 * @author    Værsågod
 * @package   PluginMate
 * @since     1.0.0
 *
 * @property CspService $csp
 * @property EmbedService $embed
 * @property ToolService $tool
 * @property Settings $settings
 * @method Settings getSettings()
 */
class ToolMate extends Plugin
{
    // Static Properties
    // =========================================================================

    /**
     * @var ToolMate
     */
    public static ToolMate $plugin;

    // Public Methods
    // =========================================================================

    public function init(): void
    {
        parent::init();

        self::$plugin = $this;

        // Register services
        $this->setComponents([
            'csp' => CspService::class,
            'embed' => EmbedService::class,
            'tool' => ToolService::class,
            'minify' => MinifyService::class,
        ]);

        // Register template variables
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            static function(Event $event) {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('toolmate', ToolMateVariable::class);
                $variable->set('tool', ToolMateVariable::class);
            }
        );

        // Add in our Twig extensions
        Craft::$app->view->registerTwigExtension(new ToolMateTwigExtension());
        Craft::$app->view->registerTwigExtension(new CspTwigExtension());

        // Lets use our own log file
        Craft::getLogger()->dispatcher->targets[] = new FileTarget([
            'logFile' => '@storage/logs/toolmate.log',
            'categories' => ['vaersaagod\toolmate\*'],
        ]);

        /**
         * Maybe send a Content-Security-Policy header
         */
        $this->maybeSendCspHeader();
    }

    // Protected Methods
    // =========================================================================

    /**
     * @return Settings
     */
    protected function createSettingsModel(): Settings
    {
        return new Settings();
    }

    protected function maybeSendCspHeader(): void
    {
        $cspConfig = self::getInstance()?->getSettings()->csp;

        if (!$cspConfig->enabled) {
            return;
        }

        if (!$cspConfig->enabledForCp && Craft::$app->getRequest()->getIsCpRequest()) {
            return;
        }

        // Replace hashed CSP nonces (this gets us around the template cache)
        Event::on(
            View::class,
            View::EVENT_AFTER_RENDER_PAGE_TEMPLATE,
            static function(TemplateEvent $event) {
                \preg_match_all('/nonce="([^"]*)"/', $event->output, $matches);
                $hashedNonces = $matches[1] ?? [];
                for ($i = 0, $iMax = \count($hashedNonces); $i < $iMax; ++$i) {
                    if (!$unhashedNonce = Craft::$app->getSecurity()->validateData($hashedNonces[$i])) {
                        continue;
                    }
                    [0 => $directive, 1 => $nonce] = \explode(':', $unhashedNonce);
                    if (!ToolMate::getInstance()->csp->hasNonce($directive, $nonce)) {
                        $nonce = ToolMate::getInstance()->csp->createNonce($directive);
                    }
                    $event->output = \str_replace($matches[0][$i], "nonce=\"$nonce\"", $event->output);
                }
            }
        );

        Event::on(
            Application::class,
            \yii\base\Application::EVENT_AFTER_REQUEST,
            static function(Event $event) {
                ToolMate::getInstance()->csp->setHeader();
            }
        );
    }
    
    /**
     * @param string|null $value
     * @return bool|string|null
     */
    public static function parseEnv(?string $value): bool|string|null
    {
        if (\version_compare(Craft::$app->getVersion(), '3.7.29', '<')) {
            return Craft::parseEnv($value);
        }
        return App::parseEnv($value);
    }
}

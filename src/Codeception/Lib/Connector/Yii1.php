<?php
namespace Codeception\Lib\Connector;

use Codeception\Exception\ConfigurationException;
use Codeception\Lib\Connector\Yii1\HttpRequest;
use Codeception\Lib\Connector\Yii1\Logger;
use Codeception\Stub;
use Symfony\Component\BrowserKit\AbstractBrowser;
use Symfony\Component\BrowserKit\Request;
use Symfony\Component\BrowserKit\Response;
use Yii;

class Yii1 extends AbstractBrowser
{
    use Shared\PhpSuperGlobalsConverter;

    /**
     * @var string
     */
    public $applicationClass = '';

    /**
     * @var string application config file
     */
    public $configFile = '';

    /**
     * @var string[] Application components to reset before each request
     */
    public $resetComponents = [];

    /**
     * @var string
     */
    public $userIdentityClass = '';

    /**
     * @param Request $request
     * @return Response
     */
    public function doRequest(object $request): object
    {
        codecept_debug('Request starts...');
        $this->fillSuperglobals($request);

        ob_start();

        $this->beforeRequest();
        $app = Yii::app();

        if ($app->hasEventHandler('onBeginRequest')) {
            $app->onBeginRequest(new \CEvent($app));
        }
        $yiiRequest = $app->getRequest();
        $route = $app->getUrlManager()->parseUrl($yiiRequest);
        $app->runController($route);
        if ($app->hasEventHandler('onEndRequest')) {
            $app->onEndRequest(new \CEvent($app));
        }

        $content = ob_get_clean();

        $response = new Response($content, $yiiRequest->getStatusCode(), $yiiRequest->getAllHeaders());

        codecept_debug('Request done, got a response.');
        return $response;
    }

    private function fillSuperglobals(Request $request)
    {
        $_COOKIE = $request->getCookies();
        $_SERVER = $request->getServer();
        $_FILES = $this->remapFiles($request->getFiles());
        $_REQUEST = $this->remapRequestParameters($request->getParameters());
        $_POST = $_GET = [];

        if (strtoupper($request->getMethod()) === 'GET') {
            $_GET = $_REQUEST;
        } else {
            $_POST = $_REQUEST;
        }

        // Parse url parts
        $uri = $request->getUri();

        $pathString = parse_url($uri, PHP_URL_PATH);
        $queryString = parse_url($uri, PHP_URL_QUERY);
        $_SERVER['REQUEST_URI'] = $queryString === null ? $pathString : "{$pathString}?{$queryString}";
        $_SERVER['REQUEST_METHOD'] = strtoupper($request->getMethod());
        $_SERVER['QUERY_STRING'] = (string) $queryString;

        parse_str((string) $queryString, $params);
        foreach ($params as $k => $v) {
            $_GET[$k] = $v;
        }
    }

    /**
     * Called before each request.
     */
    private function beforeRequest()
    {
        codecept_debug('Preparing for request');
        $app = Yii::app();
        if ($app === null) {
            $this->startApplication();
            $app = Yii::app();
        }
        // delete the old request
        $app->setComponent('request', null, false);
        // force the class for the next request
        $app->setComponent('request', ['class' => HttpRequest::class], false);
        // disable regenerate id in session
        $session = $app->getComponent('session');
        if ($session) {
            $app->setComponent('session', Stub::make('CHttpSession', ['regenerateID' => false]));
        }
        // disabling logging. Logs slow down test execution
        if ($app->hasComponent('log')) {
            foreach ($app->getComponent('log')->routes as $route) {
                $route->enabled = false;
            }
        }

        // Reset some components
        foreach ($this->resetComponents as $componentName) {
            // Only recreate if it has actually been instantiated.
            if ($app->getComponent($componentName, false) !== null) {
                $app->setComponent($componentName, null);
            }
        }
    }

    /**
     * Cleanup after a test was run.
     */
    public function resetApplication()
    {
        codecept_debug('Resetting application');
        $app = Yii::app();
        if ($app !== null) {
            // close the session
            $session = $app->getComponent('session', false);
            if ($session !== null) {
                $session->close();
            }
            // disconnect from the main DB
            $db = $app->getComponent('db', false);
            if ($db !== null) {
                // Remove a hidden global state which induces hard to debug side-effects
                \CActiveRecord::$db = null;
                // cleanup metadata cache
                $property = new \ReflectionProperty('CActiveRecord', '_md');
                $property->setAccessible(true);
                $property->setValue(null, []);
                // close and delete the db component
                $db->setActive(false);
                $db->getCurrentTransaction();
                $app->setComponent('db', null);
            }
        }
        Yii::setApplication(null);
        \CUploadedFile::reset();
        Yii::setLogger(null);
        // Resolve an issue with database connections not closing properly.
        gc_collect_cycles();
    }

    /**
     * Prepare the Yii app for a new test.
     */
    public function startApplication()
    {
        codecept_debug('Starting application');
        $config = require($this->configFile);
        if (Yii::app() !== null) {
            $this->resetApplication();
        }
        Yii::$enableIncludePath = false;
        $app = Yii::createApplication($this->applicationClass, $config);
        assert($app instanceof \CApplication);
        Yii::setLogger(new Logger());
    }

    public function restart(): void
    {
        parent::restart();
        $this->resetApplication();
    }

    public function findAndLoginUser(string $username, string $password)
    {
        $app = Yii::app();
        if ($app === null) {
            $this->startApplication();
            $app = Yii::app();
        }
        if (empty($this->userIdentityClass)) {
            throw new ConfigurationException("Configuration of 'userIdentityClass' is missing");
        }
        $c = $this->userIdentityClass;
        $userIdentity = new $c($username, $password);
        if (!($userIdentity instanceof \CBaseUserIdentity)) {
            throw new ConfigurationException("Configuration of 'userIdentityClass' doest not derive from CBaseUserIdentity");
        }
        $userIdentity->authenticate();

        $session = $app->getComponent('session');
        if ($session) {
            $app->setComponent('session', Stub::make('CHttpSession', ['regenerateID' => false]));
        }
        $app->user->login($userIdentity);
    }

    public function logout()
    {
        $app = Yii::app();
        if ($app === null) {
            return;
        }
        $session = $app->getComponent('session');
        if ($session) {
            $app->setComponent('session', Stub::make('CHttpSession', ['regenerateID' => false]));
        }
        $app->user->logout();
    }
}

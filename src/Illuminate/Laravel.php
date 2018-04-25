<?php

namespace Hhxsv5\LaravelS\Illuminate;

use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Http\Request as IlluminateRequest;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class Laravel
{
    protected $app;

    /**
     * @var HttpKernel $laravelKernel
     */
    protected $laravelKernel;

    protected static $snapshotKeys = ['config'];

    /**
     * @var array $snapshots
     */
    protected $snapshots = [];

    protected $conf = [];

    protected static $staticBlackList = [
        '/index.php'  => 1,
        '/.htaccess'  => 1,
        '/web.config' => 1,
    ];

    private $rawGlobals = [];

    public function __construct(array $conf = [])
    {
        $this->conf = $conf;
    }

    public function prepareLaravel()
    {
        $this->autoload();
        $this->createApp();
        $this->createKernel();
        $this->setLaravel();
        $this->consoleKernelBootstrap();
        $this->saveSnapshots();
    }

    protected function autoload()
    {
        $autoload = $this->conf['rootPath'] . '/bootstrap/autoload.php';
        if (file_exists($autoload)) {
            require_once $autoload;
        } else {
            require_once $this->conf['rootPath'] . '/vendor/autoload.php';
        }
    }

    protected function createApp()
    {
        $this->app = require $this->conf['rootPath'] . '/bootstrap/app.php';
    }

    protected function createKernel()
    {
        if (!$this->conf['isLumen']) {
            $this->laravelKernel = $this->app->make(HttpKernel::class);
        }
    }

    protected function setLaravel()
    {
        // Load configuration laravel.php manually for Lumen
        if ($this->conf['isLumen'] && file_exists($this->conf['rootPath'] . '/config/laravels.php')) {
            $this->app->configure('laravels');
        }

        $server = isset($this->conf['_SERVER']) ? $this->conf['_SERVER'] : [];
        $env = isset($this->conf['_ENV']) ? $this->conf['_ENV'] : [];
        $this->rawGlobals['_SERVER'] = array_merge($_SERVER, $server);
        $this->rawGlobals['_ENV'] = array_merge($_ENV, $env);
    }

    protected function consoleKernelBootstrap()
    {
        if ($this->conf['isLumen']) {
            if (Facade::getFacadeApplication() === null) {
                $this->app->withFacades();
            }
        } else {
            $this->app->make(ConsoleKernel::class)->bootstrap();
        }
    }

    protected function saveSnapshots()
    {
        $this->snapshots = [];
        foreach (self::$snapshotKeys as $key) {
            if (isset($this->app[$key])) {
                $t = &$this->app[$key];
                if (is_object($t)) {
                    $this->snapshots[$key] = clone $t;
                } else {
                    $this->snapshots[$key] = $t;
                }
            }
        }
    }

    protected function applySnapshots()
    {
        foreach ($this->snapshots as $key => $value) {
            if (is_object($value)) {
                $this->app[$key] = clone $value;
            } else {
                $this->app[$key] = $value;
            }
        }
    }

    public function getRawGlobals()
    {
        return $this->rawGlobals;
    }

    public function handleDynamic(IlluminateRequest $request)
    {
        $this->applySnapshots();

        ob_start();

        if ($this->conf['isLumen']) {
            $response = $this->app->dispatch($request);
            if ($response instanceof SymfonyResponse) {
                $content = $response->getContent();
            } else {
                $content = (string)$response;
            }

            $reflect = new \ReflectionObject($this->app);
            $middleware = $reflect->getProperty('middleware');
            $middleware->setAccessible(true);
            if (count($middleware->getValue($this->app)) > 0) {
                $callTerminableMiddleware = $reflect->getMethod('callTerminableMiddleware');
                $callTerminableMiddleware->setAccessible(true);
                $callTerminableMiddleware->invoke($this->app, $response);
            }
        } else {
            $response = $this->laravelKernel->handle($request);
            $content = $response->getContent();
            $this->laravelKernel->terminate($request, $response);
        }

        // prefer content in response, secondly ob
        if (strlen($content) === 0 && ob_get_length() > 0) {
            $response->setContent(ob_get_contents());
        }

        ob_end_clean();

        return $response;
    }

    public function handleStatic(IlluminateRequest $request)
    {
        $uri = $request->getRequestUri();
        if (isset(self::$staticBlackList[$uri])) {
            return false;
        }

        $publicPath = $this->conf['staticPath'];
        $requestFile = $publicPath . $uri;
        if (is_file($requestFile)) {
            return $this->createStaticResponse($requestFile, $request->header('if-modified-since'));
        } elseif (is_dir($requestFile)) {
            $indexFile = $this->lookupIndex($requestFile);
            if ($indexFile === false) {
                return false;
            } else {
                return $this->createStaticResponse($indexFile, $request->header('if-modified-since'));
            }
        } else {
            return false;
        }
    }

    protected function lookupIndex($folder)
    {
        $folder = rtrim($folder, '/') . '/';
        foreach (['index.html', 'index.htm'] as $index) {
            $tmpFile = $folder . $index;
            if (is_file($tmpFile)) {
                return $tmpFile;
            }
        }
        return false;
    }

    public function createStaticResponse($requestFile, $modifiedSince = null)
    {
        $code = SymfonyResponse::HTTP_OK;
        $mtime = filemtime($requestFile);
        if ($modifiedSince !== null) {
            $modifiedSince = strtotime($modifiedSince);
            if ($modifiedSince !== false && $modifiedSince >= $mtime) {
                $code = SymfonyResponse::HTTP_NOT_MODIFIED;
            }
        }

        $maxAge = 24 * 3600;
        $rsp = new BinaryFileResponse($requestFile, $code);
        $rsp->setLastModified(new \DateTime(date('Y-m-d H:i:s', $mtime)));
        $rsp->setMaxAge($maxAge);
        $rsp->setPrivate();
        $rsp->setExpires(new \DateTime(date('Y-m-d H:i:s', time() + $maxAge)));
        return $rsp;
    }

    public function reRegisterServiceProvider($providerCls, array $clearFacades = [])
    {
        if (class_exists($providerCls, false)) {
            foreach ($clearFacades as $facade) {
                Facade::clearResolvedInstance($facade);
            }
            $provider = $this->app->register($providerCls, [], true);
            if (!$this->conf['isLumen']) {
                if (method_exists($provider, 'boot')) {
                    $provider->boot();
                }
            }
        }
    }

    public function cleanRequest(IlluminateRequest $request)
    {
        // Clean laravel session
        if ($request->hasSession()) {
            $session = $request->getSession();
            if (method_exists($session, 'clear')) {
                $session->clear();
            } elseif (method_exists($session, 'flush')) {
                $session->flush();
            }
            // TODO: clear session for other versions
        }

        // Clean laravel cookie queue
        if (isset($this->app['cookie'])) {
            /**
             * @var \Illuminate\Contracts\Cookie\QueueingFactory $cookies
             */
            $cookies = $this->app['cookie'];
            foreach ($cookies->getQueuedCookies() as $name => $cookie) {
                $cookies->unqueue($name);
            }
        }

        // Re-register some singleton providers
        foreach ($this->conf['registerProviders'] as $provider) {
            $this->reRegisterServiceProvider($provider);
        }

        // Re-register auth
        $this->reRegisterServiceProvider('\Illuminate\Auth\AuthServiceProvider', ['auth', 'auth.driver']);
        $this->reRegisterServiceProvider('\Illuminate\Auth\Passwords\PasswordResetServiceProvider', ['auth.password']);

        // Re-register jwt auth
        $this->reRegisterServiceProvider('\Tymon\JWTAuth\Providers\LaravelServiceProvider');
        $this->reRegisterServiceProvider('\Tymon\JWTAuth\Providers\LumenServiceProvider');

        // Re-register passport
        $this->reRegisterServiceProvider('\Laravel\Passport\PassportServiceProvider');

        // Clear request
        $this->app->forgetInstance('request');
        Facade::clearResolvedInstance('request');

        //...
    }

    public function fireEvent($name, array $params = [])
    {
        $params[] = $this->app;
        return $this->app->events->fire($name, $params);
    }

    public function bindRequest(IlluminateRequest $request)
    {
        $this->app->instance('request', $request);
    }

    public function bindSwoole($swoole)
    {
        $this->app->singleton('swoole', function () use ($swoole) {
            return $swoole;
        });
    }

    public function make($abstract, array $parameters = [])
    {
        return $this->app->make($abstract, $parameters);
    }
}

<?php

declare(strict_types=1);

namespace Scoutapm\Laravel\Providers;

use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel as HttpKernelInterface;
use Illuminate\Contracts\View\Engine;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Http\Kernel as HttpKernelImplementation;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\Engines\EngineResolver;
use Scoutapm\Agent;
use Scoutapm\Config;
use Scoutapm\Config\ConfigKey;
use Scoutapm\Helper\ComposerPackagesCheck;
use Scoutapm\Laravel\Database\QueryListener;
use Scoutapm\Laravel\Middleware\ActionInstrument;
use Scoutapm\Laravel\Middleware\IgnoredEndpoints;
use Scoutapm\Laravel\Middleware\MiddlewareInstrument;
use Scoutapm\Laravel\Middleware\SendRequestToScout;
use Scoutapm\Laravel\Queue\JobQueueListener;
use Scoutapm\Laravel\View\Engine\ScoutViewEngineDecorator;
use Scoutapm\Logger\FilteredLogLevelDecorator;
use Scoutapm\ScoutApmAgent;
use Throwable;

use function array_combine;
use function array_filter;
use function array_map;
use function array_merge;
use function config_path;

final class ScoutApmServiceProvider extends ServiceProvider
{
    private const CONFIG_SERVICE_KEY = ScoutApmAgent::class . '_config';
    private const CACHE_SERVICE_KEY  = ScoutApmAgent::class . '_cache';

    private const VIEW_ENGINES_TO_WRAP = ['file', 'php', 'blade'];

    public const INSTRUMENT_LARAVEL_QUEUES = 'laravel_queues';

    /** @throws BindingResolutionException */
    public function register(): void
    {
        $this->app->singleton(self::CONFIG_SERVICE_KEY, function () {
            $configRepo = $this->app->make(ConfigRepository::class);

            return Config::fromArray(array_merge(
                array_filter(array_combine(
                    ConfigKey::allConfigurationKeys(),
                    array_map(
                        /** @return mixed */
                        static function (string $configurationKey) use ($configRepo) {
                            return $configRepo->get('scout_apm.' . $configurationKey);
                        },
                        ConfigKey::allConfigurationKeys()
                    )
                )),
                [
                    ConfigKey::FRAMEWORK => 'Laravel',
                    ConfigKey::FRAMEWORK_VERSION => $this->app->version(),
                ]
            ));
        });

        $this->app->singleton(self::CACHE_SERVICE_KEY, static function (Application $app) {
            try {
                return $app->make(CacheManager::class)->store();
            } catch (Throwable $anything) {
                return null;
            }
        });

        $this->app->singleton(FilteredLogLevelDecorator::class, static function (Application $app) {
            return new FilteredLogLevelDecorator(
                $app->make('log'),
                $app->make(self::CONFIG_SERVICE_KEY)->get(ConfigKey::LOG_LEVEL)
            );
        });

        $this->app->singleton(ScoutApmAgent::class, static function (Application $app) {
            return Agent::fromConfig(
                $app->make(self::CONFIG_SERVICE_KEY),
                $app->make(FilteredLogLevelDecorator::class),
                $app->make(self::CACHE_SERVICE_KEY)
            );
        });

        $this->app->afterResolving('view.engine.resolver', function (EngineResolver $engineResolver): void {
            foreach (self::VIEW_ENGINES_TO_WRAP as $engineName) {
                $realEngine = $engineResolver->resolve($engineName);

                $engineResolver->register($engineName, function () use ($realEngine) {
                    return $this->wrapEngine($realEngine);
                });
            }
        });
    }

    public function wrapEngine(Engine $realEngine): Engine
    {
        $viewFactory = $this->app->make('view');

        /** @noinspection UnusedFunctionResultInspection */
        $viewFactory->composer('*', static function (View $view) use ($viewFactory): void {
            $viewFactory->share(ScoutViewEngineDecorator::VIEW_FACTORY_SHARED_KEY, $view->name());
        });

        return new ScoutViewEngineDecorator(
            $realEngine,
            $this->app->make(ScoutApmAgent::class),
            $viewFactory
        );
    }

    /** @throws BindingResolutionException */
    public function boot(
        Application $application,
        ScoutApmAgent $agent,
        FilteredLogLevelDecorator $log,
        Connection $connection
    ): void {
        $log->debug('Agent is starting');

        ComposerPackagesCheck::logIfLaravelPackageNotPresent($log);

        $this->publishes([
            __DIR__ . '/../config/scout_apm.php' => config_path('scout_apm.php'),
        ]);

        $runningInConsole = $application->runningInConsole();

        $this->instrumentDatabaseQueries($agent, $connection);

        if ($agent->shouldInstrument(self::INSTRUMENT_LARAVEL_QUEUES)) {
            $this->instrumentQueues($agent, $application->make('events'), $runningInConsole);
        }

        if ($runningInConsole) {
            return;
        }

        $httpKernel = $application->make(HttpKernelInterface::class);
        /** @psalm-suppress ArgumentTypeCoercion */
        $this->instrumentMiddleware($httpKernel);
    }

    /**
     * @param HttpKernelImplementation $kernel
     *
     * @noinspection PhpDocSignatureInspection
     */
    private function instrumentMiddleware(HttpKernelInterface $kernel): void
    {
        $kernel->prependMiddleware(MiddlewareInstrument::class);
        $kernel->pushMiddleware(ActionInstrument::class);

        // Must be outside any other scout instruments. When this middleware's terminate is called, it will complete
        // the request, and send it to the CoreAgent.
        $kernel->prependMiddleware(IgnoredEndpoints::class);
        $kernel->prependMiddleware(SendRequestToScout::class);
    }

    private function instrumentDatabaseQueries(ScoutApmAgent $agent, Connection $connection): void
    {
        $connection->listen(static function (QueryExecuted $query) use ($agent): void {
            (new QueryListener($agent))->__invoke($query);
        });
    }

    private function instrumentQueues(ScoutApmAgent $agent, Dispatcher $eventDispatcher, bool $runningInConsole): void
    {
        $listener = new JobQueueListener($agent);

        $eventDispatcher->listen(JobProcessing::class, static function (JobProcessing $event) use ($listener, $runningInConsole): void {
            if ($runningInConsole) {
                $listener->startNewRequestForJob();
            }

            $listener->startSpanForJob($event);
        });

        $eventDispatcher->listen(JobProcessed::class, static function (JobProcessed $event) use ($listener, $runningInConsole): void {
            $listener->stopSpanForJob();

            if (! $runningInConsole) {
                return;
            }

            $listener->sendRequestForJob();
        });
    }
}

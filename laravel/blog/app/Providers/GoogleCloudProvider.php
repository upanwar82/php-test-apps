<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Google\Auth\HttpHandler\HttpHandlerFactory;
use Google\Cloud\ServiceBuilder;
use Google\Cloud\Trace\TraceClient;
use Google\Cloud\Trace\RequestTracer;
use Google\Cloud\Trace\Reporter\EchoReporter;
use Google\Cloud\Trace\Reporter\FileReporter;
use Google\Cloud\Trace\Reporter\SyncReporter;
use Google\Cloud\Trace\Reporter\AsyncReporter;
use Google\Cloud\Trace\Reporter\ReporterInterface;
use Google\Cloud\Trace\Sampler\SamplerInterface;
use Google\Cloud\Trace\Sampler\AlwaysOnSampler;
use Google\Cloud\Trace\Sampler\QpsSampler;
use Google\Cloud\Trace\Integrations\Curl;
use Google\Cloud\Trace\Integrations\Laravel;
use Google\Cloud\Trace\Integrations\Mysql;
use Google\Cloud\Trace\Integrations\PDO;
use Google\Cloud\Trace\Integrations\Guzzle;
use Google\Cloud\Trace\Integrations\Grpc;
use Google\Cloud\Translate\TranslateClient;
use Google\Cloud\PubSub\PubSubClient;
use GuzzleHttp\ClientInterface as GuzzleClient;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\CacheItemInterface;
use Illuminate\Database\Events\QueryExecuted;
use Google\Cloud\Trace\Integrations\Guzzle\Middleware;
use App\GoogleCloudConfig;
use Google\Cloud\Core\Batch\BatchRunner;

class GoogleCloudProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     *
     * @return void
     */
    public function boot(ReporterInterface $reporter, SamplerInterface $sampler)
    {
        // don't trace if we're running in the console (i.e. a php artisan command)
        if (php_sapi_name() == 'cli') {
            return;
        }

        Laravel::load();
        Mysql::load();
        PDO::load();
        Curl::load();
        Grpc::load();

        stackdriver_trace_method(BatchRunner::class, 'submitItem', function ($batchRunner, $identifier, $item) {
            return [
                'labels' => [
                    'identifier' => $identifier
                ]
            ];
        });

        // start the root span
        RequestTracer::start($reporter, [
            'sampler' => $sampler
        ]);

        // create a span from the initial start time until now as 'bootstrap'
        RequestTracer::startSpan(['name' => 'bootstrap', 'startTime' => LARAVEL_START]);
        RequestTracer::endSpan();
    }

    /**
     * Register the application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->bind(Middleware::class, function () {
            return new Middleware();
        });
        $this->app->tag([
            Middleware::class
        ], 'guzzle.middleware');

        $this->app->singleton(ServiceBuilder::class, function($app) {
            $config = new GoogleCloudConfig();
            $config = $app['config']['services']['google'] + $config->getConfigAsArray();
            return new ServiceBuilder($config);
        });
        $this->app->singleton(TraceClient::class, function($app) {
            return $app->make(ServiceBuilder::class)->trace();
        });
        $this->app->singleton(TranslateClient::class, function($app) {
            return $app->make(ServiceBuilder::class)->translate();
        });
        $this->app->singleton(ReporterInterface::class, function($app) {
            return $app->make(TraceClient::class)->reporter();
        });
        $this->app->singleton(SamplerInterface::class, function($app) {
            return new AlwaysOnSampler();
            return new QpsSampler(
                $app->make(CacheItemPoolInterface::class),
                ['cacheItemClass' => get_class($app->make(CacheItemInterface::class))]
            );
        });
        $this->app->singleton(PubSubClient::class, function($app) {
            return new PubSubClient([
                'transport' => 'grpc'
            ]);
            // return $app->make(ServiceBuilder::class)->pubsub();
        });
    }

    public function provides()
    {
        return [
            ServiceBuilder::class,
            TraceClient::class,
            ReporterInterface::class,
            SamplerInterface::class,
            TranslateClient::class,
            PubSubClient::class
        ];
    }
}

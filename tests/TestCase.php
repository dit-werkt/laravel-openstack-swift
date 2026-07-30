<?php

namespace Mzur\Filesystem\Tests;

use Biigle\CachedOpenStack\OpenStack;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\CacheManager;
use Illuminate\Cache\Repository;
use Illuminate\Config\Repository as Config;
use Illuminate\Container\Container;
use Illuminate\Filesystem\FilesystemManager;
use Mzur\Filesystem\SwiftServiceProvider;
use OpenStack\ObjectStore\v1\Models\Container as SwiftContainer;
use PHPUnit\Framework\TestCase as BaseTestCase;
use ReflectionMethod;
use ReflectionProperty;

abstract class TestCase extends BaseTestCase
{
    /**
     * Object store endpoint of the stubbed service catalog.
     *
     * @var string
     */
    const STORAGE_URL = 'https://swift.example.com/v1/AUTH_test';

    /**
     * Container name used by the default disk configuration.
     *
     * @var string
     */
    const CONTAINER = 'test-container';

    /**
     * Get a disk configuration for the swift driver.
     *
     * @param array $config Configuration to merge into the defaults.
     *
     * @return array
     */
    protected function diskConfig(array $config = [])
    {
        return array_merge([
            'driver' => 'swift',
            'authUrl' => 'https://keystone.example.com/v3',
            'region' => 'regionOne',
            'user' => 'test-user',
            'password' => 'test-password',
            'domain' => 'default',
            'container' => self::CONTAINER,
        ], $config);
    }

    /**
     * Get a container of a swift object store that is backed by the cached token.
     *
     * @return SwiftContainer
     */
    protected function makeSwiftContainer()
    {
        $config = $this->diskConfig();

        return (new OpenStack($this->makeCache(), [
                'authUrl' => $config['authUrl'],
                'region' => $config['region'],
                'user' => [
                    'name' => $config['user'],
                    'password' => $config['password'],
                    'domain' => ['name' => $config['domain']],
                ],
            ]))
            ->objectStoreV1()
            ->getContainer($config['container']);
    }

    /**
     * Get a filesystem manager with the swift driver registered by the service provider.
     *
     * @param array $disks Disk configurations, keyed by disk name.
     *
     * @return FilesystemManager
     */
    protected function makeFilesystemManager(array $disks)
    {
        $app = $this->makeApplication($disks);

        // This is what the package auto discovery does in a real application.
        (new SwiftServiceProvider($app))->boot();

        return $app->make('filesystem');
    }

    /**
     * Get a container with the services that the service provider expects.
     *
     * @param array $disks Disk configurations, keyed by disk name.
     *
     * @return Container
     */
    protected function makeApplication(array $disks)
    {
        $app = new Container;
        $app->instance('config', new Config(['filesystems' => ['disks' => $disks]]));
        $app->instance('cache', $this->makeCache());
        $app->instance('filesystem', new FilesystemManager($app));

        return $app;
    }

    /**
     * Get a cache manager that always returns a valid Keystone token.
     *
     * The cache key is an implementation detail of biigle/laravel-cached-openstack, so
     * this stub ignores the key and always reports a hit. As php-opencloud skips the
     * POST /v3/auth/tokens request when a cached token is present, the tests need no
     * Keystone (and no network) at all.
     *
     * @return CacheManager
     */
    protected function makeCache()
    {
        $token = $this->cachedToken();

        return new class($token) extends CacheManager {
            protected $token;

            public function __construct(array $token)
            {
                parent::__construct(new Container);
                $this->token = $token;
            }

            public function store($name = null)
            {
                return new Repository(new class($this->token) extends ArrayStore {
                    protected $token;

                    public function __construct(array $token)
                    {
                        parent::__construct();
                        $this->token = $token;
                    }

                    public function get($key)
                    {
                        return $this->token;
                    }
                });
            }
        };
    }

    /**
     * Get a serialized Keystone token as biigle/laravel-cached-openstack caches it.
     *
     * @return array
     */
    protected function cachedToken()
    {
        return [
            'id' => 'test-token-id',
            'methods' => ['password'],
            'issued_at' => $this->timestamp('-1 minute'),
            'expires_at' => $this->timestamp('+1 hour'),
            'catalog' => [
                [
                    'id' => 'object-store-id',
                    'name' => 'swift',
                    'type' => 'object-store',
                    'endpoints' => [
                        [
                            'id' => 'endpoint-id',
                            'interface' => 'public',
                            'region' => 'regionOne',
                            'url' => self::STORAGE_URL,
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Call a protected method of an object.
     *
     * @param object $object
     * @param string $name Method name.
     * @param array $args Method arguments.
     *
     * @return mixed
     */
    protected function callProtectedMethod($object, $name, array $args = [])
    {
        $method = new ReflectionMethod($object, $name);
        $this->makeAccessible($method);

        return $method->invokeArgs($object, $args);
    }

    /**
     * Get the value of a protected property of an object.
     *
     * @param object $object
     * @param string $name Property name.
     *
     * @return mixed
     */
    protected function getProtectedProperty($object, $name)
    {
        $property = new ReflectionProperty($object, $name);
        $this->makeAccessible($property);

        return $property->getValue($object);
    }

    /**
     * Make a reflected method or property accessible.
     *
     * This is a no-op since PHP 8.1 (and deprecated since PHP 8.5) but the package still
     * supports PHP 8.0.
     *
     * @param ReflectionMethod|ReflectionProperty $reflection
     *
     * @return void
     */
    protected function makeAccessible($reflection)
    {
        if (PHP_VERSION_ID < 80100) {
            $reflection->setAccessible(true);
        }
    }

    /**
     * Get a timestamp in the format that Keystone uses.
     *
     * @param string $modifier Relative time, e.g. '+1 hour'.
     *
     * @return string
     */
    protected function timestamp($modifier)
    {
        return (new DateTimeImmutable($modifier))->format(DateTimeInterface::ATOM);
    }
}

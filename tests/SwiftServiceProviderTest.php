<?php

namespace Mzur\Filesystem\Tests;

use DateTimeImmutable;
use Illuminate\Filesystem\FilesystemAdapter;
use Mzur\Filesystem\SwiftAdapter;
use Mzur\Filesystem\SwiftServiceProvider;
use Mzur\Filesystem\TempUrlSwiftAdapter;
use ReflectionFunction;

class SwiftServiceProviderTest extends TestCase
{
    public function testRegistersSwiftDisk()
    {
        $disk = $this->makeFilesystemManager(['swift' => $this->diskConfig()])->disk('swift');

        $this->assertInstanceOf(FilesystemAdapter::class, $disk);
        $this->assertInstanceOf(SwiftAdapter::class, $disk->getAdapter());
        $this->assertNotInstanceOf(TempUrlSwiftAdapter::class, $disk->getAdapter());
    }

    /**
     * Laravel 13 rebinds $this of anonymous driver callbacks to the FilesystemManager
     * (Illuminate\Support\RebindsCallbacksToSelf), which breaks every call to a method
     * of the service provider inside the callback.
     */
    public function testDriverCallbackKeepsTheProviderAsContext()
    {
        $manager = $this->makeFilesystemManager(['swift' => $this->diskConfig()]);

        $callback = $this->getProtectedProperty($manager, 'customCreators')['swift'];

        $this->assertInstanceOf(
            SwiftServiceProvider::class,
            (new ReflectionFunction($callback))->getClosureThis()
        );
    }

    public function testUsesTempUrlAdapterWithTempUrlKey()
    {
        $config = $this->diskConfig(['tempUrlKey' => 'secret-key']);
        $disk = $this->makeFilesystemManager(['swift' => $config])->disk('swift');

        $this->assertInstanceOf(TempUrlSwiftAdapter::class, $disk->getAdapter());
    }

    public function testUrl()
    {
        $disk = $this->makeFilesystemManager(['swift' => $this->diskConfig()])->disk('swift');

        $this->assertSame(
            self::STORAGE_URL.'/'.self::CONTAINER.'/images/1.jpg',
            $disk->url('images/1.jpg')
        );
    }

    public function testUrlWithConfiguredUrl()
    {
        $config = $this->diskConfig(['url' => 'https://cdn.example.com']);
        $disk = $this->makeFilesystemManager(['swift' => $config])->disk('swift');

        $this->assertSame('https://cdn.example.com/images/1.jpg', $disk->url('images/1.jpg'));
    }

    public function testUrlWithRoot()
    {
        $config = $this->diskConfig(['url' => 'https://cdn.example.com', 'root' => 'prefix']);
        $disk = $this->makeFilesystemManager(['swift' => $config])->disk('swift');

        $this->assertSame('https://cdn.example.com/prefix/images/1.jpg', $disk->url('images/1.jpg'));
    }

    public function testUrlWithPrefix()
    {
        $config = $this->diskConfig(['url' => 'https://cdn.example.com', 'prefix' => 'prefix']);
        $disk = $this->makeFilesystemManager(['swift' => $config])->disk('swift');

        $this->assertSame('https://cdn.example.com/prefix/images/1.jpg', $disk->url('images/1.jpg'));
    }

    public function testTemporaryUrl()
    {
        $config = $this->diskConfig(['tempUrlKey' => 'secret-key']);
        $disk = $this->makeFilesystemManager(['swift' => $config])->disk('swift');

        $this->assertSame(
            self::STORAGE_URL.'/'.self::CONTAINER.'/images/1.jpg'
                .'?temp_url_sig=2736a6429937c1066993335ac4233c81140128fc'
                .'&temp_url_expires=1700000000',
            $disk->temporaryUrl('images/1.jpg', new DateTimeImmutable('@1700000000'))
        );
    }

    public function testMultipleDisks()
    {
        $manager = $this->makeFilesystemManager([
            'swift' => $this->diskConfig(),
            'swift_public' => $this->diskConfig([
                'container' => 'public-container',
                'url' => 'https://cdn.example.com',
            ]),
        ]);

        $this->assertSame(
            self::STORAGE_URL.'/'.self::CONTAINER.'/images/1.jpg',
            $manager->disk('swift')->url('images/1.jpg')
        );
        $this->assertSame(
            'https://cdn.example.com/images/1.jpg',
            $manager->disk('swift_public')->url('images/1.jpg')
        );
    }

    public function testOsOptions()
    {
        $options = $this->getOsOptions($this->diskConfig());

        $this->assertSame('https://keystone.example.com/v3', $options['authUrl']);
        $this->assertSame('regionOne', $options['region']);
        $this->assertSame([
            'name' => 'test-user',
            'password' => 'test-password',
            'domain' => ['name' => 'default'],
        ], $options['user']);
        $this->assertArrayNotHasKey('scope', $options);
        $this->assertArrayNotHasKey('cacheOptions', $options);
    }

    public function testOsOptionsWithProjectId()
    {
        $options = $this->getOsOptions($this->diskConfig(['projectId' => 'test-project']));

        $this->assertSame(['project' => ['id' => 'test-project']], $options['scope']);
    }

    public function testOsOptionsWithTtl()
    {
        $options = $this->getOsOptions($this->diskConfig(['ttl' => 60]));

        $this->assertSame(['ttl' => 60], $options['cacheOptions']);
    }

    public function testFlyConfig()
    {
        $this->assertSame([], $this->getFlyConfig($this->diskConfig()));
        $this->assertSame([
            'swiftLargeObjectThreshold' => 104857600,
            'swiftSegmentSize' => 52428800,
            'swiftSegmentContainer' => 'segments',
        ], $this->getFlyConfig($this->diskConfig([
            'swiftLargeObjectThreshold' => 104857600,
            'swiftSegmentSize' => 52428800,
            'swiftSegmentContainer' => 'segments',
        ])));
    }

    /**
     * Call the protected getOsOptions method of the service provider.
     *
     * @param array $config Disk configuration.
     *
     * @return array
     */
    protected function getOsOptions(array $config)
    {
        return $this->callProtectedMethod($this->makeProvider(), 'getOsOptions', [$config]);
    }

    /**
     * Call the protected getFlyConfig method of the service provider.
     *
     * @param array $config Disk configuration.
     *
     * @return array
     */
    protected function getFlyConfig(array $config)
    {
        return $this->callProtectedMethod($this->makeProvider(), 'getFlyConfig', [$config]);
    }

    /**
     * Get a service provider instance.
     *
     * @return SwiftServiceProvider
     */
    protected function makeProvider()
    {
        return new SwiftServiceProvider($this->makeApplication([]));
    }
}

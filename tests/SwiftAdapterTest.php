<?php

namespace Mzur\Filesystem\Tests;

use Mzur\Filesystem\SwiftAdapter;

class SwiftAdapterTest extends TestCase
{
    public function testGetUrlWithoutConfiguredUrl()
    {
        $adapter = new SwiftAdapter($this->makeSwiftContainer());

        $this->assertSame(
            self::STORAGE_URL.'/'.self::CONTAINER.'/images/1.jpg',
            $adapter->getUrl('images/1.jpg')
        );
    }

    public function testGetUrlWithConfiguredUrl()
    {
        $adapter = new SwiftAdapter($this->makeSwiftContainer(), '', 'https://cdn.example.com');

        $this->assertSame('https://cdn.example.com/images/1.jpg', $adapter->getUrl('images/1.jpg'));
    }

    public function testGetUrlTrimsTrailingSlashOfConfiguredUrl()
    {
        $adapter = new SwiftAdapter($this->makeSwiftContainer(), '', 'https://cdn.example.com/');

        $this->assertSame('https://cdn.example.com/images/1.jpg', $adapter->getUrl('images/1.jpg'));
    }

    public function testGetUrlAppliesPrefix()
    {
        $adapter = new SwiftAdapter($this->makeSwiftContainer(), 'prefix', 'https://cdn.example.com');

        $this->assertSame('https://cdn.example.com/prefix/images/1.jpg', $adapter->getUrl('images/1.jpg'));
    }

    public function testGetUrlAppliesPrefixWithoutConfiguredUrl()
    {
        $adapter = new SwiftAdapter($this->makeSwiftContainer(), 'prefix');

        $this->assertSame(
            self::STORAGE_URL.'/'.self::CONTAINER.'/prefix/images/1.jpg',
            $adapter->getUrl('images/1.jpg')
        );
    }
}

<?php

namespace Mzur\Filesystem\Tests;

use DateTimeImmutable;
use Mzur\Filesystem\TempUrlSwiftAdapter;

class TempUrlSwiftAdapterTest extends TestCase
{
    /**
     * Expiration used for the expected signatures below.
     *
     * @var int
     */
    const EXPIRES = 1700000000;

    /**
     * Key used for the expected signatures below.
     *
     * @var string
     */
    const KEY = 'secret-key';

    public function testGetTemporaryUrl()
    {
        $adapter = new TempUrlSwiftAdapter($this->makeSwiftContainer(), self::KEY);

        // The signature is over "GET\n<expires>\n/v1/AUTH_test/test-container/images/1.jpg".
        // It is hard coded here on purpose: a change of the signed string breaks every
        // existing temporary URL, so it should never change unnoticed.
        $this->assertSame(
            self::STORAGE_URL.'/'.self::CONTAINER.'/images/1.jpg'
                .'?temp_url_sig=2736a6429937c1066993335ac4233c81140128fc'
                .'&temp_url_expires='.self::EXPIRES,
            $adapter->getTemporaryUrl('images/1.jpg', $this->expiration(), [])
        );
    }

    public function testGetTemporaryUrlWithAlgo()
    {
        $adapter = new TempUrlSwiftAdapter($this->makeSwiftContainer(), self::KEY);

        $this->assertSame(
            self::STORAGE_URL.'/'.self::CONTAINER.'/images/1.jpg'
                .'?temp_url_sig=49e58d1e640e5469c593104f32e4d99e1c0a87ee1470073e76a86a8e6e3fb87c'
                .'&temp_url_expires='.self::EXPIRES,
            $adapter->getTemporaryUrl('images/1.jpg', $this->expiration(), ['algo' => 'sha256'])
        );
    }

    public function testGetTemporaryUrlSignsDecodedPath()
    {
        $adapter = new TempUrlSwiftAdapter($this->makeSwiftContainer(), self::KEY);
        $url = $adapter->getTemporaryUrl('images/a b.jpg', $this->expiration(), []);

        $expected = hash_hmac(
            'sha1',
            "GET\n".self::EXPIRES."\n/v1/AUTH_test/".self::CONTAINER.'/images/a b.jpg',
            self::KEY
        );

        // Swift signs the decoded path, so the signature must not depend on the encoding
        // of the URL.
        $this->assertStringContainsString("temp_url_sig={$expected}", $url);
    }

    public function testGetTemporaryUrlWithConfiguredUrl()
    {
        $adapter = new TempUrlSwiftAdapter(
            $this->makeSwiftContainer(),
            self::KEY,
            '',
            'https://cdn.example.com/v1/AUTH_test/'.self::CONTAINER
        );

        $this->assertSame(
            'https://cdn.example.com/v1/AUTH_test/'.self::CONTAINER.'/images/1.jpg'
                .'?temp_url_sig=2736a6429937c1066993335ac4233c81140128fc'
                .'&temp_url_expires='.self::EXPIRES,
            $adapter->getTemporaryUrl('images/1.jpg', $this->expiration(), [])
        );
    }

    /**
     * Get the expiration used for the expected signatures.
     *
     * @return DateTimeImmutable
     */
    protected function expiration()
    {
        return new DateTimeImmutable('@'.self::EXPIRES);
    }
}

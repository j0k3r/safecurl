<?php

use fin1te\SafeCurl\Options;
use fin1te\SafeCurl\SafeCurl;

class SafeCurlTest extends \PHPUnit\Framework\TestCase
{
    public function testFunctionnalGET()
    {
        $handle = curl_init();

        $safeCurl = new SafeCurl($handle);
        $response = $safeCurl->execute('http://www.google.com');

        $this->assertNotEmpty($response);
        $this->assertEquals($handle, $safeCurl->getCurlHandle());
        $this->assertStringNotContainsString('HTTP/1.1 302 Found', $response);
    }

    public function testFunctionnalHEAD()
    {
        $handle = curl_init();
        // for an unknown reason, HEAD request failed: https://travis-ci.org/j0k3r/safecurl/jobs/91936743
        // curl_setopt($handle, CURLOPT_CUSTOMREQUEST, 'HEAD');
        curl_setopt($handle, CURLOPT_NOBODY, true);

        $safeCurl = new SafeCurl($handle);
        $response = $safeCurl->execute('http://40.media.tumblr.com/39e917383bf5fe228b82fef850251220/tumblr_nxyw8cjiYx1u7jfjwo1_100.jpg');

        $this->assertEquals('', $response);
        $this->assertEquals($handle, $safeCurl->getCurlHandle());
        $this->assertStringNotContainsString('HTTP/1.1 302 Found', $response);
    }

    public function testBadCurlHandler()
    {
        $this->expectException(\fin1te\SafeCurl\Exception::class);
        $this->expectExceptionMessage('SafeCurl expects a valid cURL resource - "NULL" provided.');

        new SafeCurl(null);
    }

    public function dataForBlockedUrl()
    {
        return array(
            array(
                'http://0.0.0.0:123',
                \fin1te\SafeCurl\Exception\InvalidURLException\InvalidPortException::class,
                'Provided port "123" doesn\'t match whitelisted values: 80, 443, 8080',
            ),
            array(
                'http://127.0.0.1/server-status',
                \fin1te\SafeCurl\Exception\InvalidURLException\InvalidIPException::class,
                'Provided host "127.0.0.1" resolves to "127.0.0.1", which matches a blacklisted value: 127.0.0.0/8',
            ),
            array(
                'file:///etc/passwd',
                \fin1te\SafeCurl\Exception\InvalidURLException::class,
                'Provided URL "file:///etc/passwd" doesn\'t contain a hostname',
            ),
            array(
                'ssh://localhost',
                \fin1te\SafeCurl\Exception\InvalidURLException\InvalidSchemeException::class,
                'Provided scheme "ssh" doesn\'t match whitelisted values: http, https',
            ),
            array(
                'gopher://localhost',
                \fin1te\SafeCurl\Exception\InvalidURLException\InvalidSchemeException::class,
                'Provided scheme "gopher" doesn\'t match whitelisted values: http, https',
            ),
            array(
                'telnet://localhost:25',
                \fin1te\SafeCurl\Exception\InvalidURLException\InvalidSchemeException::class,
                'Provided scheme "telnet" doesn\'t match whitelisted values: http, https',
            ),
            array(
                'http://169.254.169.254/latest/meta-data/',
                \fin1te\SafeCurl\Exception\InvalidURLException\InvalidIPException::class,
                'Provided host "169.254.169.254" resolves to "169.254.169.254", which matches a blacklisted value: 169.254.0.0/16',
            ),
            array(
                'ftp://myhost.com',
                \fin1te\SafeCurl\Exception\InvalidURLException\InvalidSchemeException::class,
                'Provided scheme "ftp" doesn\'t match whitelisted values: http, https',
            ),
            array(
                'http://user:pass@safecurl.fin1te.net?@google.com/',
                \fin1te\SafeCurl\Exception\InvalidURLException::class,
                'Credentials passed in but "sendCredentials" is set to false',
            ),
        );
    }

    /**
     * @dataProvider dataForBlockedUrl
     */
    public function testBlockedUrl($url, $exception, $message)
    {
        $this->expectException($exception);
        $this->expectExceptionMessage($message);

        $safeCurl = new SafeCurl(curl_init());
        $safeCurl->execute($url);
    }

    public function dataForBlockedUrlByOptions()
    {
        return array(
            array(
                'http://login:password@google.fr',
                \fin1te\SafeCurl\Exception\InvalidURLException::class,
                'Credentials passed in but "sendCredentials" is set to false',
            ),
            array(
                'http://safecurl.fin1te.net',
                \fin1te\SafeCurl\Exception\InvalidURLException::class,
                'Provided host "safecurl.fin1te.net" matches a blacklisted value',
            ),
        );
    }

    /**
     * @dataProvider dataForBlockedUrlByOptions
     */
    public function testBlockedUrlByOptions($url, $exception, $message)
    {
        $this->expectException($exception);
        $this->expectExceptionMessage($message);

        $options = new Options();
        $options->addToList('blacklist', 'domain', '(.*)\.fin1te\.net');
        $options->addToList('whitelist', 'scheme', 'ftp');
        $options->disableSendCredentials();

        $safeCurl = new SafeCurl(curl_init(), $options);
        $safeCurl->execute($url);
    }

    public function testWithPinDnsEnabled()
    {
        $options = new Options();
        $options->enablePinDns();

        $safeCurl = new SafeCurl(curl_init(), $options);
        $response = $safeCurl->execute('http://google.com');

        $this->assertNotEmpty($response);
    }

    public function testWithFollowLocationLimit()
    {
        $this->expectException(\fin1te\SafeCurl\Exception::class);
        $this->expectExceptionMessage('Redirect limit "1" hit');

        $options = new Options();
        $options->enableFollowLocation();
        $options->setFollowLocationLimit(1);

        $safeCurl = new SafeCurl(curl_init(), $options);
        $safeCurl->execute('http://t.co/5AMOLpSq3v');
    }

    public function testWithFollowLocation()
    {
        $options = new Options();
        $options->enableFollowLocation();

        $safeCurl = new SafeCurl(curl_init(), $options);
        $response = $safeCurl->execute('http://t.co/5AMOLpSq3v');

        $this->assertNotEmpty($response);
    }

    public function testWithFollowLocationLeadingToABlockedUrl()
    {
        $this->expectException(\fin1te\SafeCurl\Exception::class);
        $this->expectExceptionMessage('Provided port "123" doesn\'t match whitelisted values: 80, 443, 8080');

        $options = new Options();
        $options->enableFollowLocation();

        $safeCurl = new SafeCurl(curl_init(), $options);
        $safeCurl->execute('http://httpbin.org/redirect-to?url=http://0.0.0.0:123');
    }

    public function testWithCurlTimeout()
    {
        $this->expectException(\fin1te\SafeCurl\Exception::class);
        $this->expectExceptionMessage('cURL Error:');

        $handle = curl_init();
        curl_setopt($handle, CURLOPT_TIMEOUT_MS, 1);

        $safeCurl = new SafeCurl($handle);
        $safeCurl->execute('https://httpstat.us/200?sleep=100');
    }
}

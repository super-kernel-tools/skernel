<?php

declare(strict_types=1);

namespace Src\Tests\Unit\Toolchain;

use PHPUnit\Framework\TestCase;
use Src\Toolchain\HttpProxy;
use Symfony\Component\Console\Exception\RuntimeException;

use function base64_encode;
use function getenv;
use function is_string;
use function putenv;

final class HttpProxyTest extends TestCase
{
    private const array VARIABLES = [
        'http_proxy',
        'HTTP_PROXY',
        'https_proxy',
        'HTTPS_PROXY',
        'no_proxy',
        'NO_PROXY',
    ];

    /** @var array<string, string|false> */
    private array $previous = [];

    protected function setUp(): void
    {
        foreach (self::VARIABLES as $variable) {
            $this->previous[$variable] = getenv($variable);
            putenv($variable);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->previous as $variable => $value) {
            putenv(is_string($value) ? $variable . '=' . $value : $variable);
        }
    }

    public function testUsesNoProxyWhenTheEnvironmentNamesNone(): void
    {
        self::assertSame([], HttpProxy::options('https://www.php.net/releases/'));
    }

    public function testRoutesHttpsThroughTheHttpsProxyWithItsCredentials(): void
    {
        putenv('https_proxy=http://user:p%40ss@proxy.example:3128');
        putenv('http_proxy=http://other.example:8080');

        self::assertSame([
            'proxy'           => 'tcp://proxy.example:3128',
            'request_fulluri' => false,
            'header'          => 'Proxy-Authorization: Basic ' . base64_encode('user:p@ss'),
        ], HttpProxy::options('https://www.php.net/releases/'));
    }

    public function testFallsBackToTheUppercaseVariableAndAssumesAnHttpProxy(): void
    {
        putenv('HTTPS_PROXY=proxy.example');

        self::assertSame([
            'proxy'           => 'tcp://proxy.example:80',
            'request_fulluri' => false,
        ], HttpProxy::options('https://ziglang.org/download/'));
    }

    public function testConnectsToAnHttpsProxyOverTls(): void
    {
        putenv('https_proxy=https://proxy.example');

        self::assertSame([
            'proxy'           => 'ssl://proxy.example:443',
            'request_fulluri' => false,
        ], HttpProxy::options('https://ziglang.org/download/'));
    }

    public function testRoutesHttpThroughTheHttpProxyWithTheFullUri(): void
    {
        putenv('https_proxy=http://other.example:3128');
        putenv('http_proxy=http://proxy.example:8080');

        self::assertSame([
            'proxy'           => 'tcp://proxy.example:8080',
            'request_fulluri' => true,
        ], HttpProxy::options('http://downloads.example/archive.tar.xz'));
    }

    public function testBypassesTheProxyForHostsAndSubdomainsThatNoProxyNames(): void
    {
        putenv('https_proxy=http://proxy.example:3128');
        putenv('no_proxy=localhost, .php.net');

        self::assertSame([], HttpProxy::options('https://php.net/releases/'));
        self::assertSame([], HttpProxy::options('https://www.php.net/releases/'));
        self::assertNotSame([], HttpProxy::options('https://notphp.net/releases/'));

        putenv('no_proxy');
        putenv('NO_PROXY=*');

        self::assertSame([], HttpProxy::options('https://ziglang.org/download/'));
    }

    public function testRejectsAProxyUrlWithAnUnsupportedScheme(): void
    {
        putenv('https_proxy=socks5://proxy.example:1080');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('The https_proxy environment variable is not a valid proxy URL.');

        HttpProxy::options('https://www.php.net/releases/');
    }
}

<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\HttpClient\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\ResolverHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResolverInterface;

class ResolverHttpClientTest extends TestCase
{
    public function testResolverIsCalledOnRequest()
    {
        $resolvedHosts = [];
        $resolver = $this->createResolver(function (string $host) use (&$resolvedHosts): ?string {
            $resolvedHosts[] = $host;

            return '1.2.3.4';
        });

        $mockClient = new MockHttpClient(function (string $method, string $url, array $options) {
            $this->assertSame('1.2.3.4', $options['resolve']['example.com'] ?? null);

            return new MockResponse('OK');
        });

        $client = new ResolverHttpClient($mockClient, $resolver);
        $response = $client->request('GET', 'http://example.com/');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('OK', $response->getContent());
        $this->assertContains('example.com', $resolvedHosts);
    }

    public function testResolverReturnsNullFallsBackToTransport()
    {
        $resolver = $this->createResolver(fn (string $host): ?string => null);

        $mockClient = new MockHttpClient(function (string $method, string $url, array $options) {
            $this->assertArrayNotHasKey('example.com', $options['resolve'] ?? []);

            return new MockResponse('OK');
        });

        $client = new ResolverHttpClient($mockClient, $resolver);
        $response = $client->request('GET', 'http://example.com/');

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testResolverIsCalledOnRedirect()
    {
        $resolvedHosts = [];
        $resolver = $this->createResolver(function (string $host) use (&$resolvedHosts): ?string {
            $resolvedHosts[] = $host;

            return '10.0.0.'.\count($resolvedHosts);
        });

        $responses = [
            new MockResponse('', [
                'http_code' => 302,
                'redirect_url' => 'http://other.example.com/target',
            ]),
            function (string $method, string $url, array $options) {
                $this->assertSame('10.0.0.2', $options['resolve']['other.example.com'] ?? null);

                return new MockResponse('Redirected');
            },
        ];

        $mockClient = new MockHttpClient($responses);
        $client = new ResolverHttpClient($mockClient, $resolver);
        $response = $client->request('GET', 'http://example.com/');

        $this->assertSame('Redirected', $response->getContent());
        $this->assertContains('example.com', $resolvedHosts);
        $this->assertContains('other.example.com', $resolvedHosts);
    }

    public function testResolverSkipsIpAddresses()
    {
        $resolverCalled = false;
        $resolver = $this->createResolver(function (string $host) use (&$resolverCalled): ?string {
            $resolverCalled = true;

            return null;
        });

        $mockClient = new MockHttpClient(new MockResponse('OK'));
        $client = new ResolverHttpClient($mockClient, $resolver);
        $response = $client->request('GET', 'http://93.184.216.34/');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertFalse($resolverCalled);
    }

    public function testResolverDoesNotOverrideExplicitResolve()
    {
        $resolverCalled = false;
        $resolver = $this->createResolver(function (string $host) use (&$resolverCalled): ?string {
            $resolverCalled = true;

            return '9.9.9.9';
        });

        $mockClient = new MockHttpClient(function (string $method, string $url, array $options) {
            $this->assertSame('5.5.5.5', $options['resolve']['example.com'] ?? null);

            return new MockResponse('OK');
        });

        $client = new ResolverHttpClient($mockClient, $resolver);
        $response = $client->request('GET', 'http://example.com/', [
            'resolve' => ['example.com' => '5.5.5.5'],
        ]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertFalse($resolverCalled);
    }

    private function createResolver(\Closure $callback): ResolverInterface
    {
        return new class($callback) implements ResolverInterface {
            public function __construct(private readonly \Closure $callback)
            {
            }

            public function resolve(string $host): ?string
            {
                return ($this->callback)($host);
            }
        };
    }
}

<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\HttpClient;

use Symfony\Component\HttpClient\Response\AsyncContext;
use Symfony\Component\HttpClient\Response\AsyncResponse;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResolverInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Decorator that resolves hostnames using a custom resolver before delegating to the transport.
 *
 * If the resolver returns an IP address, the result is injected into the "resolve" option so that
 * the underlying transport connects to that IP without performing its own DNS resolution.
 * If it returns null, the transport's default DNS resolution is used.
 *
 * This decorator intercepts redirects in order to resolve the new hostname before following them.
 */
final class ResolverHttpClient implements HttpClientInterface, ResetInterface
{
    use AsyncDecoratorTrait;
    use HttpClientTrait;

    private array $defaultOptions = self::OPTIONS_DEFAULTS;

    public function __construct(
        private HttpClientInterface $client,
        private readonly ResolverInterface $resolver,
    ) {
    }

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        [$url, $options] = self::prepareRequest($method, $url, $options, $this->defaultOptions, true);

        $host = parse_url($url['authority'], \PHP_URL_HOST);
        $url = implode('', $url);

        $this->doResolve($host, $options);

        if (0 >= $maxRedirects = $options['max_redirects']) {
            return new AsyncResponse($this->client, $method, $url, $options);
        }

        $options['max_redirects'] = 0;
        $redirectHeaders['with_auth'] = $redirectHeaders['no_auth'] = $options['headers'];
        $redirectHeaders['host'] = $host;
        $redirectHeaders['port'] = parse_url($url, \PHP_URL_PORT);

        if (isset($options['normalized_headers']['host']) || isset($options['normalized_headers']['authorization']) || isset($options['normalized_headers']['cookie'])) {
            $redirectHeaders['no_auth'] = array_filter($redirectHeaders['no_auth'], static fn ($h) => 0 !== stripos($h, 'Host:') && 0 !== stripos($h, 'Authorization:') && 0 !== stripos($h, 'Cookie:'));
        }

        $resolver = $this->resolver;

        return new AsyncResponse($this->client, $method, $url, $options, static function (ChunkInterface $chunk, AsyncContext $context) use (&$method, &$options, $maxRedirects, &$redirectHeaders, $resolver): \Generator {
            if (null !== $chunk->getError() || $chunk->isTimeout() || !$chunk->isFirst()) {
                yield $chunk;

                return;
            }

            $statusCode = $context->getStatusCode();

            if ($statusCode < 300 || 400 <= $statusCode || null === $url = $context->getInfo('redirect_url')) {
                $context->passthru();

                yield $chunk;

                return;
            }

            // Resolve the redirect target's hostname
            $host = parse_url($url, \PHP_URL_HOST);

            if (null !== $host && !isset($options['resolve'][$host]) && !filter_var(trim($host, '[]'), \FILTER_VALIDATE_IP)) {
                $ip = $resolver->resolve($host);

                if (null !== $ip) {
                    $options['resolve'][$host] = $ip;
                }
            }

            // Do like curl and browsers: turn POST to GET on 301, 302 and 303
            if (303 === $statusCode || 'POST' === $method && \in_array($statusCode, [301, 302], true)) {
                $method = 'HEAD' === $method ? 'HEAD' : 'GET';
                unset($options['body'], $options['json']);

                if (isset($options['normalized_headers']['content-length']) || isset($options['normalized_headers']['content-type']) || isset($options['normalized_headers']['transfer-encoding'])) {
                    $filterContentHeaders = static fn ($h) => 0 !== stripos($h, 'Content-Length:') && 0 !== stripos($h, 'Content-Type:') && 0 !== stripos($h, 'Transfer-Encoding:');
                    $options['headers'] = array_filter($options['headers'], $filterContentHeaders);
                    $redirectHeaders['no_auth'] = array_filter($redirectHeaders['no_auth'], $filterContentHeaders);
                    $redirectHeaders['with_auth'] = array_filter($redirectHeaders['with_auth'], $filterContentHeaders);
                }
            }

            // Authorization and Cookie headers MUST NOT follow except for the initial host name
            $port = parse_url($url, \PHP_URL_PORT);
            $options['headers'] = $redirectHeaders['host'] === $host && ($redirectHeaders['port'] ?? null) === $port ? $redirectHeaders['with_auth'] : $redirectHeaders['no_auth'];

            static $redirectCount = 0;
            $context->setInfo('redirect_count', ++$redirectCount);

            $context->replaceRequest($method, $url, $options);

            if ($redirectCount >= $maxRedirects) {
                $context->passthru();
            }
        });
    }

    public function withOptions(array $options): static
    {
        $clone = clone $this;
        $clone->client = $this->client->withOptions($options);
        $clone->defaultOptions = self::mergeDefaultOptions($options, $this->defaultOptions);

        return $clone;
    }

    private function doResolve(string $host, array &$options): void
    {
        if (isset($options['resolve'][$host])) {
            return;
        }

        if (filter_var(trim($host, '[]'), \FILTER_VALIDATE_IP)) {
            return;
        }

        $ip = $this->resolver->resolve($host);

        if (null !== $ip) {
            $options['resolve'][$host] = $ip;
        }
    }
}

<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Contracts\HttpClient;

/**
 * Resolves a hostname to an IP address.
 *
 * Implementations can use any DNS resolution strategy (e.g. custom DNS server,
 * service discovery, hosts file, etc.).
 */
interface ResolverInterface
{
    /**
     * Resolves the given hostname to an IP address.
     *
     * @return string|null The resolved IP address, or null to let the transport perform its default DNS resolution
     */
    public function resolve(string $host): ?string;
}

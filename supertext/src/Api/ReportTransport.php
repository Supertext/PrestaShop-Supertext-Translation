<?php

/**
 * @package     Supertext Translation for PrestaShop
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace Supertext\PrestaShop\Api;

use Supertext\PrestaShop\PluginHealth\Http\HttpRequest;
use Supertext\PrestaShop\PluginHealth\Http\HttpResponse;
use Supertext\PrestaShop\PluginHealth\Http\HttpTransport;
use Supertext\PrestaShop\PluginHealth\Http\TransportException;
use Supertext\PrestaShop\Sdk\Http\HttpRequest as SdkRequest;
use Supertext\PrestaShop\Sdk\Http\HttpTransport as SdkTransport;

/**
 * Sends Supertext Plugin Health's version reports through the SDK transport Reporting was given (a
 * fake in tests, so the key check and the reports share it). Both libraries have the same HTTP
 * shape; only the namespaces differ.
 */
final class ReportTransport implements HttpTransport
{
    public function __construct(private readonly SdkTransport $transport)
    {
    }

    public function send(HttpRequest $request): HttpResponse
    {
        try {
            $response = $this->transport->send(new SdkRequest($request->method, $request->url, $request->headers, $request->body, $request->timeout));
        } catch (\Throwable $e) {
            throw new TransportException($e->getMessage(), 0, $e);
        }

        return new HttpResponse($response->status, $response->body, $response->headers);
    }
}

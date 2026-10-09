<?php

declare(strict_types=1);

namespace Flames\Http\Handler;

use App\App;
use Flames\Http\Async\Response;
use Flames\Http\Exception\ConnectException;
use Flames\Http\Exception\RequestException;
use Flames\Http\Promise\Create;
use Flames\Http\Promise\FulfilledPromise;
use Flames\Http\Promise\PromiseInterface;
use Flames\Http\Psr\Http\Message\RequestInterface;
use Flames\Http\Psr\Http\Message\ResponseInterface;
use Flames\Http\TransferStats;
use Flames\Http\Utils;
use Flames\Js;

/**
 * Browser HTTP handler backed by synchronous XMLHttpRequest (Surface JS bridge).
 *
 * @final
 */
final class XhrHandler
{
    /** Headers the browser forbids scripts from setting on XMLHttpRequest. */
    private const array FORBIDDEN_XHR_HEADERS = [
        'accept-charset',
        'accept-encoding',
        'access-control-request-headers',
        'access-control-request-method',
        'connection',
        'content-length',
        'cookie',
        'cookie2',
        'date',
        'dnt',
        'expect',
        'host',
        'keep-alive',
        'origin',
        'referer',
        'te',
        'trailer',
        'transfer-encoding',
        'upgrade',
        'via',
        'user-agent',
    ];

    public function __invoke(RequestInterface $request, array $options): PromiseInterface
    {
        if (isset($options['delay'])) {
            \usleep((int) ($options['delay'] * 1000));
        }

        $startTime = isset($options['on_stats']) ? Utils::currentTime() : null;

        try {
            $response = $this->transfer($request, $options);
            $this->invokeStats($options, $request, $startTime, $response, null);

            return new FulfilledPromise($response);
        } catch (\Throwable $e) {
            $exception = $e instanceof ConnectException || $e instanceof RequestException
                ? $e
                : RequestException::wrapException($request, $e);
            $this->invokeStats($options, $request, $startTime, null, $exception);

            return Create::rejectionFor($exception);
        }
    }

    private function transfer(RequestInterface $request, array $options): ResponseInterface
    {
        if (!App::isClient()) {
            throw new \RuntimeException('XhrHandler is only available in the client module.');
        }

        $window = Js::getWindow();
        $xmlHttpRequest = $window->XMLHttpRequest;
        $xhr = new $xmlHttpRequest();

        $url = (string) $request->getUri();
        $xhr->open($request->getMethod(), $url, false);

        if (isset($options['timeout'])) {
            $this->applyXhrTimeout($xhr, (float) $options['timeout']);
        }

        foreach ($request->getHeaders() as $name => $values) {
            if (\in_array(\strtolower($name), self::FORBIDDEN_XHR_HEADERS, true)) {
                continue;
            }

            try {
                $xhr->setRequestHeader($name, \implode(', ', $values));
            } catch (\Throwable) {
            }
        }

        $body = (string) $request->getBody();

        try {
            if ($body === '') {
                $xhr->send();
            } else {
                $xhr->send($body);
            }
        } catch (\Throwable $e) {
            throw new ConnectException($e->getMessage(), $request, $e);
        }

        $status = (int) $xhr->status;
        if ($status === 0) {
            throw new ConnectException('Network error or CORS blocked the request', $request);
        }

        return new Response(
            $status,
            $this->parseHeaders((string) $xhr->getAllResponseHeaders()),
            (string) $xhr->responseText,
            '1.1',
            (string) $xhr->statusText
        );
    }

    /**
     * XHR.timeout must be a JS Number. Values assigned from PHP WASM often arrive as BigInt.
     * Embed milliseconds as a JS literal and set timeout entirely on the JS side.
     */
    private function applyXhrTimeout(object $xhr, float $timeoutSeconds): void
    {
        if ($timeoutSeconds <= 0) {
            return;
        }

        $timeoutMs = (int) \round($timeoutSeconds * 1000);
        if ($timeoutMs <= 0) {
            return;
        }

        $setTimeout = Js::eval(
            '(function(ms){ return function(xhr){ xhr.timeout = ms; }; })(' . $timeoutMs . ')'
        );

        if (\is_callable($setTimeout)) {
            $setTimeout($xhr);
        }
    }

    /** @return array<string, list<string>> */
    private function parseHeaders(string $raw): array
    {
        if ($raw === '') {
            return [];
        }

        $headers = [];
        foreach (\preg_split('/\r\n|\n|\r/', \trim($raw)) ?: [] as $line) {
            if ($line === '' || !\str_contains($line, ':')) {
                continue;
            }

            [$name, $value] = \explode(':', $line, 2);
            $name  = \trim($name);
            $value = \trim($value);
            if ($name === '') {
                continue;
            }

            $headers[$name][] = $value;
        }

        return $headers;
    }

    private function invokeStats(
        array $options,
        RequestInterface $request,
        ?float $startTime,
        ?ResponseInterface $response = null,
        ?\Throwable $error = null,
    ): void {
        if (!isset($options['on_stats']) || $startTime === null) {
            return;
        }

        $stats = new TransferStats(
            $request,
            $response,
            Utils::currentTime() - $startTime,
            $error,
            []
        );
        ($options['on_stats'])($stats);
    }
}

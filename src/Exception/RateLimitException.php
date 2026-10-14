<?php

namespace Depoto\Exception;

use DateTime;
use DateTimeZone;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * HTTP 429 Too Many Requests. When it is Depoto server's admission rejection (errors[].code RATE_LIMITED),
 * the request was not processed. A 429 from anything in front of the server (proxy, load balancer) gives
 * no such guarantee, the request may have been processed. Send the request again later, ideally after
 * getRetryAfter() seconds, and keep the caller idempotent.
 */
class RateLimitException extends ServerException
{
    protected ?int $retryAfter;

    public function __construct(RequestInterface $request, ResponseInterface $response, int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($request, $response, $code, $previous);

        $this->retryAfter = static::parseRetryAfter($response);
    }

    /**
     * Seconds to wait before sending the request again, taken from the Retry-After header
     * (an HTTP-date is counted from the moment this exception was created). Null when the header is missing or unparsable.
     */
    public function getRetryAfter(): ?int
    {
        return $this->retryAfter;
    }

    /**
     * Parses Retry-After header: delta-seconds or HTTP-date (RFC 9110, section 10.2.3).
     * HTTP-date is converted to seconds from $now (current time by default), a past date gives 0.
     */
    public static function parseRetryAfter(ResponseInterface $response, ?int $now = null): ?int
    {
        $value = trim($response->getHeaderLine('Retry-After'));
        if($value === '') {
            return null;
        }

        if(preg_match('/^\d+$/', $value)) {
            return static::clampSeconds((int)$value);
        }

        $date = static::parseHttpDate($value);
        if($date === null) {
            return null;
        }

        return static::clampSeconds(max(0, $date - ($now ?? time())));
    }

    /**
     * Caps absurdly large values at 2^31 - 1 seconds (cf. RFC 9111, section 1.2.2), so that converting
     * the result to milliseconds (e.g. a Messenger retry delay) still fits into a 64-bit integer.
     */
    protected static function clampSeconds(int $seconds): int
    {
        return min($seconds, 2147483647);
    }

    protected static function parseHttpDate(string $value): ?int
    {
        $formats = [
            'D, d M Y H:i:s \G\M\T', // IMF-fixdate: Sun, 06 Nov 1994 08:49:37 GMT
            'l, d-M-y H:i:s \G\M\T', // obsolete RFC 850: Sunday, 06-Nov-94 08:49:37 GMT
            'D M j H:i:s Y', // obsolete asctime: Sun Nov  6 08:49:37 1994
        ];
        $value = preg_replace('/\s+/', ' ', $value);

        foreach($formats as $format) {
            $date = DateTime::createFromFormat('!'.$format, $value, new DateTimeZone('UTC'));
            if($date === false) {
                continue;
            }

            // createFromFormat() silently rolls over overflowing values such as 32 Oct and moves the date
            // to the next matching weekday when the day name does not fit the date. Accept the value only
            // when the parsed date formats back to exactly the same string.
            if($date->format($format) !== $value) {
                continue;
            }

            return $date->getTimestamp();
        }

        return null;
    }
}

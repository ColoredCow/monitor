<?php

namespace App\Exceptions;

use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Notifications\Notification;
use RuntimeException;

/**
 * An alert channel's HTTP call failed.
 *
 * Guzzle's own message quotes the full request URL, and for a webhook the URL
 * is the secret. This message names only the channel, the organization and
 * the status, and the original exception is deliberately not chained.
 */
class AlertChannelFailed extends RuntimeException
{
    public static function from(string $channel, Notification $notification, GuzzleException $exception): self
    {
        $organizationId = method_exists($notification, 'getMonitor')
            ? $notification->getMonitor()->organization_id
            : null;

        $status = $exception instanceof RequestException
            ? $exception->getResponse()?->getStatusCode()
            : null;

        return new self(sprintf(
            'Uptime alert via %s failed for organization %s (%s).',
            class_basename($channel),
            $organizationId ?? 'unknown',
            $status ? "HTTP {$status}" : class_basename($exception),
        ));
    }
}

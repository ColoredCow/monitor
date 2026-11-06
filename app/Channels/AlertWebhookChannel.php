<?php

namespace App\Channels;

use NotificationChannels\Webhook\WebhookChannel;
use Psr\Http\Message\ResponseInterface;

abstract class AlertWebhookChannel extends WebhookChannel
{
    /**
     * Post to a webhook with the options every alert channel shares.
     *
     * "verify" is pinned to true on purpose: WebhookMessage defaults it to
     * false, which skips TLS checks, so a message-level value is ignored.
     * The timeout keeps a dead chat service from holding up a check run.
     */
    protected function post(string $url, array $options): ResponseInterface
    {
        return $this->client->post($url, $options + [
            'verify' => true,
            'timeout' => 10,
        ]);
    }
}

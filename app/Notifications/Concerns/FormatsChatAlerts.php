<?php

namespace App\Notifications\Concerns;

use NotificationChannels\Webhook\WebhookMessage;

/**
 * Builds the Google Chat and Slack payloads for an uptime alert.
 *
 * The alert text can quote the monitored site's response, so each chat's
 * mention syntax is neutralized before it is sent.
 */
trait FormatsChatAlerts
{
    public function toGoogleChat($notifiable)
    {
        // Google Chat turns "<users/all>" into an @mention of everyone and has
        // no documented escape. A zero-width space after "<" keeps quoted text
        // from ever forming a mention, without changing how it reads.
        return WebhookMessage::create()
            ->data([
                'text' => str_replace('<', "<\u{200B}", $this->getAlertText()),
            ]);
    }

    public function toOrganizationSlack($notifiable)
    {
        // Slack reads <, > and & as control characters ("<!channel>" pings
        // everyone) and documents escaping them as HTML entities.
        return WebhookMessage::create()
            ->data([
                'text' => str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $this->getAlertText()),
            ]);
    }

    protected function getAlertText(): string
    {
        return $this->getMessageText();
    }
}

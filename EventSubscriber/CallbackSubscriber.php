<?php

declare(strict_types=1);

namespace MauticPlugin\SparkpostBundle\EventSubscriber;

use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\EmailBundle\EmailEvents;
use Mautic\EmailBundle\Event\TransportWebhookEvent;
use Mautic\EmailBundle\Model\TransportCallback;
use Mautic\LeadBundle\Entity\DoNotContact;
use MauticPlugin\SparkpostBundle\Mailer\Transport\SparkpostTransport;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Transport\Dsn;

class CallbackSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private TransportCallback $transportCallback,
        private CoreParametersHelper $coreParametersHelper,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            EmailEvents::ON_TRANSPORT_WEBHOOK => 'processCallbackRequest',
        ];
    }

    public function processCallbackRequest(TransportWebhookEvent $event): void
    {
        $dsn = Dsn::fromString($this->coreParametersHelper->get('mailer_dsn'));

        if (SparkpostTransport::MAUTIC_SPARKPOST_API_SCHEME !== $dsn->getScheme()) {
            return;
        }

        $payload = $event->getRequest()->request->all();

        foreach ($payload as $msys) {
            $msys         = $msys['msys'] ?? null;
            $messageEvent = $msys['message_event'] ?? $msys['unsubscribe_event'] ?? null;

            if (!$messageEvent) {
                continue;
            }

            if (isset($messageEvent['rcpt_type']) && 'to' !== $messageEvent['rcpt_type']) {
                // Ignore cc/bcc
                continue;
            }

            $type        = $messageEvent['type'] ?? null;
            $bounceClass = $messageEvent['bounce_class'] ?? null;

            if (('bounce' === $type && !in_array((int) $bounceClass, [10, 25, 26, 30, 90]))
                || ('out_of_band' === $type && 60 === (int) $bounceClass)
            ) {
                // Only parse hard bounces - https://support.sparkpost.com/docs/deliverability/bounce-classification-codes
                continue;
            }

            $hashId = $messageEvent['rcpt_meta']['hashId'] ?? null;

            if ($hashId) {
                $this->processCallbackByHashId($hashId, $messageEvent);

                continue;
            }

            $rcptTo = $messageEvent['rcpt_to'] ?? '';
            $this->processCallbackByEmailAddress($rcptTo, $messageEvent);
        }

        $event->setResponse(new Response('Callback processed'));
    }

    /**
     * @param string       $hashId
     * @param array<mixed> $event
     */
    private function processCallbackByHashId($hashId, array $event): void
    {
        $type = $event['type'] ?? null;

        switch ($type) {
            case 'policy_rejection':
            case 'out_of_band':
            case 'bounce':
                $rawReason = $event['raw_reason'] ?? '';
                $this->transportCallback->addFailureByHashId($hashId, $rawReason);
                break;
            case 'spam_complaint':
                $fbType = $event['fbtype'] ?? '';
                $this->transportCallback->addFailureByHashId($hashId, $fbType, DoNotContact::UNSUBSCRIBED);
                break;
            case 'list_unsubscribe':
            case 'link_unsubscribe':
                $this->transportCallback->addFailureByHashId($hashId, 'unsubscribed', DoNotContact::UNSUBSCRIBED);
                break;
            default:
                break;
        }
    }

    /**
     * @param string       $email
     * @param array<mixed> $event
     */
    private function processCallbackByEmailAddress($email, array $event): void
    {
        $type = $event['type'] ?? null;

        switch ($type) {
            case 'policy_rejection':
            case 'out_of_band':
            case 'bounce':
                $rawReason = $event['raw_reason'] ?? '';
                $this->transportCallback->addFailureByAddress($email, $rawReason);
                break;
            case 'spam_complaint':
                $fbType = $event['fbtype'] ?? '';
                $this->transportCallback->addFailureByAddress($email, $fbType, DoNotContact::UNSUBSCRIBED);
                break;
            case 'list_unsubscribe':
            case 'link_unsubscribe':
                $this->transportCallback->addFailureByAddress($email, 'unsubscribed', DoNotContact::UNSUBSCRIBED);
                break;
            default:
                break;
        }
    }
}

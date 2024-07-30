<?php

declare(strict_types=1);

namespace MauticPlugin\SparkpostBundle\Mailer\Transport;

use Mautic\EmailBundle\Helper\MailHelper;
use Mautic\EmailBundle\Mailer\Message\MauticMessage;
use Mautic\EmailBundle\Mailer\Transport\TokenTransportInterface;
use Mautic\EmailBundle\Mailer\Transport\TokenTransportTrait;
use Mautic\EmailBundle\Model\TransportCallback;
use Mautic\LeadBundle\Entity\DoNotContact;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\HttpTransportException;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractApiTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Header\ParameterizedHeader;
use Symfony\Component\Mime\Header\UnstructuredHeader;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class SparkpostTransport extends AbstractApiTransport implements TokenTransportInterface
{
    use TokenTransportTrait;

    public const MAUTIC_SPARKPOST_API_SCHEME = 'mautic+sparkpost+api';

    public const SPARK_POST_HOSTS = ['us' => 'api.sparkpost.com', 'eu' => 'api.eu.sparkpost.com'];

    private const STD_HEADER_KEYS = [
        'MIME-Version',
        'received',
        'dkim-signature',
        'Content-Type',
        'Content-Transfer-Encoding',
        'To',
        'From',
        'Subject',
        'Reply-To',
        'CC',
        'BCC',
    ];

    public function __construct(
        private string $apiKey,
        string $region,
        private TransportCallback $callback,
        HttpClientInterface $client = null,
        EventDispatcherInterface $dispatcher = null,
        LoggerInterface $logger = null
    ) {
        parent::__construct($client, $dispatcher, $logger);
        $this->host = self::SPARK_POST_HOSTS[$region] ?? self::SPARK_POST_HOSTS['us'];
    }

    public function __toString(): string
    {
        return sprintf(self::MAUTIC_SPARKPOST_API_SCHEME.'://%s', $this->host);
    }

    /**
     * @throws ClientExceptionInterface
     * @throws DecodingExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ServerExceptionInterface
     * @throws TransportException
     */
    protected function doSendApi(SentMessage $sentMessage, Email $email, Envelope $envelope): ResponseInterface
    {
        try {
            $payload = $this->getSparkpostPayload($sentMessage);
            $this->checkTemplateIsValid($payload);
            $response = $this->getSparkpostResponse('transmissions', $payload);
            $this->handleError($response);

            if ($errorMessage = $this->getErrorMessageFromResponseBody($response->toArray())) {
                /** @var MauticMessage $message */
                $message = $sentMessage->getOriginalMessage();
                $this->processImmediateSendFeedback($payload, $response->toArray(), $message->getMetadata());
                throw new TransportException($errorMessage);
            }

            return $response;
        } catch (TransportExceptionInterface $e) {
            throw new TransportException($e->getMessage(), 0, $e);
        }
    }

    /**
     * @return array<mixed>
     */
    private function getSparkpostPayload(SentMessage $message): array
    {
        $email = $message->getOriginalMessage();

        if (!$email instanceof MauticMessage) {
            throw new TransportException('Message must be an instance of '.MauticMessage::class);
        }

        $metadata    = $email->getMetadata();
        $mergeVars   = [];
        $metadataSet = [];

        // Sparkpost uses {{ name }} for tokens so Mautic's need to be converted;
        // although using their {{{ }}} syntax to prevent HTML escaping
        if (!empty($metadata)) {
            $metadataSet  = reset($metadata);
            $tokens       = (!empty($metadataSet['tokens'])) ? $metadataSet['tokens'] : [];
            $mauticTokens = array_keys($tokens);

            $mergeVarPlaceholders = [];

            foreach ($mauticTokens as $token) {
                $mergeVars[$token]            = strtoupper(preg_replace('/[^a-z0-9]+/i', '', $token));
                $mergeVarPlaceholders[$token] = '{{{ '.$mergeVars[$token].' }}}';
            }

            if (!empty($mauticTokens)) {
                MailHelper::searchReplaceTokens($mauticTokens, $mergeVarPlaceholders, $email);
            }
        }

        return [
            'content'     => $this->buildContent($email),
            'recipients'  => $this->buildRecipients($email, $metadata, $mergeVars),
            'inline_css'  => $email->getHeaders()->get('X-MC-InlineCSS')
                ? $email->getHeaders()->get('X-MC-InlineCSS')->getBody()
                : null,
            'tags'        => $email->getHeaders()->get('X-MC-Tags')
                ? $email->getHeaders()->get('X-MC-Tags')->getBody()
                : [],
            'campaign_id' => $this->getCampaignId($metadata, $metadataSet),
            'options'     => [
                'open_tracking'  => false,
                'click_tracking' => false,
                'transactional'  => !$email->getHeaders()->get('List-Unsubscribe-Post'),
            ],
        ];
    }

    /**
     * @return array<mixed>
     */
    private function buildContent(MauticMessage $message): array
    {
        $fromAddress = current($message->getFrom());
        $replyTo     = current($message->getReplyTo()) ?: $fromAddress;

        return [
            'from'        => !empty($fromAddress->getName())
                ? $fromAddress->getName().' <'.$fromAddress->getAddress().'>'
                : $fromAddress->getAddress(),
            'subject'     => $message->getSubject(),
            'headers'     => $this->buildHeaders($message) ?: new \stdClass(),
            'html'        => $message->getHtmlBody(),
            'text'        => $message->getTextBody(),
            'reply_to'    => $replyTo->getAddress(),
            'attachments' => $this->buildAttachments($message),
        ];
    }

    /**
     * @return array<mixed>
     */
    private function buildHeaders(MauticMessage $message): array
    {
        $result  = [];
        $headers = $message->getHeaders()->all();

        foreach ($headers as $header) {
            if ($header instanceof UnstructuredHeader && !in_array($header->getName(), self::STD_HEADER_KEYS)) {
                $result[$header->getName()] = $header->getBody();
            }
        }

        return $result;
    }

    /**
     * @return array<mixed>
     */
    private function buildAttachments(MauticMessage $message): array
    {
        $result = [];

        foreach ($message->getAttachments() as $attachment) {
            /** @var ParameterizedHeader $file */
            $file = $attachment->getPreparedHeaders()->get('Content-Disposition');
            /** @var ParameterizedHeader $type */
            $type = $attachment->getPreparedHeaders()->get('Content-Type');

            $result[] = [
                'name' => $file->getParameter('filename'),
                'type' => $type->getValue(),
                'data' => base64_encode($attachment->getBody()),
            ];
        }

        return $result;
    }

    /**
     * @param array<mixed> $metadata
     * @param array<mixed> $mergeVars
     *
     * @return array<mixed>
     */
    private function buildRecipients(MauticMessage $message, array $metadata, array $mergeVars): array
    {
        $recipients = [];

        foreach ($message->getTo() as $to) {
            $recipient    = $this->buildRecipient($to, $metadata, $mergeVars);
            $recipients[] = $recipient;

            // CC and BCC fields need to be included as a normal TO address with token duplication
            // https://www.sparkpost.com/docs/faq/cc-bcc-with-rest-api/ - token duplication is not mentioned here
            // See test for CC and BCC too
            foreach ($message->getCc() as $cc) {
                $recipients[] = $this->buildCopyRecipient($to, $cc, $recipient);
            }

            foreach ($message->getBcc() as $bcc) {
                $recipients[] = $this->buildCopyRecipient($to, $bcc, $recipient);
            }
        }

        return $recipients;
    }

    /**
     * @param array<mixed> $metadata
     * @param array<mixed> $mergeVars
     *
     * @return array<mixed>
     */
    private function buildRecipient(Address $to, array $metadata, array $mergeVars): array
    {
        $recipient = [
            'address'           => [
                'email' => $to->getAddress(),
                'name'  => $to->getName(),
            ],
            'substitution_data' => [],
            'metadata'          => [],
        ];

        $email = $to->getAddress();

        if (isset($metadata[$email]['tokens'])) {
            foreach ($metadata[$email]['tokens'] as $token => $value) {
                $recipient['substitution_data'][$mergeVars[$token]] = $value;
            }

            unset($metadata[$email]['tokens']);
            $recipient['metadata'] = $metadata[$email];
        }

        // Sparkpost requires substitution_data which can be by-passed by using
        // MailHelper::setTo() rather than a Lead via MailHelper::setLead()
        // Without it, Sparkpost returns the error: "field 'substitution_data' is required"
        // But, it can't be an empty array or Sparkpost will return error: field 'substitution_data'
        // is of type 'json', but needs to be of type 'json_object'
        if (empty($recipient['substitution_data'])) {
            $recipient['substitution_data'] = new \stdClass();
        }

        // Sparkpost doesn't like empty metadata
        if (empty($recipient['metadata'])) {
            unset($recipient['metadata']);
        }

        return $recipient;
    }

    /**
     * @param array<mixed> $recipient
     *
     * @return array<mixed>
     */
    private function buildCopyRecipient(Address $to, Address $copy, array $recipient): array
    {
        $copyRecipient = [
            'address'   => ['email' => $copy->getAddress()],
            'header_to' => $to->getAddress(),
        ];

        if (!empty($recipient['substitution_data'])) {
            $copyRecipient['substitution_data'] = $recipient['substitution_data'];
        }

        return $copyRecipient;
    }

    /**
     * @param array<mixed> $metadata
     * @param array<mixed> $metadataSet
     */
    private function getCampaignId(array $metadata, array $metadataSet): string
    {
        $campaignId = '';

        if (!empty($metadata)) {
            $id = '';

            if (!empty($metadataSet['utmTags']['utmCampaign'])) {
                $id = $metadataSet['utmTags']['utmCampaign'];
            } elseif (!empty($metadataSet['emailId']) && !empty($metadataSet['emailName'])) {
                $id = $metadataSet['emailId'].':'.$metadataSet['emailName'];
            } elseif (!empty($metadataSet['emailId'])) {
                $id = $metadataSet['emailId'];
            }

            $campaignId = mb_strcut($id, 0, 64);
        }

        return $campaignId;
    }

    /**
     * @param array<mixed> $payload
     *
     * @throws ClientExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ServerExceptionInterface
     * @throws TransportExceptionInterface
     */
    private function checkTemplateIsValid(array $payload): void
    {
        // Take substitution_data from the first recipient.
        if (
            empty($payload['substitution_data'])
            && isset($payload['recipients'][0]['substitution_data'])
        ) {
            $payload['substitution_data'] = $payload['recipients'][0]['substitution_data'];
            unset($payload['recipients']);
        }

        $response = $this->getSparkpostResponse('utils/content-previewer', $payload);

        if (403 === $response->getStatusCode()) {
            // We cannot fail as it would be a BC break. Throw a warning and continue.
            $this->getLogger()->warning(
                'The permission "Templates: Preview" is not enabled. '.
                'Enable it to let Mautic check email template validity before send.'
            );
        }

        $this->handleError($response);
    }

    /**
     * @param array<mixed> $payload
     *
     * @throws TransportExceptionInterface
     */
    private function getSparkpostResponse(
        string $endpoint,
        array $payload,
        string $method = Request::METHOD_POST
    ): ResponseInterface {
        return $this->client->request(
            $method,
            sprintf('https://%1$s/api/v1/%2$s/', $this->host, $endpoint),
            [
                'headers' => [
                    'Authorization' => $this->apiKey,
                    'Content-Type'  => 'application/json',
                ],
                'json'    => $payload,
            ]
        );
    }

    /**
     * @throws ClientExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ServerExceptionInterface
     * @throws TransportExceptionInterface
     */
    private function handleError(ResponseInterface $response): void
    {
        if (200 === $response->getStatusCode()) {
            return;
        }

        $data = json_decode($response->getContent(false), true);
        $this->getLogger()->error('SparkpostApiTransport error response', $data);

        throw new HttpTransportException(json_encode($data['errors']), $response, $response->getStatusCode());
    }

    /**
     * @param array<mixed> $response
     */
    private function getErrorMessageFromResponseBody(array $response): string
    {
        return $response['errors'][0]['description'] ?? $response['errors'][0]['message'] ?? '';
    }

    /**
     * @param array<mixed> $message
     * @param array<mixed> $response
     * @param array<mixed> $metadata
     */
    private function processImmediateSendFeedback(array $message, array $response, array $metadata): void
    {
        if (!empty($response['errors'][0]['code']) && 1902 === (int) $response['errors'][0]['code']) {
            $comments     = $this->getErrorMessageFromResponseBody($response);
            $emailAddress = $message['recipients'][0]['address']['email'];

            if (isset($metadata[$emailAddress]['leadId'])) {
                $emailId = $metadata[$emailAddress]['emailId'] ?? null;
                $this->callback->addFailureByContactId(
                    $metadata[$emailAddress]['leadId'],
                    $comments,
                    DoNotContact::BOUNCED,
                    $emailId
                );
            }
        }
    }

    public function getMaxBatchLimit(): int
    {
        return 5000;
    }
}

<?php

declare(strict_types=1);

namespace MauticPlugin\SparkpostBundle\Tests\Functional\EventSubscriber;

use Mautic\CoreBundle\Helper\DateTimeHelper;
use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\EmailBundle\Entity\Stat;
use Mautic\LeadBundle\Entity\DoNotContact;
use Mautic\LeadBundle\Entity\Lead;
use PHPUnit\Framework\Assert;
use Symfony\Component\HttpFoundation\Request;

class CallbackSubscriberTest extends MauticMysqlTestCase
{
    protected function setUp(): void
    {
        if ('testSparkpostTransportNotConfigured' !== $this->getName()) {
            $this->configParams['mailer_dsn'] = 'mautic+sparkpost+api://:some_api@some_host:25?region=us';
        }

        parent::setUp();
    }

    public function testSparkpostTransportNotConfigured(): void
    {
        $this->client->request(Request::METHOD_POST, '/mailer/callback');
        $response = $this->client->getResponse();
        Assert::assertSame('No email transport that could process this callback was found', $response->getContent());
        Assert::assertSame(404, $response->getStatusCode());
    }

    /**
     * @dataProvider provideMessageEventType
     */
    public function testSparkpostCallbackProcessByHashId(string $type, string $bounceClass): void
    {
        $parameters                                          = $this->getParameters($type, $bounceClass);
        $parameters[0]['msys']['message_event']['rcpt_meta'] = ['hashId' => '65763254757234'];

        $contact      = $this->createContact('contact@an.email');
        $now          = new \DateTime();
        $stat         = $this->createStat($contact, '65763254757234', 'contact@an.email', $now);
        $this->em->flush();

        $this->client->request(Request::METHOD_POST, '/mailer/callback', $parameters);
        $response = $this->client->getResponse();
        Assert::assertSame('Callback processed', $response->getContent());
        Assert::assertSame(200, $response->getStatusCode());

        // Only parse hard bounces
        if ('bounce' !== $type && '25' !== $bounceClass) {
            $result = $this->getCommentAndReason($type);

            $openDetails = $stat->getOpenDetails();
            $bounces     = $openDetails['bounces'][0];
            Assert::assertSame($now->format(DateTimeHelper::FORMAT_DB), $bounces['datetime']);
            Assert::assertSame($result['comments'], $bounces['reason']);

            $dnc = $contact->getDoNotContact()->current();
            Assert::assertSame('email', $dnc->getChannel());
            Assert::assertSame($result['comments'], $dnc->getComments());
            Assert::assertSame($now->format(DateTimeHelper::FORMAT_DB), $dnc->getDateAdded()->format(DateTimeHelper::FORMAT_DB));
            Assert::assertSame($contact, $dnc->getLead());
            Assert::assertSame($result['reason'], $dnc->getReason());
        }
    }

    /**
     * @dataProvider provideMessageEventType
     */
    public function testSparkpostCallbackProcessByEmailAddress(string $type, string $bounceClass): void
    {
        $parameters = $this->getParameters($type, $bounceClass);

        $contact = $this->createContact('recipient@example.com');
        $this->em->flush();

        $now          = new \DateTime();
        $nowFormatted = $now->format(DateTimeHelper::FORMAT_DB);

        $this->client->request(Request::METHOD_POST, '/mailer/callback', $parameters);
        $response = $this->client->getResponse();
        Assert::assertSame('Callback processed', $response->getContent());
        Assert::assertSame(200, $response->getStatusCode());

        // Only parse hard bounces
        if ('bounce' !== $type && '25' !== $bounceClass) {
            $result = $this->getCommentAndReason($type);

            $dnc = $contact->getDoNotContact()->current();
            Assert::assertSame('email', $dnc->getChannel());
            Assert::assertSame($result['comments'], $dnc->getComments());
            Assert::assertSame($nowFormatted, $dnc->getDateAdded()->format(DateTimeHelper::FORMAT_DB));
            Assert::assertSame($contact, $dnc->getLead());
            Assert::assertSame($result['reason'], $dnc->getReason());
        }
    }

    /**
     * @return array<mixed>
     */
    public function provideMessageEventType(): iterable
    {
        yield ['policy_rejection', '25'];
        yield ['out_of_band', '25'];
        yield ['bounce', '25'];
        yield ['bounce', '10'];
        yield ['spam_complaint', '25'];
        yield ['list_unsubscribe', '25'];
        yield ['link_unsubscribe', '25'];
    }

    /**
     * @return array<mixed>
     */
    private function getParameters(string $type, string $bounceClass): array
    {
        return [
            [
                'msys' => [
                    'message_event' => [
                        'type'             => $type,
                        'campaign_id'      => 'Example Campaign Name',
                        'customer_id'      => '1',
                        'error_code'       => '554',
                        'event_id'         => '92356927693813856',
                        'friendly_from'    => 'sender@example.com',
                        'message_id'       => '000443ee14578172be22',
                        'msg_from'         => 'sender@example.com',
                        'rcpt_tags'        => [
                            'male',
                            'US',
                        ],
                        'rcpt_to'          => 'recipient@example.com',
                        'raw_rcpt_to'      => 'recipient@example.com',
                        'rcpt_type'        => 'to',
                        'raw_reason'       => 'MAIL REFUSED - IP (19.99.99.99) is in black list',
                        'reason'           => 'MAIL REFUSED - IP (a.b.c.d) is in black list',
                        'remote_addr'      => '127.0.0.1',
                        'subaccount_id'    => '101',
                        'template_id'      => 'templ-1234',
                        'template_version' => '1',
                        'timestamp'        => '1454442600',
                        'transmission_id'  => '65832150921904138',
                        'bounce_class'     => $bounceClass,
                    ],
                ],
            ],
        ];
    }

    private function createContact(string $email): Lead
    {
        $lead = new Lead();
        $lead->setEmail($email);

        $this->em->persist($lead);

        return $lead;
    }

    private function createStat(Lead $contact, string $trackingHash, string $emailAddress, \DateTime $dateSent): Stat
    {
        $stat = new Stat();
        $stat->setLead($contact);
        $stat->setTrackingHash($trackingHash);
        $stat->setEmailAddress($emailAddress);
        $stat->setDateSent($dateSent);

        $this->em->persist($stat);

        return $stat;
    }

    /**
     * @return array<mixed>
     */
    private function getCommentAndReason(string $type): array
    {
        return match ($type) {
            'policy_rejection', 'out_of_band', 'bounce' => [
                'comments' => 'MAIL REFUSED - IP (19.99.99.99) is in black list',
                'reason'   => DoNotContact::BOUNCED,
            ],
            'spam_complaint'                            => [
                'comments' => '',
                'reason'   => DoNotContact::UNSUBSCRIBED,
            ],
            'list_unsubscribe', 'link_unsubscribe'      => [
                'comments' => 'unsubscribed',
                'reason'   => DoNotContact::UNSUBSCRIBED,
            ],
            default                                     => [
                'comments' => '',
                'reason'   => '',
            ],
        };
    }
}

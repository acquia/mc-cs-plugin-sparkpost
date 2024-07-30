<?php

declare(strict_types=1);

namespace MauticPlugin\SparkpostBundle\Tests\Functional\Mailer\Transport;

use Mautic\CoreBundle\Helper\UserHelper;
use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\EmailBundle\Entity\Email;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Entity\LeadList;
use Mautic\LeadBundle\Entity\ListLead;
use PHPUnit\Framework\Assert;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class SparkpostTransportTest extends MauticMysqlTestCase
{
    private TranslatorInterface $translator;

    protected function setUp(): void
    {
        $this->configParams['mailer_dsn']            = 'mautic+sparkpost+api://:some_api@some_host:25?region=us';
        $this->configParams['messenger_dsn_email']   = 'sync://';
        $this->configParams['mailer_custom_headers'] = ['x-global-custom-header' => 'value123'];
        $this->configParams['mailer_from_email']     = 'admin@mautic.test';
        $this->configParams['mailer_from_name']      = 'Admin';
        parent::setUp();
        $this->translator = self::getContainer()->get('translator');
    }

    public function testEmailSendToContactSync(): void
    {
        $expectedResponses = [
            function ($method, $url, $options): MockResponse {
                Assert::assertSame(Request::METHOD_POST, $method);
                Assert::assertSame('https://api.sparkpost.com/api/v1/utils/content-previewer/', $url);
                $this->assertSparkpostRequestBody($options['body']);

                return new MockResponse('{"results": {"subject": "Hello there!", "html": "This is test body for {contactfield=email}!"}}');
            },
            function ($method, $url, $options): MockResponse {
                Assert::assertSame(Request::METHOD_POST, $method);
                Assert::assertSame('https://api.sparkpost.com/api/v1/transmissions/', $url);
                $this->assertSparkpostRequestBody($options['body']);

                return new MockResponse('{"results": {"total_rejected_recipients": 0, "total_accepted_recipients": 1, "id": "11668787484950529"}}');
            },
        ];

        /** @var MockHttpClient $mockHttpClient */
        $mockHttpClient = self::getContainer()->get(HttpClientInterface::class);
        $mockHttpClient->setResponseFactory($expectedResponses);

        $contact = $this->createContact('contact@an.email');
        $this->em->flush();

        $this->client->request(Request::METHOD_GET, "/s/contacts/email/{$contact->getId()}");
        Assert::assertTrue($this->client->getResponse()->isOk());
        $newContent = json_decode($this->client->getResponse()->getContent(), true)['newContent'];
        $crawler    = new Crawler($newContent, $this->client->getInternalRequest()->getUri());
        $form       = $crawler->selectButton('Send')->form();
        $form->setValues(
            [
                'lead_quickemail[subject]' => 'Hello there!',
                'lead_quickemail[body]'    => 'This is test body for {contactfield=email}!',
            ]
        );
        $this->client->submit($form);
        Assert::assertTrue($this->client->getResponse()->isOk());
        self::assertQueuedEmailCount(1);

        $email      = self::getMailerMessage();
        $userHelper = static::getContainer()->get(UserHelper::class);
        $user       = $userHelper->getUser();

        Assert::assertSame('Hello there!', $email->getSubject());
        Assert::assertStringContainsString('This is test body for {contactfield=email}!', $email->getHtmlBody());
        Assert::assertSame('This is test body for {contactfield=email}!', $email->getTextBody());
        /** @phpstan-ignore-next-line */
        Assert::assertSame('contact@an.email', $email->getMetadata()['contact@an.email']['tokens']['{contactfield=email}']);
        Assert::assertCount(1, $email->getFrom());
        Assert::assertSame($user->getName(), $email->getFrom()[0]->getName());
        Assert::assertSame($user->getEmail(), $email->getFrom()[0]->getAddress());
        Assert::assertCount(1, $email->getTo());
        Assert::assertSame('', $email->getTo()[0]->getName());
        Assert::assertSame($contact->getEmail(), $email->getTo()[0]->getAddress());
        Assert::assertCount(1, $email->getReplyTo());
        Assert::assertSame('', $email->getReplyTo()[0]->getName());
    }

    public function testSegmentEmailSendToCoupleOfContactSync(): void
    {
        $segment = new LeadList();
        $segment->setName('Test Segment');
        $segment->setPublicName('Test Segment');
        $segment->setAlias('test-segment');

        $email = new Email();
        $email->setName('Test Email');
        $email->setSubject('Hello there!');
        $email->setEmailType('list');
        $email->setLists([$segment]);
        $email->setCustomHtml('<html><body>Hello {contactfield=email}!</br>{unsubscribe_text}</body></html>');

        $this->em->persist($segment);
        $this->em->persist($email);
        $this->em->flush();

        $email->setPlainText('Dear {contactfield=email}');
        $email->setFromAddress('custom@from.address');
        $email->setFromName('Custom From Name');
        $email->setReplyToAddress('custom@replyto.address');
        $email->setBccAddress('custom@bcc.email');
        $email->setHeaders(['x-global-custom-header' => 'value123 overridden']);
        $email->setUtmTags(
            [
                'utmSource'   => 'utmSourceA',
                'utmMedium'   => 'utmMediumA',
                'utmCampaign' => 'utmCampaignA',
                'utmContent'  => 'utmContentA',
            ]
        );

        foreach (['contact@one.email', 'contact@two.email'] as $emailAddress) {
            $contact = new Lead();
            $contact->setEmail($emailAddress);

            $member = new ListLead();
            $member->setLead($contact);
            $member->setList($segment);
            $member->setDateAdded(new \DateTime());

            $this->em->persist($member);
            $this->em->persist($contact);
        }

        $this->em->persist($segment);
        $this->em->persist($email);
        $this->em->flush();

        $assertRecipient = function (array $recipient, string $contactAddressWithoutDotPart, string $recipientAddressWithoutDotPart, Email $email): void {
            // Address
            $this->assertSame($recipientAddressWithoutDotPart.'.email', $recipient['address']['email']);

            if ($contactAddressWithoutDotPart === $recipientAddressWithoutDotPart) {
                // This is for the contact
                $this->assertSame('', $recipient['address']['name']);

                // Metadata
                $this->assertSame(' ', $recipient['metadata']['name']);
                $this->assertMatchesRegularExpression('/\d+/', $recipient['metadata']['leadId']);
                $this->assertSame($email->getId(), $recipient['metadata']['emailId']);
                $this->assertSame('Test Email', $recipient['metadata']['emailName']);
                $this->assertMatchesRegularExpression('/[a-f0-9]{20,40}/', $recipient['metadata']['hashId']);
                $this->assertTrue($recipient['metadata']['hashIdState']);
                $this->assertSame(['email', $email->getId()], $recipient['metadata']['source']);
                $this->assertSame('utmSourceA', $recipient['metadata']['utmTags']['utmSource']);
                $this->assertSame('utmMediumA', $recipient['metadata']['utmTags']['utmMedium']);
                $this->assertSame('utmCampaignA', $recipient['metadata']['utmTags']['utmCampaign']);
                $this->assertSame('utmContentA', $recipient['metadata']['utmTags']['utmContent']);
            } else {
                // This is for the BCC
                $this->assertSame($contactAddressWithoutDotPart.'.email', $recipient['header_to']);
            }

            // Substitution Data
            $this->assertSame('Default Dynamic Content', $recipient['substitution_data']['DYNAMICCONTENTDYNAMICCONTENT1']);
            $this->assertMatchesRegularExpression(
                '/<a href="https:\/\/localhost\/email\/unsubscribe\/[a-f0-9]{20,40}\/'.$contactAddressWithoutDotPart.'\.email\/[a-f0-9]*">Unsubscribe<\/a> to no longer receive emails from us./',
                $recipient['substitution_data']['UNSUBSCRIBETEXT']
            );
            $this->assertMatchesRegularExpression(
                '/https:\/\/localhost\/email\/unsubscribe\/[a-f0-9]{20,40}\/'.$contactAddressWithoutDotPart.'\.email\/[a-f0-9]*/',
                $recipient['substitution_data']['UNSUBSCRIBEURL']
            );
            $this->assertMatchesRegularExpression(
                '/<a href="https:\/\/localhost\/email\/view\/[a-f0-9]{20,40}">Having trouble reading this email\? Click here.<\/a>/',
                $recipient['substitution_data']['WEBVIEWTEXT']
            );
            $this->assertMatchesRegularExpression(
                '/https:\/\/localhost\/email\/view\/[a-f0-9]{20,40}/',
                $recipient['substitution_data']['WEBVIEWURL']
            );
            $this->assertSame('', $recipient['substitution_data']['SIGNATURE']);
            $this->assertSame('Hello there!', $recipient['substitution_data']['SUBJECT']);
            $this->assertSame($contactAddressWithoutDotPart.'.email', $recipient['substitution_data']['CONTACTFIELDEMAIL']);
            $this->assertSame('', $recipient['substitution_data']['OWNERFIELDEMAIL']);
            $this->assertSame('', $recipient['substitution_data']['OWNERFIELDFIRSTNAME']);
            $this->assertSame('', $recipient['substitution_data']['OWNERFIELDLASTNAME']);
            $this->assertSame('', $recipient['substitution_data']['OWNERFIELDPOSITION']);
            $this->assertSame('', $recipient['substitution_data']['OWNERFIELDSIGNATURE']);
            $this->assertMatchesRegularExpression('/https:\/\/localhost\/email\/[a-f0-9]{20,40}\.gif/', $recipient['substitution_data']['TRACKINGPIXEL']);
        };

        $expectedResponses = [
            function ($method, $url, $options): MockResponse {
                Assert::assertSame(Request::METHOD_POST, $method);
                Assert::assertSame('https://api.sparkpost.com/api/v1/utils/content-previewer/', $url);
                $jsonArray = json_decode($options['body'], true);

                // Content
                $this->assertSame('Admin <admin@mautic.test>', $jsonArray['content']['from']);
                $this->assertSame('Hello there!', $jsonArray['content']['subject']);
                $this->assertSame('value123', $jsonArray['content']['headers']['x-global-custom-header']);
                $this->assertSame('Bulk', $jsonArray['content']['headers']['Precedence']);
                $this->assertMatchesRegularExpression('/\d+/', $jsonArray['content']['headers']['X-EMAIL-ID'], 'X-EMAIL-ID does not match');
                $this->assertMatchesRegularExpression('/https:\/\/localhost\/email\/unsubscribe\/[a-f0-9]{20,40}\/contact@(one|two)\.email\/[a-f0-9]*/', $jsonArray['content']['headers']['List-Unsubscribe'], 'List-Unsubscribe does not match');
                $this->assertSame('List-Unsubscribe=One-Click', $jsonArray['content']['headers']['List-Unsubscribe-Post']);
                $this->assertSame('<html lang="en"><head><title>Hello there!</title></head><body>Hello {{{ CONTACTFIELDEMAIL }}}!</br>{{{ UNSUBSCRIBETEXT }}}<img height="1" width="1" src="{{{ TRACKINGPIXEL }}}" alt="" /></body></html>', $jsonArray['content']['html']);
                $this->assertSame('Dear {{{ CONTACTFIELDEMAIL }}}', $jsonArray['content']['text']);
                $this->assertSame('admin@mautic.test', $jsonArray['content']['reply_to']);
                $this->assertEmpty($jsonArray['content']['attachments']);

                $this->assertNull($jsonArray['inline_css']);

                // Tags
                $this->assertEmpty($jsonArray['tags']);

                // Campaign ID
                $this->assertSame('utmCampaignA', $jsonArray['campaign_id']);

                // Options
                $this->assertFalse($jsonArray['options']['open_tracking']);
                $this->assertFalse($jsonArray['options']['click_tracking']);
                $this->assertTrue($jsonArray['options']['transactional']);

                // Substitution Data
                $this->assertSame('Default Dynamic Content', $jsonArray['substitution_data']['DYNAMICCONTENTDYNAMICCONTENT1']);
                $this->assertMatchesRegularExpression('/<a href="https:\/\/localhost\/email\/unsubscribe\/[a-f0-9]{20,40}\/contact@(one|two)\.email\/[a-f0-9]*">Unsubscribe<\/a> to no longer receive emails from us./', $jsonArray['substitution_data']['UNSUBSCRIBETEXT'], 'UNSUBSCRIBETEXT does not match');
                $this->assertMatchesRegularExpression('/https:\/\/localhost\/email\/unsubscribe\/[a-f0-9]{20,40}\/contact@(one|two)\.email\/[a-f0-9]*/', $jsonArray['substitution_data']['UNSUBSCRIBEURL'], 'UNSUBSCRIBEURL does not match');
                $this->assertMatchesRegularExpression('/<a href="https:\/\/localhost\/email\/view\/[a-f0-9]{20,40}">Having trouble reading this email\? Click here\.<\/a>/', $jsonArray['substitution_data']['WEBVIEWTEXT'], 'WEBVIEWTEXT does not match');
                $this->assertMatchesRegularExpression('/https:\/\/localhost\/email\/view\/[a-f0-9]*/', $jsonArray['substitution_data']['WEBVIEWURL'], 'WEBVIEWURL does not match');
                $this->assertSame('', $jsonArray['substitution_data']['SIGNATURE']);
                $this->assertSame('Hello there!', $jsonArray['substitution_data']['SUBJECT']);
                $this->assertMatchesRegularExpression('/contact@(one|two)\.email/', $jsonArray['substitution_data']['CONTACTFIELDEMAIL'], 'CONTACTFIELDEMAIL does not match');
                $this->assertSame('', $jsonArray['substitution_data']['OWNERFIELDEMAIL']);
                $this->assertSame('', $jsonArray['substitution_data']['OWNERFIELDFIRSTNAME']);
                $this->assertSame('', $jsonArray['substitution_data']['OWNERFIELDLASTNAME']);
                $this->assertSame('', $jsonArray['substitution_data']['OWNERFIELDPOSITION']);
                $this->assertSame('', $jsonArray['substitution_data']['OWNERFIELDSIGNATURE']);
                $this->assertMatchesRegularExpression('/https:\/\/localhost\/email\/[a-f0-9]*\.gif/', $jsonArray['substitution_data']['TRACKINGPIXEL'], 'TRACKINGPIXEL does not match');

                return new MockResponse('{"results": {"subject": "Hello there!", "html": "This is test body for {contactfield=email}!"}}');
            },
            function ($method, $url, $options) use ($assertRecipient, $email): MockResponse {
                Assert::assertSame(Request::METHOD_POST, $method);
                Assert::assertSame('https://api.sparkpost.com/api/v1/transmissions/', $url);
                $jsonArray = json_decode($options['body'], true);
                // dd($options['body']);
                $this->assertSame('Admin <admin@mautic.test>', $jsonArray['content']['from']);
                $this->assertSame('Hello there!', $jsonArray['content']['subject']);
                $this->assertSame('value123', $jsonArray['content']['headers']['x-global-custom-header']);
                $this->assertSame('Bulk', $jsonArray['content']['headers']['Precedence']);
                $this->assertMatchesRegularExpression('/\d+/', $jsonArray['content']['headers']['X-EMAIL-ID']);
                $this->assertMatchesRegularExpression('/https:\/\/localhost\/email\/unsubscribe\/[a-f0-9]{20,40}\/contact@(one|two)\.email\/[a-f0-9]*/', $jsonArray['content']['headers']['List-Unsubscribe']);
                $this->assertSame('List-Unsubscribe=One-Click', $jsonArray['content']['headers']['List-Unsubscribe-Post']);
                $this->assertSame('<html lang="en"><head><title>Hello there!</title></head><body>Hello {{{ CONTACTFIELDEMAIL }}}!</br>{{{ UNSUBSCRIBETEXT }}}<img height="1" width="1" src="{{{ TRACKINGPIXEL }}}" alt="" /></body></html>', $jsonArray['content']['html']);
                $this->assertSame('Dear {{{ CONTACTFIELDEMAIL }}}', $jsonArray['content']['text']);
                $this->assertSame('admin@mautic.test', $jsonArray['content']['reply_to']);
                $this->assertEmpty($jsonArray['content']['attachments']);

                // Recipients
                $this->assertCount(4, $jsonArray['recipients']);

                // Sort recipients by recipient email address and then by contact email address
                usort($jsonArray['recipients'], function ($a, $b) {
                    $emailComparison = strcmp($a['substitution_data']['CONTACTFIELDEMAIL'], $b['substitution_data']['CONTACTFIELDEMAIL']);
                    if (0 === $emailComparison) {
                        return strcmp($a['address']['email'], $b['address']['email']);
                    }

                    return $emailComparison;
                });

                $assertRecipient($jsonArray['recipients'][0], 'contact@one', 'contact@one', $email);
                $assertRecipient($jsonArray['recipients'][1], 'contact@one', 'custom@bcc', $email);
                $assertRecipient($jsonArray['recipients'][2], 'contact@two', 'contact@two', $email);
                $assertRecipient($jsonArray['recipients'][3], 'contact@two', 'custom@bcc', $email);

                return new MockResponse('{"results": {"total_rejected_recipients": 0, "total_accepted_recipients": 1, "id": "11668787484950529"}}');
            },
        ];

        /** @var MockHttpClient $mockHttpClient */
        $mockHttpClient = self::getContainer()->get(HttpClientInterface::class);
        $mockHttpClient->setResponseFactory($expectedResponses);

        $this->client->request(Request::METHOD_POST, '/s/ajax?action=email:sendBatch', [
            'id'         => $email->getId(),
            'pending'    => 2,
            'batchLimit' => 10,
        ]);

        $this->assertTrue($this->client->getResponse()->isOk(), $this->client->getResponse()->getContent());
        $this->assertSame('{"success":1,"percent":100,"progress":[2,2],"stats":{"sent":2,"failed":0,"failedRecipients":[]}}', $this->client->getResponse()->getContent());
    }

    public function testTestTransportButton(): void
    {
        $expectedResponses = [
            function ($method, $url, $options): MockResponse {
                Assert::assertSame(Request::METHOD_POST, $method);
                Assert::assertSame('https://api.sparkpost.com/api/v1/utils/content-previewer/', $url);
                $this->assertSparkpostTestRequestBody($options['body']);

                return new MockResponse('{"results": {"subject": "Hello there!", "html": "This is test body for {contactfield=email}!"}}');
            },
            function ($method, $url, $options): MockResponse {
                Assert::assertSame(Request::METHOD_POST, $method);
                Assert::assertSame('https://api.sparkpost.com/api/v1/transmissions/', $url);
                $this->assertSparkpostTestRequestBody($options['body']);

                return new MockResponse('{"results": {"total_rejected_recipients": 0, "total_accepted_recipients": 1, "id": "11668787484950529"}}');
            },
        ];

        /** @var MockHttpClient $mockHttpClient */
        $mockHttpClient = self::getContainer()->get(HttpClientInterface::class);
        $mockHttpClient->setResponseFactory($expectedResponses);
        $this->client->request(Request::METHOD_GET, '/s/ajax?action=email:sendTestEmail');
        Assert::assertTrue($this->client->getResponse()->isOk());
        Assert::assertSame('{"success":1,"message":"Success!"}', $this->client->getResponse()->getContent());
    }

    private function assertSparkpostTestRequestBody(string $body): void
    {
        $bodyArray = json_decode($body, true);
        Assert::assertSame('Admin <admin@mautic.test>', $bodyArray['content']['from']);
        Assert::assertNull($bodyArray['content']['html']);
        Assert::assertSame('admin@mautic.test', $bodyArray['content']['reply_to']);
        Assert::assertSame('Mautic test email', $bodyArray['content']['subject']);
        Assert::assertSame('Hi! This is a test email from Mautic. Testing...testing...1...2...3!', $bodyArray['content']['text']);
    }

    private function assertSparkpostRequestBody(string $body): void
    {
        $bodyArray = json_decode($body, true);
        Assert::assertSame('Admin User <admin@yoursite.com>', $bodyArray['content']['from']);
        Assert::assertSame('value123', $bodyArray['content']['headers']['x-global-custom-header']);
        Assert::assertSame('This is test body for {{{ CONTACTFIELDEMAIL }}}!<img height="1" width="1" src="{{{ TRACKINGPIXEL }}}" alt="" />', $bodyArray['content']['html']);
        Assert::assertSame('admin@mautic.test', $bodyArray['content']['reply_to']);
        Assert::assertSame('Hello there!', $bodyArray['content']['subject']);
        Assert::assertSame('This is test body for {{{ CONTACTFIELDEMAIL }}}!', $bodyArray['content']['text']);
        Assert::assertSame(['open_tracking' => false, 'click_tracking' => false, 'transactional' => true], $bodyArray['options']);
        Assert::assertSame('contact@an.email', $bodyArray['substitution_data']['CONTACTFIELDEMAIL']);
        Assert::assertSame('Hello there!', $bodyArray['substitution_data']['SUBJECT']);
        Assert::assertArrayHasKey('SIGNATURE', $bodyArray['substitution_data']);
        Assert::assertArrayHasKey('TRACKINGPIXEL', $bodyArray['substitution_data']);
        Assert::assertArrayHasKey('UNSUBSCRIBETEXT', $bodyArray['substitution_data']);
        Assert::assertArrayHasKey('UNSUBSCRIBEURL', $bodyArray['substitution_data']);
        Assert::assertArrayHasKey('WEBVIEWTEXT', $bodyArray['substitution_data']);
        Assert::assertArrayHasKey('WEBVIEWURL', $bodyArray['substitution_data']);
    }

    private function createContact(string $email): Lead
    {
        $lead = new Lead();
        $lead->setEmail($email);

        $this->em->persist($lead);

        return $lead;
    }

    /**
     * @dataProvider dataInvalidDsn
     *
     * @param array<string, string> $data
     */
    public function testInvalidDsn(array $data, string $expectedMessage): void
    {
        // Request config edit page
        $crawler = $this->client->request(Request::METHOD_GET, '/s/config/edit');
        Assert::assertTrue($this->client->getResponse()->isOk());

        // Set form data
        $form = $crawler->selectButton('config[buttons][save]')->form();
        $form->setValues($data + ['config[leadconfig][contact_columns]' => ['name', 'email', 'id']]);

        // Check if there is the given validation error
        $crawler = $this->client->submit($form);
        Assert::assertTrue($this->client->getResponse()->isOk());
        Assert::assertStringContainsString($this->translator->trans($expectedMessage, [], 'validators'), $crawler->text());
    }

    /**
     * @return array<string, mixed[]>
     */
    public function dataInvalidDsn(): iterable
    {
        yield 'Empty region' => [
            [
                'config[emailconfig][mailer_dsn][options][list][0][value]' => '',
            ],
            'mautic.sparkpost.plugin.region.empty',
        ];

        yield 'Invalid region' => [
            [
                'config[emailconfig][mailer_dsn][options][list][0][value]' => 'invalid_region',
            ],
            'mautic.sparkpost.plugin.region.invalid',
        ];
    }
}

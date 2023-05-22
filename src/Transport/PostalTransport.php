<?php

namespace Lopes\LaravelPostalDriver\Transport;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Support\Arr;
use Psr\Http\Message\ResponseInterface;
use Lopes\LaravelPostalDriver\Postal;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\MessageConverter;
use Symfony\Component\Mime\Part\DataPart;

class PostalTransport extends AbstractTransport
{
    use Postal {
        ptDecode as decode;
    }

 
    const BASE_URL = 'https://postal.smartgps.com.br/api/v1/send/message';

    /**
     * @deprecated use REQUEST_BODY_PARAMETER instead
     */
    const SMTP_API_NAME = 'postal/request-body-parameter';
    const REQUEST_BODY_PARAMETER = 'postal/request-body-parameter';

    /**
     * @var Client
     */
    private $client;
    private $attachments;
    private $numberOfRecipients;
    private $apiKey;
    private $endpoint;

    public function __construct(ClientInterface $client, string $api_key, string $endpoint = null)
    {
        $this->client = $client;
        $this->apiKey = $api_key;
        $this->endpoint = $endpoint ?? self::BASE_URL;
        $this->attachments = [];

        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $email = MessageConverter::toEmail($message->getOriginalMessage());
        

        $body = $this->getPayload($email);
        

        $payload = [
            'headers' => [
                'X-Server-API-Key' => $this->apiKey,
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode($body),
        ];
    
        $response = $this->post($payload);

        $message->getOriginalMessage()
            ->getHeaders()
            ->addTextHeader('X-Postal-Message-Id', $response->getHeaderLine('X-Message-Id'));
    }

    /**
     * @param Email $email
     * @return array[]
     */
    private function getPersonalizations(Email $email): array
    {
          $personalization = $this->setAddress($email->getTo());

        /*if (count($email->getCc()) > 0) {
            $personalization['cc'] = $this->setAddress($email->getCc());

        }

        if (count($email->getBcc()) > 0) {
            $personalization['bcc'] = $this->setAddress($email->getBcc());

        }*/

        return $personalization;
    }

    /**
     * @param Address[] $addresses
     * @return array
     */
    private function setAddress(array $addresses): array
    {
        $recipients = [];
        foreach ($addresses as $address) {
            $recipient = [$address->getAddress()];
            if ($address->getName() !== '') {
                $recipient['name'] = $address->getName();
            }
            $recipients[] = $recipient;
        }
        return $recipients;
    }

    /**
     * @param Email $email
     * @return array
     */
    private function getFrom(Email $email): array
    {
        if (count($email->getFrom()) > 0) {
            foreach ($email->getFrom() as $from) {
                return ['email' => $from->getAddress(), 'name' => $from->getName()];
            }
        }
        return [];
    }

    /**
     * @param Email $email
     * @return array
     */
    private function getContents(Email $email): array
    {
        $contents = [];
        if (!is_null($email->getTextBody())) {
            $contents[] = [
                'type' => 'text/plain',
                'value' => $email->getTextBody(),
            ];
        }

        if (!is_null($email->getHtmlBody())) {
            $contents[] = [
                'type' => 'text/html',
                'value' => $email->getHtmlBody(),
            ];
        }

        return $contents;
    }

    /**
     * @param Email $email
     * @return array|null
     */
    private function getReplyTo(Email $email): ?array
    {
        if (count($email->getReplyTo()) > 0) {
            $replyTo = $email->getReplyTo()[0];
            return [
                'email' => $replyTo->getAddress(),
                'name' => $replyTo->getName(),
            ];
        }
        return null;
    }

    /**
     * @param Email $email
     * @return array
     */
    private function getAttachments(Email $email): array
    {
        $attachments = [];
        foreach ($email->getAttachments() as $attachment) {
            $filename = $this->getAttachmentName($attachment);
            if ($filename === self::REQUEST_BODY_PARAMETER) {
                continue;
            }

            $attachments[] = [
                'content' => base64_encode($attachment->getBody()),
                'filename' => $this->getAttachmentName($attachment),
                'type' => $this->getAttachmentContentType($attachment),
                'disposition' => $attachment->getPreparedHeaders()->getHeaderParameter('Parameterized', 'Content-Disposition'),
                'content_id' => $attachment->getContentId(),
            ];
        }
        return $attachments;
    }

    private function getAttachmentName(DataPart $dataPart): string
    {
        return $dataPart->getPreparedHeaders()->getHeaderParameter('Content-Disposition', 'filename');
    }

    private function getAttachmentContentType(Datapart $dataPart): string
    {
        return $dataPart->getMediaType() . '/' . $dataPart->getMediaSubtype();
    }

  

  

    private function getPayload(Email $email): array
    {
        $headers = $email->getHeaders();
        $html = $email->getHtmlBody();
        if (null !== $html && \is_resource($html)) {
            if (stream_get_meta_data($html)['seekable'] ?? false) {
                rewind($html);
            }
            $html = stream_get_contents($html);
        }
        [$attachments, $inlines, $html] = $this->prepareAttachments($email, $html);

        $payload = [
            'from' => 'notificacao@tracker-net.app',
            'to' => $this->getPersonalizations($email),
            'subject' => $email->getSubject(),
            'attachment' => $attachments,
            'inline' => $inlines,
        ];
        if ($emails = $email->getCc()) {
            $payload['cc'] = implode(',', $this->stringifyAddresses($emails));
        }
        if ($emails = $email->getBcc()) {
            $payload['bcc'] = implode(',', $this->stringifyAddresses($emails));
        }
        if ($email->getTextBody()) {
            $payload['plain_body'] = $email->getTextBody();
        }
        if ($html) {
            $payload['html_body'] = $html;
        }

        $headersToBypass = ['from', 'to', 'cc', 'bcc', 'subject', 'content-type'];
        foreach ($headers->all() as $name => $header) {
            if (\in_array($name, $headersToBypass, true)) {
                continue;
            }

            if ($header instanceof TagHeader) {
                $payload[] = ['o:tag' => $header->getValue()];

                continue;
            }

            if ($header instanceof MetadataHeader) {
                $payload['v:'.$header->getKey()] = $header->getValue();

                continue;
            }

            // Check if it is a valid prefix or header name according to postal API
            $prefix = substr($name, 0, 2);
            if (\in_array($prefix, ['h:', 't:', 'o:', 'v:']) || \in_array($name, ['recipient-variables', 'template', 'amp-html'])) {
                $headerName = $header->getName();
            } else {
                $headerName = 'h:'.$header->getName();
            }

            $payload[$headerName] = $header->getBodyAsString();
        }

        return $payload;
    }


    private function prepareAttachments(Email $email, ?string $html): array
    {
        $attachments = $inlines = [];
        foreach ($email->getAttachments() as $attachment) {
            $headers = $attachment->getPreparedHeaders();
            if ('inline' === $headers->getHeaderBody('Content-Disposition')) {
                // replace the cid with just a file name (the only supported way by postal)
                if ($html) {
                    $filename = $headers->getHeaderParameter('Content-Disposition', 'filename');
                    $new = basename($filename);
                    $html = str_replace('cid:'.$filename, 'cid:'.$new, $html);
                    $p = new \ReflectionProperty($attachment, 'filename');
                    $p->setValue($attachment, $new);
                }
                $inlines[] = $attachment;
            } else {
                $attachments[] = $attachment;
            }
        }

        return [$attachments, $inlines, $html];
    }


    /**
     * @param $payload
     * @return ResponseInterface
     * @throws ClientException
     */
    private function post($payload)
    {
        return $this->client->request('POST', $this->endpoint, $payload);
    }

    public function __toString(): string
    {
        return 'postal';
    }
}

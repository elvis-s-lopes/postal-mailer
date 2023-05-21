<?php

namespace Lopes\LaravelPostalDriver;

use Illuminate\Mail\Mailable;
use Illuminate\Notifications\Messages\MailMessage;
use Lopes\LaravelPostalDriver\Transport\PostalTransport;
use Symfony\Component\Mime\Email;

trait Postal
{

    /**
     * @param null|array $params
     * @return $this
     */
    public function postal($params)
    {
        $isValidInstance = $this instanceof Mailable || $this instanceof MailMessage;

        if ($isValidInstance && $this->mailDriver() == "postal") {
            $this->withSymfonyMessage(function (Email $email) use ($params) {
                $email->embed(static::sgEncode($params), PostalTransport::REQUEST_BODY_PARAMETER);
            });
        }
        return $this;
    }

    /**
     * @return string
     */
    private function mailDriver()
    {
        return function_exists('config') ? config('mail.default', config('mail.driver')) : env('MAIL_MAILER', env('MAIL_DRIVER'));
    }

    /**
     * @param array $params
     * @return string
     */
    public static function sgEncode($params)
    {
        if (is_string($params)) {
            return $params;
        }
        return json_encode($params);
    }

    /**
     * @param string $strParams
     * @return array
     */
    public static function sgDecode($strParams)
    {
        if (!is_string($strParams)) {
            return (array)$strParams;
        }
        $params = json_decode($strParams, true);
        return is_array($params) ? $params : [];
    }

}

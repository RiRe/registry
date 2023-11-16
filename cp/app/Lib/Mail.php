<?php

namespace App\Lib;

use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;
use Utopia\Messaging\Messages\Email;
use Utopia\Messaging\Adapters\Email\SendGrid;
use Utopia\Messaging\Adapters\Email\Mailgun;

class Mail
{
    public static function send($subject, $body, $from=[], $to=[], $info=[])
    {
        if (envi('MAIL_DRIVER') == 'utopia') {
            try {
                $message = new Email(
                    from: [$from['email']],
                    to: [$to['email']],
                    subject: $subject,
                    content: $body
                );

                // Send email
                if (envi('MAIL_API_PROVIDER') == 'sendgrid') {
                    $messaging = new Sendgrid(envi('MAIL_API_KEY'));
                    $messaging->send($message);
                    return true;
                } else {
                    $messaging = new Mailgun(envi('MAIL_API_KEY'), envi('APP_DOMAIN'));
                    $messaging->send($message);
                    return true;
                }
            } catch (\Exception $e) {
                echo "Message could not be sent. Error: {$e->getMessage()}";
                return false;
            }
        } else if (envi('MAIL_DRIVER') == 'smtp') {
            $mail = new PHPMailer(true);
            try {
                $mail->SMTPDebug = 0;
                $mail->isSMTP();
                $mail->Host = envi('MAIL_HOST');
                $mail->SMTPAuth = true;
                $mail->Username = envi('MAIL_USERNAME');
                $mail->Password = envi('MAIL_PASSWORD');
                $mail->SMTPSecure = envi('MAIL_ENCRYPTION');
                $mail->Port = envi('MAIL_PORT');

                $mail->setFrom($from['email'], $from['name']);
                $mail->addAddress($to['email'], $to['name']);
                //$mail->addAttachment('path/to/invoice1.pdf', 'invoice1.pdf');

                $mail->isHTML(true);
                $mail->Subject = $subject;
                $mail->Body = $body;
                $mail->send();
                return true;
            } catch (Exception $e) {
                echo "Message could not be sent. Mailer Error: {$mail->ErrorInfo}";
                return false;
            }
        }
    }
}
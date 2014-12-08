<?php
/*************************************************************************************/
/*                                                                                   */
/*      Thelia 2 Paybox payment module                                               */
/*                                                                                   */
/*      Copyright (c) CQFDev                                                         */
/*      email : thelia@cqfdev.fr                                                     */
/*      web : http://www.cqfdev.fr                                                   */
/*                                                                                   */
/*************************************************************************************/

namespace Paybox;

use Propel\Runtime\Connection\ConnectionInterface;
use Thelia\Core\HttpFoundation\Response;
use Thelia\Core\Translation\Translator;
use Thelia\Model\Message;
use Thelia\Model\MessageQuery;
use Thelia\Model\ModuleImageQuery;
use Thelia\Model\Order;
use Thelia\Module\AbstractPaymentModule;

/**
 * Paybox payment module
 *
 * @author Franck Allimant <franck@cqfdev.fr>
 */
class Paybox extends AbstractPaymentModule
{
    /** The module domain for internationalisation */
    const MODULE_DOMAIN = "paybox";

    /** The module domain for internationalisation */
    const MODULE_CODE = "Paybox";

    /** The confirmation message identifier */
    const CONFIRMATION_MESSAGE_NAME = 'paybox_payment_confirmation';

    // Liste des variables retournées par paybox
    const PARAMETRES_RETOUR = 'montant:M;ref:R;auto:A;trans:T;erreur:E;sign:K';

    /** The notification of payment confirmation */
    const NOTIFICATION_MESSAGE_NAME = 'paybox_payment_status_notification';

    /**
     * @inheritdoc
     */
    public function postActivation(ConnectionInterface $con = null)
    {
        // Create payment confirmation message from templates, if not already defined
        $email_templates_dir = __DIR__ . DS . 'I18n' . DS . 'email-templates' . DS;

        if (null === MessageQuery::create()->findOneByName(self::CONFIRMATION_MESSAGE_NAME)) {
            $message = new Message();

            $message
                ->setName(self::CONFIRMATION_MESSAGE_NAME)

                ->setLocale('en_US')
                ->setTitle('Paybox payment confirmation')
                ->setSubject('Payment of order {$order_ref}')
                ->setHtmlMessage(file_get_contents($email_templates_dir . 'en.html'))
                ->setTextMessage(file_get_contents($email_templates_dir . 'en.txt'))

                ->setLocale('fr_FR')
                ->setTitle('Confirmation de paiement par PayBox')
                ->setSubject('Confirmation du paiement de votre commande {$order_ref}')
                ->setHtmlMessage(file_get_contents($email_templates_dir . 'fr.html'))
                ->setTextMessage(file_get_contents($email_templates_dir . 'fr.txt'))
                ->save();
        }

        if (null === MessageQuery::create()->findOneByName(self::NOTIFICATION_MESSAGE_NAME)) {
            $message = new Message();

            $message
                ->setName(self::NOTIFICATION_MESSAGE_NAME)

                ->setLocale('en_US')
                ->setTitle('Paybox payment status notification')
                ->setSubject('Paybox payment status for order {$order_ref}: {$paybox_payment_status}')
                ->setHtmlMessage(file_get_contents($email_templates_dir . 'notification-en.html'))
                ->setTextMessage(file_get_contents($email_templates_dir . 'notification-en.txt'))

                ->setLocale('fr_FR')
                ->setTitle('Notification du résultat d\'un paiement par Paybox')
                ->setSubject('Résultats du paiement Paybox de la commande {$order_ref} : {$paybox_payment_status}')
                ->setHtmlMessage(file_get_contents($email_templates_dir . 'notification-fr.html'))
                ->setTextMessage(file_get_contents($email_templates_dir . 'notification-fr.txt'))
                ->save();
        }

        /* Deploy the module's image */
        $module = $this->getModuleModel();

        if (ModuleImageQuery::create()->filterByModule($module)->count() == 0) {
            $this->deployImageFolder($module, sprintf('%s'.DS.'images', __DIR__), $con);
        }
    }

    /**
     * @inheritdoc
     */
    public function destroy(ConnectionInterface $con = null, $deleteModuleData = false)
    {
        // Delete config table and messages if required
        if ($deleteModuleData) {
            MessageQuery::create()->findOneByName(self::CONFIRMATION_MESSAGE_NAME)->delete($con);
            MessageQuery::create()->findOneByName(self::NOTIFICATION_MESSAGE_NAME)->delete($con);
        }
    }

    /**
     *  Method used by payment gateway.
     *
     *  If this method return a \Thelia\Core\HttpFoundation\Response instance, this response is sent to the
     *  browser.
     *
     *  In many cases, it's necessary to send a form to the payment gateway.
     *  On your response you can return this form already completed, ready to be sent
     *
     * @param  Order $order processed order
     * @return Response the HTTP response
     */
    public function pay(Order $order)
    {
        return $this->doPay($order, 'SINGLE');
    }

    /**
     * Payment gateway invocation
     *
     * @param  Order $order processed order
     * @return Response the HTTP response
     */
    protected function doPay(Order $order)
    {
        if ('TEST' == Paybox::getConfigValue('mode', false)) {
            $platformUrl = Paybox::getConfigValue('url_serveur_test', false);
        } else {
            $platformUrl = Paybox::getConfigValue('url_serveur', false);
        }

        // Be sure to have a valid platform URL, otherwise give up
        if (false === $platformUrl) {
            throw new \InvalidArgumentException(
                Translator::getInstance()->trans(
                    "The platform URL is not defined, please check Paybox module configuration.",
                    [],
                    Paybox::MODULE_DOMAIN
                )
            );
        }

        $hashAlgo = $this->getHashAlgorithm();
        $clefPrivee = Paybox::getConfigValue('clef_privee');

        // Generate a transaction ID
        $transactionId = sprintf("%010d", $order->getId());

        $order->setTransactionRef($transactionId)->save();

        $paybox_params = [
            'PBX_SITE' => Paybox::getConfigValue('numero_site'),
            'PBX_RANG' => Paybox::getConfigValue('rang_site'),
            'PBX_IDENTIFIANT' => Paybox::getConfigValue('identifiant_interne'),
            'PBX_RETOUR' => self::PARAMETRES_RETOUR,
            'PBX_HASH' => $hashAlgo,
            'PBX_SECRET' => $clefPrivee,
            'PBX_ANNULE' => Paybox::getConfigValue('url_retour_abandon'),
            'PBX_EFFECTUE' => Paybox::getConfigValue('url_retour_succes'),
            'PBX_REFUSE' => Paybox::getConfigValue('url_retour_refus'),
            'PBX_REPONDRE_A' => Paybox::getConfigValue('url_ipn'),
            'PBX_TOTAL' => round(100 * $order->getTotalAmount()),
            'PBX_DEVISE' => $this->getCurrencyIso4217NumericCode($order->getCurrency()->getCode()),
            'PBX_CMD' => $transactionId,
            'PBX_PORTEUR' => $order->getCustomer()->getEmail(),
            'PBX_TIME' => date("c"),
            'PBX_RUF1' => 'POST'
        ];

        // Generate signature
        $param = '';

        foreach ($paybox_params as $key => $value) {
            $param .= "&" . $key . '=' . $value;
        }

        $param = ltrim($param, '&');

        $binkey = pack('H*', $clefPrivee);

        $paybox_params['PBX_HMAC'] = strtoupper(hash_hmac($hashAlgo, $param, $binkey));

        return $this->generateGatewayFormResponse($order, $platformUrl, $paybox_params);
    }

    /**
     * @return boolean true to allow usage of this payment module, false otherwise.
     */
    public function isValidPayment()
    {
        $valid = false;

        $mode = Paybox::getConfigValue('mode', false);

        // If we're in test mode, do not display Paybox on the front office, except for allowed IP addresses.
        if ('TEST' == $mode) {
            $raw_ips = explode("\n", Paybox::getConfigValue('allowed_ip_list', ''));

            $allowed_client_ips = array();

            foreach ($raw_ips as $ip) {
                $allowed_client_ips[] = trim($ip);
            }

            $client_ip = $this->getRequest()->getClientIp();

            $valid = in_array($client_ip, $allowed_client_ips);
        } elseif ('PRODUCTION' == $mode) {
            $valid = true;
        }

        if ($valid) {
            // Check if total order amount is in the module's limits
            $valid = $this->checkMinMaxAmount();
        }

        return $valid;
    }

    /**
     * Check if total order amount is in the module's limits
     *
     * @return bool true if the current order total is within the min and max limits
     */
    protected function checkMinMaxAmount()
    {
        // Check if total order amount is in the module's limits
        $order_total = $this->getCurrentOrderTotalAmount();

        $min_amount = Paybox::getConfigValue('minimum_amount', 0);
        $max_amount = Paybox::getConfigValue('maximum_amount', 0);

        return $order_total > 0
            &&
            ($min_amount <= 0 || $order_total >= $min_amount)
            &&
            ($max_amount <= 0 || $order_total <= $max_amount);
    }

    /**
     * Get the numeric ISO 4217 code of a currency
     *
     * @param string $textCurrencyCode currency textual code, like EUR or USD
     * @return string the algorithm
     * @throw \RuntimeException if no algorithm was found.
     */

    protected function getCurrencyIso4217NumericCode($textCurrencyCode)
    {
        $localIso417data = __DIR__ . DS . "Config" . DS . "iso4217.xml";

        $xmlData = @file_get_contents("http://www.currency-iso.org/dam/downloads/table_a1.xml");

        if (!$xmlData) {
            $xmlData = @file_get_contents($localIso417data);
        } else {
            // Update the local currencies.
            @file_put_contents($localIso417data, $xmlData);
        }

        if (!$xmlData) {
            // Last chance: get code for common currencies
            switch ($textCurrencyCode) {
                case 'USD':
                    return 840;
                case 'GBP':
                    return 826;
                    break;
                case 'EUR':
                    return 978;
                    break;
            }
        } else {
            $currencies = new \SimpleXMLElement($xmlData);

            foreach ($currencies->CcyTbl->CcyNtry as $country) {
                if ($country->Ccy == $textCurrencyCode) {
                    return (string) $country->CcyNbr;
                }
            }
        }

        throw new \RuntimeException(
            Translator::getInstance()->trans(
                "Failed to get ISO 4217 data for currency %curr, payment is not possible.",
                ['%curr' => $textCurrencyCode]
            )
        );

    }

    /**
     * Find a suitable hashing algorithm
     *
     * @return string the algorithm
     * @throw \RuntimeException if no algorithm was found.
     */
    protected function getHashAlgorithm()
    {
        // Possible hashes
        $hashes = [
            'sha512',
            'sha256',
            'sha384',
            'ripemd160',
            'sha224',
            'mdc2'
        ];

        $hashEnabled = hash_algos();

        foreach ($hashes as $hash) {
            if (in_array($hash, $hashEnabled)) {
                return strtoupper($hash);
            }
        }

        throw new \RuntimeException(
            Translator::getInstance()->trans(
                "Failed to find a suitable hash algorithm. Please check your PHP configuration."
            )
        );
    }
}

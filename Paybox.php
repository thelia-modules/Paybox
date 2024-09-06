<?php

/*
 * This file is part of the Thelia package.
 * http://www.thelia.net
 *
 * (c) OpenStudio <info@thelia.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/*      Copyright (c) CQFDev */
/*      email : thelia@cqfdev.fr */
/*      web : http://www.cqfdev.fr */

namespace Paybox;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberUtil;
use Propel\Runtime\Connection\ConnectionInterface;
use Propel\Runtime\Exception\PropelException;
use Thelia\Core\HttpFoundation\Response;
use Thelia\Core\Translation\Translator;
use Thelia\Log\Tlog;
use Thelia\Model\Message;
use Thelia\Model\MessageQuery;
use Thelia\Model\ModuleImageQuery;
use Thelia\Model\Order;
use Thelia\Module\AbstractPaymentModule;

/**
 * Paybox payment module.
 *
 * @author Franck Allimant <franck@cqfdev.fr>
 */
class Paybox extends AbstractPaymentModule
{
    /** The module domain for internationalisation */
    const MODULE_DOMAIN = 'paybox';

    /** The module domain for internationalisation */
    const MODULE_CODE = 'Paybox';

    /** The confirmation message identifier */
    const CONFIRMATION_MESSAGE_NAME = 'paybox_payment_confirmation';

    // Liste des variables retournées par paybox
    const PARAMETRES_RETOUR = 'montant:M;ref:R;auto:A;trans:T;erreur:E;sign:K';

    /** The notification of payment confirmation */
    const NOTIFICATION_MESSAGE_NAME = 'paybox_payment_status_notification';

    public function postActivation(ConnectionInterface $con = null): void
    {
        // Create payment confirmation message from templates, if not already defined
        $email_templates_dir = __DIR__.DS.'I18n'.DS.'email-templates'.DS;

        if (null === MessageQuery::create()->findOneByName(self::CONFIRMATION_MESSAGE_NAME)) {
            $message = new Message();

            $message
                ->setName(self::CONFIRMATION_MESSAGE_NAME)

                ->setLocale('en_US')
                ->setTitle('Paybox payment confirmation')
                ->setSubject('Payment of order {$order_ref}')
                ->setHtmlMessage(file_get_contents($email_templates_dir.'en.html'))
                ->setTextMessage(file_get_contents($email_templates_dir.'en.txt'))

                ->setLocale('fr_FR')
                ->setTitle('Confirmation de paiement par PayBox')
                ->setSubject('Confirmation du paiement de votre commande {$order_ref}')
                ->setHtmlMessage(file_get_contents($email_templates_dir.'fr.html'))
                ->setTextMessage(file_get_contents($email_templates_dir.'fr.txt'))
                ->save();
        }

        if (null === MessageQuery::create()->findOneByName(self::NOTIFICATION_MESSAGE_NAME)) {
            $message = new Message();

            $message
                ->setName(self::NOTIFICATION_MESSAGE_NAME)

                ->setLocale('en_US')
                ->setTitle('Paybox payment status notification')
                ->setSubject('Paybox payment status for order {$order_ref}: {$paybox_payment_status}')
                ->setHtmlMessage(file_get_contents($email_templates_dir.'notification-en.html'))
                ->setTextMessage(file_get_contents($email_templates_dir.'notification-en.txt'))

                ->setLocale('fr_FR')
                ->setTitle('Notification du résultat d\'un paiement par Paybox')
                ->setSubject('Résultats du paiement Paybox de la commande {$order_ref} : {$paybox_payment_status}')
                ->setHtmlMessage(file_get_contents($email_templates_dir.'notification-fr.html'))
                ->setTextMessage(file_get_contents($email_templates_dir.'notification-fr.txt'))
                ->save();
        }

        /* Deploy the module's image */
        $module = $this->getModuleModel();

        if (ModuleImageQuery::create()->filterByModule($module)->count() == 0) {
            $this->deployImageFolder($module, sprintf('%s'.DS.'images', __DIR__), $con);
        }
    }

    public function destroy(ConnectionInterface $con = null, $deleteModuleData = false): void
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
     * @param Order $order processed order
     *
     * @return Response the HTTP response
     */
    public function pay(Order $order): Response
    {
        return $this->doPay($order);
    }

    /**
     * Payment gateway invocation.
     *
     * @param Order $order processed order
     *
     * @return Response the HTTP response
     */
    protected function doPay(Order $order): Response
    {
        if ('TEST' === self::getConfigValue('mode', false)) {
            $platformUrl = self::getConfigValue('url_serveur_test', false);
        } else {
            $platformUrl = self::getConfigValue('url_serveur', false);
        }

        // Be sure to have a valid platform URL, otherwise give up
        if (false === $platformUrl) {
            throw new \InvalidArgumentException(
                Translator::getInstance()->trans(
                    'The platform URL is not defined, please check Paybox module configuration.',
                    [],
                    self::MODULE_DOMAIN
                )
            );
        }

        $hashAlgo = $this->getHashAlgorithm();
        $clefPrivee = self::getConfigValue('clef_privee');

        $paybox_params = $this->doPayPayboxParameters($order)
            + [
                'PBX_HASH' => $hashAlgo,
                'PBX_SECRET' => $clefPrivee,
            ];

        // Generate signature
        $param = '';

        foreach ($paybox_params as $key => $value) {
            $param .= '&'.$key.'='.$value;
        }

        $param = ltrim($param, '&');

        $binkey = pack('H*', $clefPrivee);

        $paybox_params['PBX_HMAC'] = strtoupper(hash_hmac($hashAlgo, $param, $binkey));

        return $this->generateGatewayFormResponse($order, $platformUrl, $paybox_params);
    }

    protected function doPayPayboxParameters(Order $order): array
    {
        // Generate a transaction ID
        $transactionId = sprintf('%010d', $order->getId());

        $order->setTransactionRef($transactionId)->save();

        $paybox_params = [
            'PBX_SITE' => self::getConfigValue('numero_site'),
            'PBX_RANG' => self::getConfigValue('rang_site'),
            'PBX_IDENTIFIANT' => self::getConfigValue('identifiant_interne'),
            'PBX_RETOUR' => self::PARAMETRES_RETOUR,
            'PBX_ANNULE' => self::getConfigValue('url_retour_abandon'),
            'PBX_EFFECTUE' => self::getConfigValue('url_retour_succes'),
            'PBX_REFUSE' => self::getConfigValue('url_retour_refus'),
            'PBX_REPONDRE_A' => self::getConfigValue('url_ipn'),
            'PBX_TOTAL' => round(100 * $order->getTotalAmount()),
            'PBX_DEVISE' => $this->getCurrencyIso4217NumericCode($order->getCurrency()->getCode()),
            'PBX_CMD' => $transactionId,
            'PBX_PORTEUR' => $order->getCustomer()->getEmail(),
            'PBX_TIME' => date('c'),
            'PBX_RUF1' => 'POST',
            'PBX_SHOPPINGCART' => $this->getShoppingCart($order),
            'PBX_BILLING' => $this->getBilling($order),
        ];

        return $paybox_params;
    }

    /**
     * @throws PropelException
     *
     * @return array|bool|string|string[]
     */
    protected function getBilling(Order $order)
    {
        $address = $order->getOrderAddressRelatedByInvoiceOrderAddressId();

        // Decode phone number
        $phoneNumber = empty($address->getCellphone()) ? $address->getPhone() : $address->getCellphone();

        $phoneUtil = PhoneNumberUtil::getInstance();

        // Default values
        $phoneCountryCode = '00';
        $phoneNationalNumber = '00000000';

        try {
            $phoneNumberProto = $phoneUtil->parse($phoneNumber, $address->getCountry()->getIsoalpha2());

            $phoneNationalNumber = $phoneNumberProto?->getNationalNumber();
            $phoneCountryCode = $phoneNumberProto?->getCountryCode();
        } catch (NumberParseException $e) {
            Tlog::getInstance()->error('Failed to parse phone number "'.$phoneNumber.'", error is : '.$e->getMessage());
        }

        $billingXml = new \SimpleXMLElement('<Billing/>');
        $addressXml = $billingXml->addChild('Address');

        $addressXml->addChild('FirstName', $address->getFirstname());
        $addressXml->addChild('LastName', $address->getLastname());
        $addressXml->addChild('Address1', $address->getAddress1());
        $addressXml->addChild('ZipCode', $address->getZipcode());
        $addressXml->addChild('City', $address->getCity());
        $addressXml->addChild('CountryCode', $address->getCountry()->getIsocode());
        $addressXml->addChild('CountryCodeMobilePhone', '+'.$phoneCountryCode);
        $addressXml->addChild('MobilePhone', $phoneNationalNumber);

        return str_replace(["\n", "\r"], '', $billingXml->asXML());
    }

    /**
     * @return array|bool|string|string[]
     */
    protected function getShoppingCart(Order $order)
    {
        $quantity = 0;
        $shoppingCartXml = new \SimpleXMLElement('<shoppingcart/>');

        foreach ($order->getOrderProducts() as $product) {
            $quantity += $product->getQuantity();
        }

        $total = $shoppingCartXml->addChild('total');
        $total->addChild('totalQuantity', $quantity);

        return str_replace(["\n", "\r"], '', $shoppingCartXml->asXML());
    }

    /**
     * @return bool true to allow usage of this payment module, false otherwise
     */
    public function isValidPayment(): bool
    {
        $valid = false;

        $mode = self::getConfigValue('mode', false);

        // If we're in test mode, do not display Paybox on the front office, except for allowed IP addresses.
        if ('TEST' === $mode) {
            $raw_ips = explode("\n", self::getConfigValue('allowed_ip_list', ''));

            $allowed_client_ips = [];

            foreach ($raw_ips as $ip) {
                $allowed_client_ips[] = trim($ip);
            }

            $client_ip = $this->getRequest()->getClientIp();

            $valid = \in_array($client_ip, $allowed_client_ips);
        } elseif ('PRODUCTION' === $mode) {
            $valid = true;
        }

        if ($valid) {
            // Check if total order amount is in the module's limits
            $valid = $this->checkMinMaxAmount();
        }

        return $valid;
    }

    /**
     * Check if total order amount is in the module's limits.
     *
     * @return bool true if the current order total is within the min and max limits
     */
    protected function checkMinMaxAmount(): bool
    {
        // Check if total order amount is in the module's limits
        $order_total = $this->getCurrentOrderTotalAmount();

        $min_amount = self::getConfigValue('minimum_amount', 0);
        $max_amount = self::getConfigValue('maximum_amount', 0);

        return $order_total > 0
            && ($min_amount <= 0 || $order_total >= $min_amount)
            && ($max_amount <= 0 || $order_total <= $max_amount);
    }

    /**
     * Get the numeric ISO 4217 code of a currency.
     *
     * @param string $textCurrencyCode currency textual code, like EUR or USD
     *
     * @return string the algorithm
     *
     * @throw \RuntimeException if no algorithm was found.
     */
    protected function getCurrencyIso4217NumericCode($textCurrencyCode)
    {
        $currencies = null;

        $localIso417data = __DIR__.DS.'Config'.DS.'iso4217.xml';

        $currencyXmlDataUrl = 'http://www.currency-iso.org/dam/downloads/lists/list_one.xml';

        $xmlData = @file_get_contents($currencyXmlDataUrl);

        try {
            $currencies = new \SimpleXMLElement($xmlData);

            // Update the local currencies copy.
            @file_put_contents($localIso417data, $xmlData);
        } catch (\Exception $ex) {
            Tlog::getInstance()->warning("Failed to get currency XML data from $currencyXmlDataUrl: ".$ex->getMessage());
            try {
                $currencies = new \SimpleXMLElement(@file_get_contents($localIso417data));
            } catch (\Exception $ex) {
                Tlog::getInstance()->warning("Failed to get currency XML data from local copy $localIso417data: ".$ex->getMessage());
            }
        }

        if (null !== $currencies) {
            foreach ($currencies->CcyTbl->CcyNtry as $country) {
                if ($country->Ccy == $textCurrencyCode) {
                    return (string) $country->CcyNbr;
                }
            }
        }

        // Last chance
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

        throw new \RuntimeException(
            Translator::getInstance()->trans(
                'Failed to get ISO 4217 data for currency %curr, payment is not possible.',
                ['%curr' => $textCurrencyCode]
            )
        );
    }

    /**
     * Find a suitable hashing algorithm.
     *
     * @return string the algorithm
     *
     * @throw \RuntimeException if no algorithm was found.
     */
    protected function getHashAlgorithm(): string
    {
        // Possible hashes
        $hashes = [
            'sha512',
            'sha256',
            'sha384',
            'ripemd160',
            'sha224',
            'mdc2',
        ];

        $hashEnabled = hash_algos();

        foreach ($hashes as $hash) {
            if (\in_array($hash, $hashEnabled)) {
                return strtoupper($hash);
            }
        }

        throw new \RuntimeException(
            Translator::getInstance()->trans(
                'Failed to find a suitable hash algorithm. Please check your PHP configuration.'
            )
        );
    }
}

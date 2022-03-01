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

namespace Paybox\Controller;

use Paybox\Paybox;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\HttpFoundation\Response;
use Thelia\Module\BasePaymentModuleController;

/**
 * Paybox payment module
 *
 * @author Franck Allimant <franck@cqfdev.fr>
 */
class PaymentController extends BasePaymentModuleController
{
    protected function getModuleCode()
    {
        return Paybox::MODULE_CODE;
    }

    protected function getTextualMessage($code)
    {
        $messages = [
            '00001' => 'La connexion au centre d’autorisation a échoué. Vous pouvez dans ce cas là effectuer les redirections des internautes vers le FQDN tpeweb1.paybox.com.',
            // Traité spécialement plus bas
            // '001xx' => 'Paiement refusé par le centre d’autorisation',
            '00003' => 'Erreur Paybox',
            '00004' => 'Numéro de porteur ou cryptogramme visuel invalide.',
            '00006' => 'Accès refusé ou site/rang/identifiant incorrect.',
            '00008' => 'Date de fin de validité incorrecte',
            '00009' => 'Erreur de création d’un abonnement.',
            '00010' => 'Devise inconnue.',
            '00011' => 'Montant incorrect.',
            '00015' => 'Paiement déjà effectué.',
            '00016' => 'Abonné déjà existant (inscription nouvel abonné). Valeur ‘U’ de la variable PBX_RETOUR.',
            '00021' => 'Carte non autorisée.',
            '00029' => 'Carte non conforme. Code erreur renvoyé lors de la documentation de la variable « PBX_EMPREINTE ».',
            '00030' => 'Temps d’attente > 15 mn par l’internaute/acheteur au niveau de la page de paiements.',
            '00031' => 'Code réservé par paybox',
            '00032' => 'Code réservé par paybox',
            '00033' => 'Code pays de l’adresse IP du navigateur de l’acheteur non autorisé.',
            '00040' => 'Opération sans authentification 3DSecure, bloquée par le filtre.'
        ];

        if (isset($messages[$code])) {
            return $messages[$code];
        } else {
            $codeNum = intval($code);

            if ($codeNum >= 100 && $codeNum <= 199) {
                return 'Paiement refusé par le centre d’autorisation';
            } else {
                return "Aucune information sur le code $code";
            }
        }
    }

    /**
     * Send the payment notification email to the shop admin
     *
     * @param int $orderId
     * @param string $orderReference
     * @param string $paymentStatus
     * @param string $payboxMessage
     */
    protected function sendPaymentNotification($orderId, $orderReference, $paymentStatus, $payboxMessage)
    {
        $this->getMailer()->sendEmailToShopManagers(
            Paybox::NOTIFICATION_MESSAGE_NAME,
            [
                'order_ref' => $orderReference,
                'order_id' => $orderId,
                'paybox_payment_status' => $paymentStatus,
                'paybox_message' => $payboxMessage
            ]
        );
    }

    /**
     * Process a Paybox platform request
     */
    public function processPayboxRequest(EventDispatcherInterface $eventDispatcher)
    {
        // The response code to the server
        $request = $this->getRequest();

        $this->getLog()->addInfo(
            $this->getTranslator()->trans(
                "Paybox platform request received.",
                [],
                Paybox::MODULE_DOMAIN
            )
        );

        $orderId = 0;
        $orderReference = $this->getTranslator()->trans('UNDEFINED', [], Paybox::MODULE_DOMAIN);

        $orderStatus = $this->getTranslator()->trans('UNKNOWN', [], Paybox::MODULE_DOMAIN);

        $payboxRequestValues = [];

        $variables = explode(';', Paybox::PARAMETRES_RETOUR);

        foreach ($variables as $variable) {
            list($nom, $dummy) = explode(':', $variable);

            $payboxRequestValues[$nom] = $request->get($nom);
        }

        // Vérification de la signature
        $stringParam = '';

        foreach ($payboxRequestValues as $key => $value) {
            // Ignore sign parameter
            if ($key == 'sign') {
                continue;
            }
            
            $stringParam .= "&".$key.'='.$value;
        }

        $stringParam = ltrim($stringParam, '&');

        $signature = base64_decode($request->get('sign'));

        // Charger le fichier qui contient la clef publique de Paybox
        $publicKeyFile = __DIR__ . DS . '..' . DS . 'Config' . DS . 'clef-publique-paybox.pem';

        if (false !== $publicKeyData = file_get_contents($publicKeyFile)) {
            $publicKey = openssl_pkey_get_public($publicKeyData);

            if (openssl_verify($stringParam, $signature, $publicKey)) {
                // L'ID de transaction passé est l'ID de la commande
                $orderId = intval($payboxRequestValues['ref']);

                $orderStatus = $this->getTranslator()->trans('NOT PAID', [], Paybox::MODULE_DOMAIN);

                if (null !== $order = $this->getOrder($orderId)) {
                    $orderReference = $order->getRef();

                    $codeRetour = $payboxRequestValues['erreur'];

                    // Check payment status
                    if ($codeRetour == '00000') {
                        $orderStatus = $this->getTranslator()->trans('PAID', [], Paybox::MODULE_DOMAIN);

                        if (!$order->isPaid()) {
                            $this->confirmPayment($eventDispatcher, $orderId);

                            $message = $this->getTranslator()->trans(
                                "Order ID %id is confirmed.",
                                [ '%id' => $orderId ],
                                Paybox::MODULE_DOMAIN
                            );
                        } else {
                            $message = $this->getTranslator()->trans(
                                "Order ID %id already paid, message ignored.",
                                [ '%id' => $orderId ],
                                Paybox::MODULE_DOMAIN
                            );
                        }
                    } else {
                        $message = $this->getTranslator()->trans(
                            "Order cannot be confirmed, Paybox returned error %num: %text",
                            [
                                '%num' => $codeRetour,
                                '%text' => $this->getTextualMessage($codeRetour)
                            ],
                            Paybox::MODULE_DOMAIN
                        );
                    }
                } else {
                    $message = $this->getTranslator()->trans(
                        "Order ID %id was not found. Transaction reference is '%ref'.",
                        [ '%id' => $orderId, '%ref' =>  $payboxRequestValues['ref']],
                        Paybox::MODULE_DOMAIN
                    );
                }
            } else {
                $message = $this->getTranslator()->trans(
                    "Request parameters signature verification failed.",
                    [],
                    Paybox::MODULE_DOMAIN
                );
            }
        } else {
            $message = $this->getTranslator()->trans(
                "Failed to open %file, please check Paybox configuration",
                [ '%file' => $publicKeyFile ],
                Paybox::MODULE_DOMAIN
            );
        }

        $this->getLog()->addInfo($message);

        $this->getLog()->info(
            $this->getTranslator()->trans(
                "Paybox platform request processing terminated.",
                [],
                Paybox::MODULE_DOMAIN
            )
        );

        $this->sendPaymentNotification($orderId, $orderReference, $orderStatus, $message);

        return Response::create('');
    }

    public function processPayboxSuccessfulRequest()
    {
        $url = $this->getRouteFromRouter(
            'router.front',
            'order.placed',
            [ 'order_id' => intval($this->getRequest()->get('ref')) ]
        );

        return $this->generateRedirect($url);
    }

    public function processPayboxRejectedRequest()
    {
        $url = $this->getRouteFromRouter(
            'router.front',
            'order.failed',
            [
                'order_id' => intval($this->getRequest()->get('ref')),
                'message' => $this->getTranslator()->trans("Your payment was rejected.", [], Paybox::MODULE_DOMAIN)
            ]
        );

        return $this->generateRedirect($url);
    }

    public function processPayboxCanceledRequest()
    {
        $url = $this->getRouteFromRouter(
            'router.front',
            'order.failed',
            [
                'order_id' => intval($this->getRequest()->get('ref')),
                'message' => $this->getTranslator()->trans("Your payment was canceled.", [], Paybox::MODULE_DOMAIN)
            ]
        );

        return $this->generateRedirect($url);
    }
}

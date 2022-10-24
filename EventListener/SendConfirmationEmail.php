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

namespace Paybox\EventListener;

use Paybox\Paybox;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Thelia\Action\BaseAction;
use Thelia\Core\Event\Order\OrderEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Template\ParserInterface;
use Thelia\Log\Tlog;
use Thelia\Mailer\MailerFactory;
use Thelia\Model\ConfigQuery;

/**
 * Paybox payment module
 *
 * @author Franck Allimant <franck@cqfdev.fr>
 */
class SendConfirmationEmail extends BaseAction implements EventSubscriberInterface
{
    /**
     * @var MailerFactory
     */
    protected $mailer;
    /**
     * @var ParserInterface
     */
    protected $parser;

    /**
     * @var EventDispatcherInterface
     */
    protected $eventDispatcher;

    public function __construct(ParserInterface $parser, MailerFactory $mailer, EventDispatcherInterface $eventDispatcher)
    {
        $this->parser = $parser;
        $this->mailer = $mailer;
        $this->eventDispatcher = $eventDispatcher;
    }

    /**
     * @return \Thelia\Mailer\MailerFactory
     */
    public function getMailer()
    {
        return $this->mailer;
    }

    /**
     * Checks if we are the payment module for the order, and if the order is paid,
     * then send a confirmation email to the customer.
     *
     * @param OrderEvent $event
     * @throws \Exception
     */
    public function updateOrderStatus(OrderEvent $event)
    {
        $paybox = new Paybox();

        if ($event->getOrder()->isPaid() && $paybox->isPaymentModuleFor($event->getOrder())) {
            $contact_email = ConfigQuery::read('store_email', false);

            Tlog::getInstance()->debug(
                "Order " . $event->getOrder()->getRef() . ": sending confirmation email from store contact e-mail $contact_email"
            );

            if ($contact_email && Paybox::getConfigValue('send_confirmation_email_on_successful_payment', false)) {
                $order = $event->getOrder();

                $this->getMailer()->sendEmailToCustomer(
                    Paybox::CONFIRMATION_MESSAGE_NAME,
                    $order->getCustomer(),
                    [
                        'order_id' => $order->getId(),
                        'order_ref' => $order->getRef()
                    ]
                );

                Tlog::getInstance()->debug("Order " . $order->getRef() . ": confirmation email sent to customer.");

                $this->eventDispatcher->dispatch($event, TheliaEvents::ORDER_SEND_CONFIRMATION_EMAIL);
            }
        } else {
            Tlog::getInstance()->debug(
                "Order " . $event->getOrder()->getRef() . ": no confirmation email sent (order not paid, or not the proper payment module)."
            );
        }
    }

    /**
     * Send the confirmation message only if the order is paid.
     *
     * @param OrderEvent $event
     */
    public function checkSendOrderConfirmationMessageToCustomer(OrderEvent $event)
    {
        if (Paybox::getConfigValue('send_confirmation_email_on_successful_payment', false)) {
            $paybox = new Paybox();

            if ($paybox->isPaymentModuleFor($event->getOrder())) {
                if (!$event->getOrder()->isPaid()) {
                    $event->stopPropagation();
                }
            }
        }
    }

    public static function getSubscribedEvents()
    {
        return array(
            TheliaEvents::ORDER_UPDATE_STATUS => array("updateOrderStatus", 128),
            TheliaEvents::ORDER_SEND_CONFIRMATION_EMAIL => array("checkSendOrderConfirmationMessageToCustomer", 150)
        );
    }
}

<?php

namespace Paybox\EventListener;

use Paybox\Paybox;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\EventDispatcher\GenericEvent;
use Thelia\Model\ModuleConfigQuery;

class ConfigListener implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            'module.config' => [
                'onModuleConfig', 128
            ],
        ];
    }

    public function onModuleConfig(GenericEvent $event): void
    {
        $subject = $event->getSubject();

        if ($subject !== "HealthStatus") {
            throw new \RuntimeException('Event subject does not match expected value');
        }

        $configModule = ModuleConfigQuery::create()
            ->filterByModuleId(Paybox::getModuleId())
            ->filterByName(['numero_site', 'rang_site', 'identifiant_interne', 'clef_privee', 'url_serveur', 'url_serveur_test', 'url_retour_abandon', 'url_retour_succes', 'url_retour_refus', 'url_ipn', 'mode', 'minimum_amount', 'maximum_amount', 'send_confirmation_email_on_successful_payment'])
            ->find();

        $moduleConfig = [];

        $moduleConfig['module'] = Paybox::getModuleCode();
        $configsCompleted = true;

        if ($configModule->count() === 0) {
            $configsCompleted = false;
        }

        if (!isset($moduleConfig['clef_privee'])) {
            $configsCompleted = false;
        }

        foreach ($configModule as $config) {
            $moduleConfig[$config->getName()] = $config->getValue();
            if ($config->getValue() === null) {
                $configsCompleted = false;
            }
        }

        $moduleConfig['completed'] = $configsCompleted;

        $event->setArgument('paybox.module.config', $moduleConfig);
    }

}
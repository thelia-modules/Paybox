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

namespace Paybox\Form;

use Paybox\Paybox;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Validator\Constraints\GreaterThanOrEqual;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Url as UrlValidator;
use Thelia\Form\BaseForm;
use Thelia\Tools\URL;

/**
 * Paybox payment module
 *
 * @author Franck Allimant <franck@cqfdev.fr>
 */
class ConfigurationForm extends BaseForm
{
    protected function buildForm()
    {
        $this->formBuilder
            ->add(
                'numero_site',
                TextType::class,
                [
                    'constraints' => [new NotBlank()],
                    'label' => $this->translator->trans('Numéro du site', [], Paybox::MODULE_DOMAIN),
                    'data' => Paybox::getConfigValue('numero_site', '1234567'),
                    'label_attr' => [
                        'for' => 'numero_site',
                        'help' => $this->translator->trans('Numéro du site, tel que fourni par Paybox', [], Paybox::MODULE_DOMAIN)
                    ]
                ]
            )
            ->add(
                'rang_site',
                TextType::class,
                [
                    'constraints' => [ new NotBlank() ],
                    'label' => $this->translator->trans('Numéro de rang', [], Paybox::MODULE_DOMAIN),
                    'data' => Paybox::getConfigValue('rang_site', '1234567'),
                    'label_attr' => [
                        'for' => 'rang_site',
                        'help' => $this->translator->trans('Numéro de rang, tel que fourni par Paybox', [], Paybox::MODULE_DOMAIN)
                    ]
                ]
            )
            ->add(
                'identifiant_interne',
                TextType::class,
                [
                    'constraints' => [ new NotBlank() ],
                    'label' => $this->translator->trans('Identifiant interne', [], Paybox::MODULE_DOMAIN),
                    'data' => Paybox::getConfigValue('identifiant_interne', '123456789'),
                    'label_attr' => [
                        'for' => 'identifiant_interne',
                        'help' => $this->translator->trans('Identifiant interne, tel que fourni par Paybox', [], Paybox::MODULE_DOMAIN)
                    ]
                ]
            )
            ->add(
                'clef_privee',
                TextType::class,
                array(
                    'constraints' => [ new NotBlank() ],
                    'label' => $this->translator->trans('Clef privée d\'échange', [], Paybox::MODULE_DOMAIN),
                    'data' => Paybox::getConfigValue('clef_privee', ''),
                    'label_attr' => [
                        'for' => 'clef_privee',
                        'help' => $this->translator->trans('Voyez ci-dessous comment générer votre clef privée.', [], Paybox::MODULE_DOMAIN)
                    ]
                )
            )
            ->add(
                'url_serveur',
                UrlType::class,
                [
                    'constraints' => [ new NotBlank(), new UrlValidator() ],
                    'label' => $this->translator->trans('URL de production du serveur Paybox', [], Paybox::MODULE_DOMAIN),
                    'data' => Paybox::getConfigValue('url_serveur', "https://tpeweb.paybox.com/cgi/MYchoix_pagepaiement.cgi"),
                    'label_attr' => [
                       'for' => 'url_serveur',
                        'help' => $this->translator->trans('URL du serveur Paybox en mode production', [], Paybox::MODULE_DOMAIN)
                    ]
                ]
            )
            ->add(
                'url_serveur_test',
                UrlType::class,
                [
                    'constraints' => [ new NotBlank(), new UrlValidator() ],
                    'label' => $this->translator->trans('URL de test du serveur Paybox', [], Paybox::MODULE_DOMAIN),
                    'data' => Paybox::getConfigValue('url_serveur_test', "https://preprod-tpeweb.paybox.com/cgi/MYchoix_pagepaiement.cgi"),
                    'label_attr' => [
                        'for' => 'url_serveur_test',
                        'help' => $this->translator->trans('URL du serveur Paybox en mode test', [], Paybox::MODULE_DOMAIN)
                    ]
                ]
            )
            ->add(
                'url_retour_abandon',
                UrlType::class,
                [
                    'constraints' => [ new NotBlank(), new UrlValidator() ],
                    'label' => $this->translator->trans('URL de retour en cas d\'abandon', [], Paybox::MODULE_DOMAIN),
                    'data' => Paybox::getConfigValue('url_retour_abandon', URL::getInstance()->absoluteUrl('/paybox/cancel')),
                    'label_attr' => [
                        'for' => 'url_retour_abandon',
                        'help' => $this->translator->trans('URL de la page présentée à votre client en cas d\'abandon du paiement.', [], Paybox::MODULE_DOMAIN)
                    ]
                ]
            )
            ->add(
                'url_retour_succes',
                UrlType::class,
                [
                    'constraints' => [ new NotBlank(), new UrlValidator() ],
                    'label' => $this->translator->trans('URL de retour après un paiement réussi', [], Paybox::MODULE_DOMAIN),
                    'data' => Paybox::getConfigValue('url_retour_succes', URL::getInstance()->absoluteUrl('/paybox/success')),
                    'label_attr' => [
                        'for' => 'url_retour_succes',
                        'help' => $this->translator->trans('URL de la page présentée à votre client après un paiement réussi.', [], Paybox::MODULE_DOMAIN)
                    ]
                ]
            )
            ->add(
                'url_retour_refus',
                UrlType::class,
                [
                    'constraints' => [ new NotBlank(), new UrlValidator() ],
                    'label' => $this->translator->trans('URL de retour en cas de rejet du paiement', [], Paybox::MODULE_DOMAIN),
                    'data' => Paybox::getConfigValue('url_retour_refus', URL::getInstance()->absoluteUrl('/paybox/rejected')),
                    'label_attr' => [
                        'for' => 'url_retour_refus',
                        'help' => $this->translator->trans('URL de la page présentée à votre client après rejet de son paiement.', [], Paybox::MODULE_DOMAIN)
                    ]
                ]
            )
            ->add(
                'url_ipn',
                UrlType::class,
                [
                    'constraints' => [ new NotBlank(), new UrlValidator() ],
                    'label' => $this->translator->trans('URL IPN', [], Paybox::MODULE_DOMAIN),
                    'data' => Paybox::getConfigValue('url_ipn', URL::getInstance()->absoluteUrl('/paybox/callback')),
                    'label_attr' => [
                        'for' => 'url_ipn',
                        'help' => $this->translator->trans('URL appellée par la banque poour confirmer les commandes', [], Paybox::MODULE_DOMAIN)
                    ],
                    'attr' => [
                        // 'readonly' => 'readonly'
                    ]
                ]
            )
            ->add(
                'mode',
                ChoiceType::class,
                [
                    'constraints' => [ new NotBlank() ],
                    'choices' => [
                        $this->translator->trans('Test', [], Paybox::MODULE_DOMAIN) => 'TEST',
                        $this->translator->trans('Production', [], Paybox::MODULE_DOMAIN) => 'PRODUCTION',
                    ],
                    'label' => $this->translator->trans('Mode de fonctionnement', [], Paybox::MODULE_DOMAIN),
                    'data' => Paybox::getConfigValue('mode', 'TEST'),
                    'label_attr' => [
                        'for' => 'mode',
                        'help' => $this->translator->trans('En mode test, seuls les IPs ci-dessous peuvent accéder à ce module de paiement.', [], Paybox::MODULE_DOMAIN)
                    ]
                ]
            )

            ->add(
                'allowed_ip_list',
                TextareaType::class,
                [
                    'required' => false,
                    'label' => $this->translator->trans('Adresses IP autorisées en mode test', [], Paybox::MODULE_DOMAIN),
                    'data' => Paybox::getConfigValue('allowed_ip_list', $this->getRequest()->getClientIp()),
                    'label_attr' => [
                        'for' => 'platform_url',
                        'help' => $this->translator->trans(
                            'En mode test, liste des apdresses IP autorisées à utiliser le module de paiement en front office. Indiquer une adresse par ligne. Votre IP actuelle est %ip',
                            [ '%ip' => $this->getRequest()->getClientIp() ],
                            Paybox::MODULE_DOMAIN
                        ),
                        'attr' => [
                            'rows' => 3
                        ]
                    ]
                ]
            )
            ->add(
                'minimum_amount',
                TextType::class,
                [
                    'constraints' => [
                        new NotBlank(),
                        new GreaterThanOrEqual(['value' => 0])
                    ],
                    'label' => $this->translator->trans('Minimum order total', [], Paybox::MODULE_DOMAIN),
                    'data' => Paybox::getConfigValue('minimum_amount', '0'),
                    'label_attr' => [
                        'for' => 'minimum_amount',
                        'help' => $this->translator->trans('Minimum order total in the default currency for which this payment method is available. Enter 0 for no minimum', [], Paybox::MODULE_DOMAIN)
                    ]
                ]
            )
            ->add(
                'maximum_amount',
                TextType::class,
                [
                    'constraints' => [
                        new NotBlank(),
                        new GreaterThanOrEqual(['value' => 0])
                    ],
                    'label' => $this->translator->trans('Maximum order total', [], Paybox::MODULE_DOMAIN),
                    'data' => Paybox::getConfigValue('maximum_amount', '0'),
                    'label_attr' => [
                        'for' => 'maximum_amount',
                        'help' => $this->translator->trans('Maximum order total in the default currency for which this payment method is available. Enter 0 for no maximum', [], Paybox::MODULE_DOMAIN)
                    ]
                ]
            )
            ->add(
                'send_confirmation_email_on_successful_payment',
                CheckboxType::class,
                [
                    'required' => false,
                    'label' => $this->translator->trans('Envoi du mail de confirmation de commande sur paiement réussi', [], Paybox::MODULE_DOMAIN),
                    'data' => Paybox::getConfigValue('send_confirmation_email_on_successful_payment', '1') == '1',
                    'label_attr' => [
                        'for' => 'send_confirmation_email_on_successful_payment',
                        'help' => $this->translator->trans('Cochez cette case pour envoyer le mail de confirmation de commande à vos clients une fois que le paiement a été confirmé.', [], Paybox::MODULE_DOMAIN)
                    ]
                ]
            )
        ;
    }

    public static function getName()
    {
        return 'paybox_configuration_form';
    }
}

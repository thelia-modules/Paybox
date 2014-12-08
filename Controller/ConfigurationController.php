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

use Paybox\Form\ConfigurationForm;
use Paybox\Paybox;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Thelia\Controller\Admin\BaseAdminController;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Form\Exception\FormValidationException;
use Thelia\Tools\URL;

/**
 * Paybox payment module
 *
 * @author Franck Allimant <franck@cqfdev.fr>
 */
class ConfigurationController extends BaseAdminController
{
    public function displayConfigurationPage() {

        $logFilePath = sprintf(THELIA_ROOT."log".DS."%s.log", Paybox::MODULE_CODE);

        $traces = file_get_contents($logFilePath);

        if (false === $traces) {
            $traces = $this->getTranslator()->trans("Le fichier de log n'a pas été trouvé.", [], Paybox::MODULE_DOMAIN);
        }
        else if (empty($traces)) {
            $traces = $this->getTranslator()->trans("Le fichier de log est vide.", [], Paybox::MODULE_DOMAIN);
        }

        return $this->render('module-configure', [
                'module_code' => 'Paybox',
                'trace_content' => nl2br($traces)
            ]
        );
    }

    public function configure()
    {
        if (null !== $response = $this->checkAuth(AdminResources::MODULE, 'Paybox', AccessManager::UPDATE)) {
            return $response;
        }

        // Create the Form from the request
        $configurationForm = new ConfigurationForm($this->getRequest());

        try {
            // Check the form against constraints violations
            $form = $this->validateForm($configurationForm, "POST");

            // Get the form field values
            $data = $form->getData();

            foreach ($data as $name => $value) {
                if (is_array($value)) {
                    $value = implode(';', $value);
                }

                Paybox::setConfigValue($name, $value);
            }

            // Log configuration modification
            $this->adminLogAppend(
                "paybox.configuration.message",
                AccessManager::UPDATE,
                sprintf("Paybox configuration updated")
            );

            // Redirect to the success URL,
            if ($this->getRequest()->get('save_mode') == 'stay') {
                // If we have to stay on the same page, redisplay the configuration page/
                $route = '/admin/module/Paybox';
            } else {
                // If we have to close the page, go back to the module back-office page.
                $route = '/admin/modules';
            }

            return new RedirectResponse(URL::getInstance()->absoluteUrl($route));

        } catch (FormValidationException $ex) {
            // Form cannot be validated. Create the error message using
            // the BaseAdminController helper method.
            $error_msg = $this->createStandardFormValidationErrorMessage($ex);
        }
        catch (\Exception $ex) {
              // Any other error
             $error_msg = $ex->getMessage();
        }

        // At this point, the form has errors, and should be redisplayed. We don not redirect,
        // just redisplay the same template.
        // Setup the Form error context, to make error information available in the template.
        $this->setupFormErrorContext(
            $this->getTranslator()->trans("Paybox configuration", [], Paybox::MODULE_DOMAIN),
            $error_msg,
            $configurationForm,
            $ex
        );

        // Do not redirect at this point, or the error context will be lost.
        // Just redisplay the current template.
        return $this->displayConfigurationPage();
    }
}

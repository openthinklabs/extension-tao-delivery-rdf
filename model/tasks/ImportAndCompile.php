<?php

/**
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; under version 2
 * of the License (non-upgradable).
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 *
 * Copyright (c) 2017-2020 (original work) Open Assessment Technologies SA;
 */

namespace oat\taoDeliveryRdf\model\tasks;

use Exception;
use JsonSerializable;
use common_Logger as Logger;
use common_report_Report as Report;
use common_exception_Error as Error;
use oat\oatbox\service\ServiceManager;
use oat\oatbox\task\AbstractTaskAction;
use common_Exception as CommonException;
use oat\generis\model\OntologyAwareTrait;
use oat\tao\model\import\ImportersService;
use core_kernel_classes_Class as CoreClass;
use oat\tao\model\taskQueue\QueueDispatcher;
use core_kernel_classes_Resource as Resource;
use oat\taoDeliveryRdf\model\DeliveryFactory;
use oat\taoTests\models\import\AbstractTestImporter;
use oat\taoDeliveryRdf\model\DeliveryAssemblyService;
use oat\tao\model\taskQueue\Task\CallbackTaskInterface;
use oat\oatbox\service\exception\InvalidServiceManagerException;
use common_exception_InconsistentData as InconsistentDataException;
use common_exception_MissingParameter as MissingParameterException;
use taoQtiTest_models_classes_QtiTestService as QtiTestService;
use Throwable;

/**
 * Class ImportAndCompile
 * Action to import test and compile it into delivery
 *
 * @package oat\taoDeliveryRdf\model\tasks
 *
 * @author Aleh Hutnikau, <hutnikau@1pt.com>
 */
class ImportAndCompile extends AbstractTaskAction implements JsonSerializable
{
    use OntologyAwareTrait;

    public const FILE_DIR = 'ImportAndCompileTask';
    private const OPTION_FILE = 'file';
    private const OPTION_IMPORTER = 'importer';
    private const OPTION_CUSTOM = 'custom';
    private const OPTION_DELIVERY_LABELS = 'delivery-class-labels';

    /**
     * @param array $params
     *
     * @throws Error
     * @throws InconsistentDataException
     * @throws MissingParameterException
     *
     * @return Report
     */
    public function __invoke($params)
    {
        $this->checkParams($params);

        /** @var string[] $customParams */
        $customParams = $params[self::OPTION_CUSTOM];

        $file = $this->getFileReferenceSerializer()->unserializeFile($params[self::OPTION_FILE]);
        $report = null;
        $test = null;
        $importer = $this->getImporter($params[self::OPTION_IMPORTER]);

        try {
            /** @var Report $report */
            $report = $importer->import($file);

            if ($report->getType() === Report::TYPE_SUCCESS) {
                foreach ($report as $r) {
                    /** @var Resource $test */
                    $test = $r->getData()->rdfsResource;
                }
            } else {
                throw new CommonException(
                    $file->getBasename() . ' Unable to import test with message ' . $report->getMessage()
                );
            }

            $label = 'Delivery of ' . $test->getLabel();
            $parent = $this->checkSubClasses($params[self::OPTION_DELIVERY_LABELS]);
            $deliveryFactory = $this->getServiceManager()->get(DeliveryFactory::SERVICE_ID);
            $compilationReport = $deliveryFactory->create($parent, $test, $label, null, $customParams);

            if ($compilationReport->getType() == Report::TYPE_ERROR) {
                Logger::i(
                    'Unable to generate delivery execution into taoDeliveryRdf::RestDelivery for test uri '
                    . $test->getUri()
                );
            }
            /** @var Resource $delivery */
            $delivery = $compilationReport->getData();

            if ($delivery instanceof Resource && is_array($customParams)) {
                foreach ($customParams as $rdfKey => $rdfValue) {
                    $property = $this->getProperty($rdfKey);
                    $delivery->editPropertyValues($property, $rdfValue);
                }
            }

            $report->add($compilationReport);
            $report->setData(['delivery-uri' => $delivery->getUri()]);

            return $report;
        } catch (Exception $e) {
            Logger::singleton()->handleException($e);
        } catch (Throwable $e) {
            Logger::f($e->getMessage(), [
                Logger::CONTEXT_EXCEPTION => get_class($e),
                Logger::CONTEXT_ERROR_FILE => $e->getFile(),
                Logger::CONTEXT_ERROR_LINE => $e->getLine(),
                Logger::CONTEXT_TRACE => $e->getTrace()
            ]);
        } finally {
            if (!isset($e)) {
                return $report;
            }

            if (null !== $report) {
                $this->getQtiTestService()->clearRelatedResources($report);
            }

            $detailedErrorReport = Report::createFailure($e->getMessage());

            if ($report) {
                $errors = $report->getErrors();

                foreach ($errors as $error) {
                    $detailedErrorReport->add($error->getErrors());
                }
            }

            return $detailedErrorReport;
        }
    }

    /**
     * Create task in queue
     *
     * @param string $importerId test importer identifier
     * @param array $file uploaded file @see \tao_helpers_Http::getUploadedFile()
     * @param array $customParams
     * @param array $deliveryClassLabels
     *
     * @return CallbackTaskInterface
     */
    public static function createTask(
        string $importerId,
        array $file,
        array $customParams = [],
        array $deliveryClassLabels = []
    ): CallbackTaskInterface {
        $serviceManager = ServiceManager::getServiceManager();
        $action = new self();
        $action->setServiceLocator($serviceManager);

        $importersService = $serviceManager->get(ImportersService::SERVICE_ID);
        $importersService->getImporter($importerId);

        $fileUri = $action->saveFile($file['tmp_name'], $file['name']);
        /** @var QueueDispatcher $queueDispatcher */
        $queueDispatcher = ServiceManager::getServiceManager()->get(QueueDispatcher::SERVICE_ID);
        $taskParameters = [
            self::OPTION_FILE => $fileUri,
            self::OPTION_IMPORTER => $importerId,
            self::OPTION_CUSTOM => $customParams,
            self::OPTION_DELIVERY_LABELS => $deliveryClassLabels,
        ];
        $taskTitle = __('Import QTI test and create delivery.');;

        return $queueDispatcher->createTask($action, $taskParameters, $taskTitle, null, true);
    }

    /**
     * @return string
     */
    public function jsonSerialize()
    {
        return __CLASS__;
    }

    /**
     * @param array $params
     *
     * @throws InvalidServiceManagerException
     * @throws InconsistentDataException
     * @throws MissingParameterException
     */
    private function checkParams(array $params): void
    {
        foreach ([self::OPTION_FILE, self::OPTION_IMPORTER] as $param) {
            if (!isset($params[$param])) {
                throw new MissingParameterException(sprintf(
                    'Missing parameter `%s` in %s',
                    $param,
                    self::class
                ));
            }
        }

        $importer = $this->getImporter($params[self::OPTION_IMPORTER]);

        if (!$importer instanceof AbstractTestImporter) {
            throw new InconsistentDataException(sprintf(
                'Wrong importer `%s`',
                $params[self::OPTION_IMPORTER]
            ));
        }
    }

    /**
     * @param array $classLabels
     *
     * @return CoreClass
     */
    private function checkSubClasses(array $classLabels = []): CoreClass
    {
        $parent = $this->determineParentClass(new CoreClass(DeliveryAssemblyService::CLASS_URI), $classLabels);

        if (!empty($classLabels)) {
            foreach ($classLabels as $classLabel) {
                $parent = $parent->createSubClass($classLabel);
            }
        }

        return $parent;
    }

    /**
     * @param CoreClass $parent
     * @param array $classLabels
     * @param int $level
     *
     * @return CoreClass
     */
    private function determineParentClass(CoreClass $parent, array &$classLabels): CoreClass
    {
        if (empty($classLabels)) {
            return $parent;
        }

        foreach ($parent->getSubClasses(true) as $deliveryClass) {
            if (isset($classLabels[0]) && $classLabels[0] === $deliveryClass->getLabel()) {
                $classLabels = array_slice($classLabels, 1);
                $parent = $this->determineParentClass($deliveryClass, $classLabels) ?? $parent;

                break;
            }
        }

        return $parent;
    }

    /**
     * @param string $id
     *
     * @throws InvalidServiceManagerException
     *
     * @return mixed
     */
    private function getImporter(string $id)
    {
        $importersService = $this->getServiceManager()->get(ImportersService::SERVICE_ID);

        return $importersService->getImporter($id);
    }

    private function getQtiTestService(): QtiTestService
    {
        return $this->getServiceLocator()->get(QtiTestService::class);
    }
}

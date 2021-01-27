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
 * Copyright (c) 2021 (original work) Open Assessment Technologies SA;
 */

declare(strict_types=1);

namespace oat\taoDeliveryRdf\model\DataStore;

use core_kernel_classes_Property;
use core_kernel_classes_Resource;
use http\Exception\RuntimeException;
use oat\oatbox\event\Event;
use oat\oatbox\log\LoggerAwareTrait;
use oat\oatbox\service\ConfigurableService;
use oat\oatbox\service\exception\InvalidServiceManagerException;
use oat\tao\model\metadata\compiler\ResourceJsonMetadataCompiler;
use oat\tao\model\taskQueue\QueueDispatcher;
use oat\taoDeliveryRdf\model\DeliveryAssemblyService;
use oat\taoDeliveryRdf\model\event\AbstractDeliveryEvent;
use taoQtiTest_models_classes_QtiTestService;
use Throwable;


class DataStoreService extends ConfigurableService
{
    use LoggerAwareTrait;

    public function processMetaData(Event $event): void
    {
        try {
            $this->logDebug(sprintf('Processing MetaData event for %s', get_class($event)));
            $this->checkEventType($event);
            $compiler = $this->getMetaDataCompiler();

            $params['deliveryId'] = $event->getDeliveryUri();
            $params['count'] = 0;

            //DeliveryMetaData
            $deliveryResource = new core_kernel_classes_Resource($event->getDeliveryUri());
            $params['deliveryMetaData'] = $compiler->compile($deliveryResource);

            //test MetaData
            $testProperty = new core_kernel_classes_Property(DeliveryAssemblyService::PROPERTY_ORIGIN);
            $testUri = $deliveryResource->getOnePropertyValue($testProperty)->getUri();
            $test = new core_kernel_classes_Resource($testUri);
            $params['testMetaData'] = $compiler->compile($test);

            //items
            /** @var taoQtiTest_models_classes_QtiTestService $testService */
            $testService = $this->getServiceLocator()->get(taoQtiTest_models_classes_QtiTestService::class);
            $items = $testService->getItems($test);
            $params['itemMetaData'] = [];
            foreach ($items as $item) {
                $params['itemMetaData'][$item->getUri()] = $compiler->compile($item);
            }
            $this->triggerSyncTask($params);
            $this->logDebug(sprintf('Event %s processed', get_class($event)));
        } catch (Throwable $exception) {
            $this->logError(sprintf('Error processing event %s: %s', get_class($event), $exception->getMessage()));
        }
    }

    /**
     * @throws InvalidServiceManagerException
     */
    private function getQueueDispatcher(): ConfigurableService
    {
        return $this->getServiceManager()->get(QueueDispatcher::SERVICE_ID);
    }

    /**
     * @throws RuntimeException
     */
    private function checkEventType(Event $event): void
    {
        if (!$event instanceof AbstractDeliveryEvent) {
            throw new RuntimeException($event);
        }
    }

    private function triggerSyncTask(iterable $params): void
    {
        /** @var QueueDispatcher $queueDispatcher */
        $queueDispatcher = $this->getQueueDispatcher();
        $queueDispatcher->createTask(
            new GCPDeliverySyncTask(),
            $params,
            __('Continue try to sync GCP of delivery "%s".', $params['deliveryId'])
        );
    }

    private function getMetaDataCompiler(): ResourceJsonMetadataCompiler
    {
        return $this->getServiceLocator()->get(ResourceJsonMetadataCompiler::SERVICE_ID);
    }
}

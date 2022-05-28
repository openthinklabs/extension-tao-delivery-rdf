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
 */

namespace oat\taoDeliveryRdf\controller;

use common_Exception as BaseException;
use common_exception_MethodNotAllowed as HttpMethodNotAllowedException;
use common_exception_ResourceNotFound as ResourceNotFoundException;
use common_exception_RestApi as BadRequestException;
use core_kernel_classes_Class as KernelClass;
use core_kernel_classes_Resource as kernelResource;
use oat\generis\model\kernel\persistence\smoothsql\search\ComplexSearchService;
use oat\oatbox\event\EventManager;
use oat\search\base\exception\SearchGateWayExeption;
use oat\tao\model\taskQueue\QueueDispatcher;
use oat\tao\model\taskQueue\TaskLog\Broker\TaskLogBrokerInterface;
use oat\tao\model\taskQueue\TaskLog\Entity\EntityInterface;
use oat\tao\model\taskQueue\TaskLog\TaskLogFilter;
use oat\tao\model\taskQueue\TaskLogActionTrait;
use oat\tao\model\taskQueue\TaskLogInterface;
use oat\taoDeliveryRdf\model\Delete\DeliveryDeleteTask;
use oat\taoDeliveryRdf\model\Delivery\Business\Service\DeliveryService;
use oat\taoDeliveryRdf\model\Delivery\Presentation\Web\RequestHandler\DeliveryPatchRequestHandler;
use oat\taoDeliveryRdf\model\DeliveryAssemblyService;
use oat\generis\model\OntologyRdfs;
use oat\taoDeliveryRdf\model\DeliveryFactory;
use oat\taoDeliveryRdf\model\tasks\CompileDelivery;
use oat\taoDeliveryRdf\model\tasks\UpdateDelivery;
use oat\generis\model\OntologyAwareTrait;
use oat\taoDeliveryRdf\controller\RestDelivery;
use Request;
use RuntimeException;
use tao_models_classes_dataBinding_GenerisFormDataBindingException as FormDataBindingException;

class RestDeliveryCustom extends RestDelivery
{
    use TaskLogActionTrait;
    use OntologyAwareTrait;

    public function assignDelivery2Group()
    { 
        $resource = $this->getResource($this->getRequestParameter('resourceUri')); //Group Resource URI 
        $property = $this->getProperty("http://www.tao.lu/Ontologies/TAOGroup.rdf#Deliveries");
        $resource_delivery_uri = [$this->getProperty($this->getRequestParameter('resourceDeliveryUri'))]; //Delivery URI
        $success = $resource->editPropertyValues($property, $resource_delivery_uri);

        return $this->returnJson(['saved'  => $success ]);
    }    
}

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

use common_Exception;
use common_exception_Error;
use common_exception_NotFound;
use oat\oatbox\filesystem\FileSystem;
use oat\oatbox\filesystem\FileSystemService;
use oat\oatbox\service\ConfigurableService;
use oat\tao\helpers\FileHelperService;
use tao_helpers_Uri;
use tao_models_classes_export_ExportHandler as ExporterInterface;
use oat\taoQtiTest\models\export\Formats\Package2p2\TestPackageExport;
use Throwable;

class PersistDataService extends ConfigurableService
{
    private const PACKAGE_FILENAME = 'QTIPackage';
    private const ZIP_EXTENSION = '.zip';
    public const  OPTION_EXPORTER_SERVICE = 'exporter_service';

    /**
     * @throws Throwable
     * @throws common_Exception
     * @throws common_exception_Error
     * @throws common_exception_NotFound
     */
    public function persist(array $params): void
    {
        $this->persistArchive($params[MetaDataDeliverySyncTask::DELIVERY_OR_TEST_ID_PARAM_NAME], $params);
    }

    /**
     * @throws common_exception_NotFound
     * @throws common_exception_Error
     */
    public function remove(array $params): void
    {
        $this->removeArchive(
            $params[MetaDataDeliverySyncTask::DELIVERY_OR_TEST_ID_PARAM_NAME],
            $params[MetaDataDeliverySyncTask::FILE_SYSTEM_ID_PARAM_NAME],
            $this->getTenantId($params),
        );
    }

    /**
     * @throws common_exception_Error
     * @throws common_exception_NotFound
     */
    private function getDataStoreFilesystem(string $fileSystemId): FileSystem
    {
        return $this->getFileSystemManager()->getFileSystem($fileSystemId);
    }

    private function getFolderName(string $deliveryOrTestId): string
    {
        return tao_helpers_Uri::encode($deliveryOrTestId);
    }

    /**
     * @throws common_exception_Error
     * @throws common_exception_NotFound
     */
    private function removeArchive(string $deliveryOrTestId, string $fileSystemId, string $tenantId): void
    {
        $directoryPath =  $this->getZipFileDirectory($deliveryOrTestId, $tenantId);

        if ($this->getDataStoreFilesystem($fileSystemId)->has($directoryPath)) {
            $this->getDataStoreFilesystem($fileSystemId)->deleteDir(
                $directoryPath
            );
        }
    }

    /**
     * @throws Throwable
     * @throws common_Exception
     * @throws common_exception_Error
     * @throws common_exception_NotFound
     */
    private function persistArchive(string $deliveryOrTestId, array $params): void
    {
        /** @var FileHelperService $tempDir */
        $tempDir = $this->getServiceLocator()->get(FileHelperService::class);
        $folder = $tempDir->createTempDir();

        try {
            $this->getTestExporter()->export(
                [
                    'filename' => self::PACKAGE_FILENAME,
                    'instances' => $params['testUri'],
                    'uri' => $params['testUri']
                ],
                $folder
            );

            $this->moveExportedZipTest($folder, $deliveryOrTestId, $params);
        } finally {
            $tempDir->removeDirectory($folder);
        }
    }

    /**
     * @throws common_exception_Error
     * @throws common_exception_NotFound
     */
    private function moveExportedZipTest(string $folder, string $deliveryOrTestId, array $params): void
    {
        $zipFiles = glob(
            sprintf('%s%s*%s', $folder, self::PACKAGE_FILENAME, self::ZIP_EXTENSION)
        );

        $fileSystemId = $params[MetaDataDeliverySyncTask::FILE_SYSTEM_ID_PARAM_NAME];

        if (!empty($zipFiles)) {
            foreach ($zipFiles as $zipFile) {
                $zipFileName = $this->getZipFileName($deliveryOrTestId, $this->getTenantId($params));
                $this->getProcessDataService()->process($zipFile, $params);

                $contents = file_get_contents($zipFile);

                if ($this->getDataStoreFilesystem($fileSystemId)->has($zipFileName)) {
                    $this->getDataStoreFilesystem($fileSystemId)->update(
                        $zipFileName,
                        $contents
                    );
                } else {
                    $this->getDataStoreFilesystem($fileSystemId)->write(
                        $zipFileName,
                        $contents
                    );
                }
            }
        }
    }

    private function getTenantId(array $params): string
    {
        if (!empty($params[MetaDataDeliverySyncTask::TENANT_ID_PARAM_NAME])) {
            return $params[MetaDataDeliverySyncTask::TENANT_ID_PARAM_NAME];
        }

        if (!empty($params[MetaDataDeliverySyncTask::FIRST_TENANT_ID_PARAM_NAME])) {
            return $params[MetaDataDeliverySyncTask::FIRST_TENANT_ID_PARAM_NAME];
        }

        return "";
    }

    private function getTestExporter(): ExporterInterface
    {
        $exporter = $this->getOption(self::OPTION_EXPORTER_SERVICE);

        if ($exporter) {
            return $exporter;
        }

        return new TestPackageExport();
    }

    private function getFileSystemManager(): FileSystemService
    {
        return $this->getServiceLocator()->get(FileSystemService::SERVICE_ID);
    }

    private function getProcessDataService(): ProcessDataService
    {
        return $this->getServiceLocator()->get(ProcessDataService::class);
    }

    private function getZipFileName(string $deliveryOrTestId, string $tenantId): string
    {
        return sprintf(
            '%s%s%s',
            $this->getZipFileDirectory($deliveryOrTestId, $tenantId),
            self::PACKAGE_FILENAME,
            self::ZIP_EXTENSION
        );
    }

    private function getZipFileDirectory(string $deliveryOrTestId, string $tenantId): string
    {
        return sprintf(
            '%s-%s%s',
            $this->getFolderName($deliveryOrTestId),
            $tenantId,
            DIRECTORY_SEPARATOR,
        );
    }
}

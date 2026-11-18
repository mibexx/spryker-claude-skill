<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace Pyz\Zed\ProductBadgeDataImport\Communication\Plugin\DataImport;

use Generated\Shared\Transfer\DataImporterConfigurationTransfer;
use Generated\Shared\Transfer\DataImporterReportTransfer;
use Pyz\Zed\ProductBadgeDataImport\ProductBadgeDataImportConfig;
use Spryker\Zed\DataImport\Dependency\Plugin\DataImportPluginInterface;
use Spryker\Zed\Kernel\Communication\AbstractPlugin;

/**
 * @method \Pyz\Zed\ProductBadgeDataImport\Business\ProductBadgeDataImportFacadeInterface getFacade()
 * @method \Pyz\Zed\ProductBadgeDataImport\ProductBadgeDataImportConfig getConfig()
 */
class ProductBadgeDataImportPlugin extends AbstractPlugin implements DataImportPluginInterface
{
    /**
     * {@inheritDoc}
     *
     * @api
     */
    public function import(?DataImporterConfigurationTransfer $dataImporterConfigurationTransfer = null): DataImporterReportTransfer
    {
        return $this->getFacade()->importProductBadge($dataImporterConfigurationTransfer);
    }

    /**
     * {@inheritDoc}
     *
     * @api
     */
    public function getImportType(): string
    {
        return ProductBadgeDataImportConfig::IMPORT_TYPE_PRODUCT_BADGE;
    }
}

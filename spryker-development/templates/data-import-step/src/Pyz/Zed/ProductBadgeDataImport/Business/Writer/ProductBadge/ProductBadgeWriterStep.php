<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace Pyz\Zed\ProductBadgeDataImport\Business\Writer\ProductBadge;

use Orm\Zed\ProductBadge\Persistence\SpyProductBadgeQuery;
use Pyz\Shared\ProductBadgeStorage\ProductBadgeStorageConfig;
use Pyz\Zed\ProductBadgeDataImport\Business\Writer\ProductBadge\DataSet\ProductBadgeDataSetInterface;
use Spryker\Zed\DataImport\Business\Exception\InvalidDataException;
use Spryker\Zed\DataImport\Business\Model\DataImportStep\DataImportStepInterface;
use Spryker\Zed\DataImport\Business\Model\DataImportStep\PublishAwareStep;
use Spryker\Zed\DataImport\Business\Model\DataSet\DataSetInterface;

/**
 * Writer step: idempotent upsert on the natural key (fk_product_abstract + badge_key).
 *
 * Extends PublishAwareStep: DataImportEventBehaviorPlugin disables entity events during the import,
 * so the storage publish event is collected here and fired after the import by DataImportPublisherPlugin.
 */
class ProductBadgeWriterStep extends PublishAwareStep implements DataImportStepInterface
{
    protected const array REQUIRED_DATA_SET_KEYS = [
        ProductBadgeDataSetInterface::ID_PRODUCT_ABSTRACT,
        ProductBadgeDataSetInterface::COLUMN_BADGE_KEY,
        ProductBadgeDataSetInterface::COLUMN_LABEL,
    ];

    public function execute(DataSetInterface $dataSet): void
    {
        $this->validateDataSet($dataSet);

        $productBadgeEntity = SpyProductBadgeQuery::create()
            ->filterByFkProductAbstract($dataSet[ProductBadgeDataSetInterface::ID_PRODUCT_ABSTRACT])
            ->filterByBadgeKey($dataSet[ProductBadgeDataSetInterface::COLUMN_BADGE_KEY])
            ->findOneOrCreate();

        $productBadgeEntity
            ->setLabel($dataSet[ProductBadgeDataSetInterface::COLUMN_LABEL])
            ->save();

        $this->addPublishEvents(
            ProductBadgeStorageConfig::PRODUCT_BADGE_PUBLISH,
            $dataSet[ProductBadgeDataSetInterface::ID_PRODUCT_ABSTRACT],
        );
    }

    protected function validateDataSet(DataSetInterface $dataSet): void
    {
        foreach (static::REQUIRED_DATA_SET_KEYS as $requiredDataSetKey) {
            if (!$dataSet[$requiredDataSetKey]) {
                throw new InvalidDataException(sprintf('"%s" is required.', $requiredDataSetKey));
            }
        }
    }
}

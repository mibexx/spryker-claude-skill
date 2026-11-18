<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace Pyz\Zed\ProductBadgeGui\Communication\Plugin\Acl;

use Generated\Shared\Transfer\GroupTransfer;
use Generated\Shared\Transfer\RoleTransfer;
use Generated\Shared\Transfer\RuleTransfer;
use Spryker\Shared\Acl\AclConstants;
use Spryker\Zed\AclExtension\Dependency\Plugin\AclInstallerPluginInterface;

class ProductBadgeGuiAclInstallerPlugin implements AclInstallerPluginInterface
{
    protected const GROUP_NAME = 'product_badge_managers';

    protected const ROLE_NAME = 'product_badge_manager_role';

    protected const BUNDLE = 'product-badge-gui';

    /**
     * @return array<\Generated\Shared\Transfer\RoleTransfer>
     */
    public function getRoles(): array
    {
        return [
            (new RoleTransfer())
                ->setName(static::ROLE_NAME)
                ->setAclGroup($this->createGroupTransfer())
                ->addAclRule(
                    (new RuleTransfer())
                        ->setBundle(static::BUNDLE)
                        ->setController(AclConstants::VALIDATOR_WILDCARD)
                        ->setAction(AclConstants::VALIDATOR_WILDCARD)
                        ->setType(AclConstants::ALLOW),
                ),
        ];
    }

    /**
     * @return array<\Generated\Shared\Transfer\GroupTransfer>
     */
    public function getGroups(): array
    {
        return [$this->createGroupTransfer()];
    }

    protected function createGroupTransfer(): GroupTransfer
    {
        return (new GroupTransfer())
            ->setName(static::GROUP_NAME)
            ->setReference(static::GROUP_NAME);
    }
}

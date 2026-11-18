<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace Pyz\Zed\Acl;

use Pyz\Zed\ProductBadgeGui\Communication\Plugin\Acl\ProductBadgeGuiAclInstallerPlugin;
use Spryker\Zed\Acl\AclDependencyProvider as SprykerAclDependencyProvider;

class AclDependencyProvider extends SprykerAclDependencyProvider
{
    /**
     * @return array<\Spryker\Zed\AclExtension\Dependency\Plugin\AclInstallerPluginInterface>
     */
    protected function getAclInstallerPlugins(): array
    {
        return [
            new ProductBadgeGuiAclInstallerPlugin(),
        ];
    }
}

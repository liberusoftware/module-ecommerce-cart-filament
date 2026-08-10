<?php

namespace Liberu\Ecommerce\Cart\Filament\Tests\Fixtures;

use Filament\Panel;
use Filament\PanelProvider;
use Liberu\Ecommerce\Cart\Filament\CartPlugin;

/**
 * The panel this package's resource needs in order to be a resource at all.
 *
 * The package ships a plugin and the application composes the panel, so the
 * suite composes the smallest panel that attaches the plugin — under the `admin`
 * id `module.json`'s `presentation.filament` key names, which is the composition
 * this repository is actually claiming works.
 *
 * Deliberately not tenant-aware, and deliberately not opting in to Filament's
 * read-only relation managers on view pages. The plugin must work on a panel
 * with no tenancy, because nothing in the manifest says the host's panel has
 * any — and `LinesRelationManager::isReadOnly()` must hold without the panel
 * setting, because nothing in the manifest says the host's panel sets it.
 */
final class TestPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->plugins([CartPlugin::make()]);
    }
}

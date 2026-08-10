<?php

namespace Liberu\Ecommerce\Cart\Filament;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Liberu\Ecommerce\Cart\Filament\Resources\CartResource;
use Liberu\Ecommerce\Cart\Filament\Widgets\CartsOverview;

/**
 * What this package contributes to a panel the application composes.
 *
 * One resource and one widget, listed rather than discovered by directory scan:
 * discovery reads the filesystem on every boot to rediscover a set that is fixed
 * at release, and a scan rooted at `src/` would also sweep up anything a later
 * version happens to put there. `tests/Feature/PanelTest.php` asserts the list is
 * exactly what `src/Resources` and `src/Widgets` hold, so a class added later and
 * left out of the list fails a build rather than being silently unreachable.
 *
 * There is no `CartItemResource`, and that is deliberate — see
 * `docs/domain.md`. A line is meaningless without the cart holding it, the
 * domain's policy authorizes a line only through
 * `CartPolicy::updateLine()`, and `CartPolicy::view()` is typed against `Cart`,
 * so a resource over `CartItem` would be a surface whose every default gate call
 * raises a `TypeError` or answers about the wrong model.
 */
final class CartPlugin implements Plugin
{
    public static function make(): self
    {
        return new self();
    }

    public function getId(): string
    {
        return 'liberu-ecommerce-cart';
    }

    public function register(Panel $panel): void
    {
        $panel
            ->resources([
                CartResource::class,
            ])
            ->widgets([
                CartsOverview::class,
            ]);
    }

    public function boot(Panel $panel): void {}
}

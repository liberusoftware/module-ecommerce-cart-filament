# Adoption

Installing this package, enabling it, attaching it to a panel, and what the host has to supply.

## 1. Install

The domain package this presents is **not on Packagist**. Composer honours `repositories` only from
the root manifest, so the entry goes in the *application's* `composer.json`, not in this package's —
this package declares it for its own CI and that declaration does nothing for a consumer.

```bash
composer config repositories.ecommerce-cart vcs https://github.com/liberusoftware/module-ecommerce-cart
composer require liberusoftware/ecommerce-cart-filament
```

That pulls `liberusoftware/ecommerce-cart` with it. When the domain package reaches Packagist, the
`composer config repositories.*` line is the only thing to remove.

## 2. Enable the modules

Installing boots nothing: neither package ships `extra.laravel.providers`, so Composer discovery finds
no provider. `ModuleManagerServiceProvider` registers the provider each `module.json` names, and only
when the deployment asks for it:

```dotenv
MODULES_ENABLED=ecommerce-cart,ecommerce-cart-filament
```

Both, in that order. The presentation package registers nothing of its own — no migrations, no
policies, no config — so enabling it without the domain module gives you a resource with no tables to
query and no gate to ask.

## 3. Migrate

The domain module's single migration is loaded by `CartServiceProvider`:

```bash
php artisan migrate
```

Both `Schema::create` calls in it are guarded by `hasTable`, and **that guard is a trap on this
module in particular**. The host already has a `cart_items` table, and the host's is an *older* shape
than the module's — `MIGRATION_PLAN.md` §wave-1.5 describes the merge that gave the module's version
`guest_token` and a nullable `user_id`. Left in place, the guard silently leaves the module pointing
at a table with no `cart_id`.

**The host must delete its own `cart_items` migration** and let the module's create it. `carts` is
new and needs nothing from the host.

Nothing in *this* package migrates anything.

## 4. Attach the plugin to a panel

The application owns its panels; this package never registers itself into one.

```php
use Filament\Panel;
use Filament\PanelProvider;
use Liberu\Ecommerce\Cart\Filament\CartPlugin;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('admin')
            ->path('admin')
            ->plugins([
                CartPlugin::make(),
            ]);
    }
}
```

`module.json` declares the plugin under `presentation.filament.admin`, which is the panel id this
package is tested against — but nothing enforces it. Attach it to whichever panel should carry carts,
and to more than one if that is what the deployment needs.

The panel does **not** need to be tenant-aware. This resource scopes on the actor's `current_team_id`
rather than on `Filament::getTenant()`, and `isScopedToTenant()` is `false`.

The panel also does **not** need
`readOnlyRelationManagersOnResourceViewPagesByDefault()`. `LinesRelationManager::isReadOnly()` is
unconditionally true, because a package must not depend on a host having opted in to the one setting
standing between staff and a shopper's basket.

## 5. What the host has to supply

| Thing | Why | What happens without it |
| --- | --- | --- |
| A `current_team_id` on the authenticated user | It is the staff half of `CartPolicy`, and the column the resource query scopes on | Every list is empty and the dashboard widget is hidden. Not an error — the deliberate answer for an actor working in no team |
| A `team_id` on the carts staff should see | A cart belonging to no team is nobody's, and the policy denies every action on one | Those carts are invisible to every panel. Set it wherever carts are created, from the store or the request |
| `MODULES_ENABLED` naming both modules | Installation never implies boot | The resource exists as a class and appears nowhere |
| Colour aliases `success`, `warning`, `danger`, `info`, `gray` on the panel | Badge and notification colours | Filament's defaults apply |

Optional:

| Thing | Effect |
| --- | --- |
| `CART_TEAM_MODEL`, if the host's team model is not `App\Models\Team` | The domain resolves it from config at call time and never imports it. Only matters if something eager-loads the `team` relation; nothing in this package does |
| `CART_GUEST_TTL_DAYS` | Moves the line between **Live** and **Stale**, and therefore which carts offer the *Mark abandoned* action and what the dashboard's *Gone quiet* number counts |
| `CART_TELEMETRY=true` | The domain's own event logger starts recording, including the `CartRecalculated` and `CartAbandoned` this package's two actions dispatch. Off by default; a busy storefront writes one record per add-to-cart |

**`CART_PRODUCT_MODEL` changes nothing here.** This package never dereferences a product — see
[domain.md](domain.md#10-known-limitation-a-product-is-a-number).

## 6. What it does not bring

- **No way to change a shopper's cart.** No quantity field, no line removal, no discount form, no
  create page, no edit page. That is the design and not a gap; the reasoning is in
  [domain.md](domain.md#1-the-decision-a-cart-is-the-shoppers).
- **No catalogue.** Products and variants are numbers. Cart does not depend on Catalog and neither
  does this.
- **No checkout.** Converting a cart is checkout's move, not a panel's.
- **No storefront.** Everything here is the operator's side.
- **No abandoned-cart emails.** This package marks a cart abandoned and dispatches the domain's
  `CartAbandoned`; what listens is the host's.

## Upgrading

This is the first release; there is nothing to upgrade from.

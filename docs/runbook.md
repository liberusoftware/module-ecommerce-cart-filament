# Runbook

What goes wrong in production with this package, what it looks like, and what to do. Ordered by how
often it happens.

## Carts do not appear in the panel at all

**Looks like:** no Commerce navigation group, no carts page, no 404 either — the routes simply do not
exist.

**Almost always:** the plugin is not attached. This package registers nothing into a panel on its
own; the application composes its panels and attaches `CartPlugin::make()` to one. Check the panel
provider.

**Otherwise:** `MODULES_ENABLED` does not name `ecommerce-cart-filament`, so
`ModuleManagerServiceProvider` never registered the provider. Installation never implies boot here.

**Not the cause:** a stale component cache. This package registers a literal list rather than
discovering by directory scan, so `filament:cache-components` has nothing to go stale. Clearing it
will not bring the resource back.

```bash
php artisan about                   # does the module appear as enabled?
php artisan route:list --path=carts # do the routes exist?
```

## The list is empty, and there are definitely carts

**Looks like:** the page renders, the table says "No records", and the dashboard widget is not there
either.

**The actor has no `current_team_id`.** That is the deliberate answer, not a bug: the scope is an
explicit `whereRaw('1 = 0')`. Written as `where('team_id', null)` it would compile to `is null` and
show precisely the unowned carts the policy denies every action on — and on this table that is not a
handful of rows, because `team_id` is nullable and every cart a storefront created without knowing a
team has it null.

```php
// tinker
auth()->loginUsingId($userId);
Liberu\Ecommerce\Cart\Filament\Support\PanelTeam::id();   // null is the problem
Liberu\Ecommerce\Cart\Filament\Resources\CartResource::getEloquentQuery()->count();
```

Fix it on the application's side — the column belongs to the host, not to this package.

## Some carts are invisible to everyone

**Looks like:** rows exist in `carts` but no team's panel lists them.

**They have `team_id` null.** A cart belonging to no team is nobody's, and `CartPolicy::staffFor()`
denies staff every action on one. This package does not list them either: a row whose every action
refuses reads as a broken panel rather than a denied one.

The fix is upstream of the panel — whatever creates carts should pass a `team_id`. `CreateCart` takes
one.

```sql
select count(*) from carts where team_id is null;
```

## "Every amount shown here is out of date"

**Working as designed, and worth reading rather than dismissing.**

Recalculation in this domain is explicit: no observer fires it, so a cart carries the totals it was
last given until something asks for new ones. A cart whose line changed a second ago has a subtotal
from before that change, and `Cart::totalsAreCurrent()` — a revision counter, not two timestamps — is
how anything finds out.

Use **Recalculate totals**. It recomputes the subtotal, the per-line discount shares and the total
from the lines as they stand, changes nothing the shopper chose, and is idempotent.

**Do not quote the pre-recalculation number to a shopper.** That is the failure this sentence exists
to prevent.

**If the dashboard's "Totals out of date" number is large and growing,** the problem is not here: the
surfaces creating and changing carts are not recalculating. A storefront cart page should recalculate
before it renders a total.

## The recalculated total dropped the tax to zero

**Working as designed.** The action passes `EstimateInputs::none()`.

Tax and shipping are inputs the domain refuses to invent — it knows no jurisdiction, looks no rate up
and cannot know a carrier. This package knows none of it either. What the action produces is the
cart-page estimate: `total = subtotal - discount`.

A cart that had been through a checkout carrying a real tax amount loses it. Recalculate from
whatever holds the tax answer instead, or re-run the checkout estimate. Tracked in
[domain.md](domain.md#11-known-deviation-recalculation-here-computes-no-tax-and-no-shipping).

## "Recalculate totals" is not offered

Either the totals are already current — the action is hidden rather than shown as a button that
succeeds having changed nothing — or the cart is **terminal**, and `CartPolicy::update()` refuses
those for everybody, staff included. The numbers on a converted cart are the ones somebody was shown
when they agreed to pay.

## "Mark abandoned" is not offered

**The cart is not stale.** Deliberately stricter than the policy: staleness is the domain's measure
of a shopper having walked away, read off `last_activity_at`, and a cart somebody is filling in
another tab is not staff's to end. A button on one makes ending it a mis-click.

`CART_GUEST_TTL_DAYS` moves the line. An operator who genuinely needs to end a live cart has the
domain action and a console:

```php
app(Liberu\Ecommerce\Cart\Actions\MarkCartAbandoned::class)->handle($cart);
```

Otherwise the cart is already terminal, or the actor is not on its team.

## "Totals not recalculated" / "Cart not marked abandoned"

`CartNotActive`, caught and shown rather than raised as a 500.

The cart became terminal between the page rendering and the button being pressed — converted at
checkout in another tab, or ended by a job. The gate makes this unreachable in the ordinary case;
seeing it means losing that race. Reload; there is nothing to fix.

## There is no way to change a quantity, remove a line, or discount a cart

**Correct, and the central decision of this package.** A cart is the shopper's own note of what they
have chosen and they are about to be charged from it. A staff-side quantity box writes a number the
shopper never chose into that basket, and the domain would dispatch `LineQuantityChanged` with
nothing recording that it was not them. Removing a line is a hard delete — the domain is explicit
that a line is not a record of anything that happened — so afterwards nothing shows it existed, which
is what produces the "where did my thing go" ticket in the first place.

The full argument, and every operation ruled out, is in
[domain.md](domain.md#1-the-decision-a-cart-is-the-shoppers).

If a deployment genuinely needs staff-side edits, that is a request for an audited action in the
**domain** package — one that records who did it and why — not a form in a panel.

## A product shows as a number

**Working as designed.** Catalog is a sibling module and deliberately not a dependency of the cart
domain or of this package, so this has to render where no catalogue exists.
`CartItem::product()` resolves a class from `cart.product_model` and **throws** when the host has not
set one, so a column using it would crash the panel on every catalogue-less deployment.

Setting `CART_PRODUCT_MODEL` makes the relation loadable for the host's own code; it does not change
this table. The line already carries the name, SKU and unit price the shopper was shown. Subclass
`LinesRelationManager` if the deployment wants links. Tracked in
[domain.md](domain.md#10-known-limitation-a-product-is-a-number).

## A guest cart shows no way to identify the shopper

**Correct.** The guest token is never rendered anywhere in this package, and the column is not
searchable by it. Anything holding the token can be handed the cart by the surface that issued it,
and a support screen is shared, screenshotted and pasted into tickets.

Identify a guest cart by its id, its store and its lines. If a deployment needs staff to match a
walked-up shopper to a guest cart, the surface that issued the token is what should be doing the
matching.

## Events are not reaching a listener

Both writes this package makes go through a domain action, and the actions dispatch
`CartRecalculated` and `CartAbandoned`. If a listener is not firing:

1. Confirm the write went through the panel and not through a console command writing Eloquent
   directly. `$cart->update(['status' => 'abandoned'])` dispatches nothing.
2. Confirm the listener is registered by the *application*. This package subscribes to nothing.
3. Turn on the domain's own telemetry to see the events as they happen:

```dotenv
CART_TELEMETRY=true
CART_TELEMETRY_CHANNEL=stack
```

Turn it off afterwards. A busy storefront writes one record per add-to-cart.

## Upgrading the domain package broke a page

This package pins `liberusoftware/ecommerce-cart: ^0.1`. A minor release inside that range that
changed an action signature, an enum case or a policy ability would surface here as a `TypeError`, an
`UnhandledMatchError`, or a surface that renders and authorizes nothing.

The one to watch is `CartPolicy`. This package overrides ten Filament abilities on the assumption
that the policy publishes exactly six; a release adding a seventh does not break anything, but a
release **removing** `update` would silently hide both actions, and one removing `view` would empty
every page.

Roll the domain package back to the last known-good `0.1.x` and open an issue against it. Do **not**
work around it here: a compatibility shim in a presentation package is a second copy of a domain
rule, in the layer least likely to be read.

# Ecommerce: Cart Filament

> This optional Filament 5 presentation package presents exactly one independent domain module. It contributes reusable resources, pages, widgets, schemas, tables, infolists, and actions to application-owned panels while delegating authorization, validation, tenancy, persistence, and business rules to the [ecommerce-cart](https://github.com/liberusoftware/module-ecommerce-cart) public boundary.

[Software](https://liberusoftware.com) ·
[Hosting](https://liberuhosting.com) ·
[Services](https://liberuservices.com) ·
[Liberu Group](https://liberugroup.com)

![PHP](https://img.shields.io/badge/PHP-8.5-777BB4?logo=php&logoColor=white) ![Laravel](https://img.shields.io/badge/Laravel-13-FF2D20?logo=laravel&logoColor=white) ![Filament](https://img.shields.io/badge/Filament-5-FDAE4B)
[![Latest release](https://img.shields.io/github/v/release/liberusoftware/module-ecommerce-cart-filament?sort=semver)](https://github.com/liberusoftware/module-ecommerce-cart-filament/releases/latest) [![Tests](https://github.com/liberusoftware/module-ecommerce-cart-filament/actions/workflows/tests.yml/badge.svg?branch=main)](https://github.com/liberusoftware/module-ecommerce-cart-filament/actions/workflows/tests.yml)

## Features

- Fully compatible with **Laravel 13**, **PHP 8.5**, and **Pest 5**.
- Built following the domain-driven design guidelines of the Liberu architecture.
- Reusable, presenting a clean public contract and boundaries.
- Adheres to the strict database, security, and authorization standards of Liberu.

## A cart is the shopper's

This panel reads. It does not edit.

Staff have every ordinary reason to look at a cart — somebody is on the phone asking why the total
says what it says, or where the thing they added last week went. Staff have almost no defensible
reason to write to one. A quantity box on a staff screen looks like a convenience and is a write into
a basket somebody else is about to be charged from; the domain would dispatch `LineQuantityChanged`
carrying the old number and the new, with nothing anywhere recording that it was not the shopper who
did it.

So there is **no quantity field, no line removal, no discount form, no create page and no edit
page** — and the two routes that would carry the last two are not registered at all.

The two writes that are offered change nothing the shopper chose:

| Action | What it does | When it is offered |
| --- | --- | --- |
| **Recalculate totals** | Recomputes the subtotal, the per-line discount shares and the total from the lines as they stand. Idempotent | Only when the stored totals are actually out of date |
| **Mark abandoned** | Ends the cart. **Nothing is deleted** — the row and every line stay, which is what an abandoned-cart campaign reads | Only on a cart the shopper has already walked away from |

Every operation ruled out, and why each one is a decision rather than an omission, is in
[docs/domain.md](docs/domain.md#1-the-decision-a-cart-is-the-shoppers).

## Can I believe this number?

Recalculation in this domain is explicit — no observer fires it, so a cart carries the totals it was
last given until something asks. That is the right trade, and it leaves a support agent looking at a
number that was true earlier.

Every cart list here leads with what its three separate facts add up to:

| Standing | Means |
| --- | --- |
| **Live** | Active, and the shopper has been here lately |
| **Stale** | Active, but untouched for longer than the deployment's window, so signing in will not fold it into an account |
| **Merged** | Folded into an account's cart on sign-in. Terminal, and its lines are the record of what they had before |
| **Converted** | Handed to checkout. Terminal — the order is the record now |
| **Abandoned** | Left alone, or refused a merge. Terminal, and kept so somebody can be emailed |

And whatever the standing, it says so out loud when the money cannot be trusted: *"Its totals were
last computed before the most recent change to a line, so every amount shown here is out of date."*
A stale total looks exactly like a current one, and that number is what goes to a shopper in an
email.

`Support\CartStanding` computes it from attributes already loaded, so a page of fifty carts costs no
extra queries. It restates rules `Cart::isStale()` and `Cart::totalsAreCurrent()` also state, which is
a real risk, and `tests/Feature/StandingTest.php` asserts the two agree across every status against
every combination of the other two.

## The guest token is never rendered

A guest cart shows as **Guest**, and that is all. The token appears on no page, in no column, in no
filter, and the table is not searchable by it.

The token is the whole of a guest's claim on a cart — anything holding it can be handed that cart by
the surface that issued it. The domain's telemetry logger refuses to write it because *a log is read
by more people than a table is*; a staff panel is the same argument with a bigger audience. A test
asserts it.

## What it contributes

| Surface | Covers |
| --- | --- |
| `Resources\CartResource` (list, view) | Carts, their standing, their owner, their amounts, and the two writes |
| ↳ `LinesRelationManager` | What is in the cart, read-only and unconditionally so |
| `Widgets\CartsOverview` | Active carts, how many have gone quiet, and how many are showing out-of-date money |

Authorization is `CartPolicy` throughout, and the resource query is scoped to the team the actor is
working in — the same column the policy reads.

`CartPolicy` publishes six abilities and Filament asks for sixteen. **A model with no policy is
exposed, not safe, and so is an ability a present policy is silent on** — Filament's unanswered case
returns *allow*. Every one of the ten is answered explicitly here, and asserted in
[`tests/Feature/SecurityTest.php`](tests/Feature/SecurityTest.php) against the actor the policy is
most generous to. Three of them (`create`, `update`, `delete`) the policy answers *yes* and this
package refuses anyway; the test asserts the policy's yes first, so the override is visibly stricter
rather than redundant.

## Requirements

- **PHP 8.5**
- **Composer 2**
- A supported database (e.g. MySQL, PostgreSQL, SQLite)

## Quick start

The domain package this presents is not on Packagist, so add its repository alongside the
requirement:

```bash
composer config repositories.ecommerce-cart vcs https://github.com/liberusoftware/module-ecommerce-cart
composer require liberusoftware/ecommerce-cart-filament
```

Installing boots nothing — the package ships no `extra.laravel.providers`. Enable the module and the
domain module it presents:

```dotenv
MODULES_ENABLED=ecommerce-cart,ecommerce-cart-filament
```

Then attach the plugin to whichever panel should carry carts. The application owns its panels; this
package never registers itself into one:

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

The plugin's id is `liberu-ecommerce-cart`, and `module.json` declares it under
`presentation.filament.admin`.

The resource scopes itself to the team the panel user is working in — the `current_team_id` the
policy reads — rather than to Filament's tenant, so the plugin works on a panel with no tenancy
configured at all. It likewise does not need the panel to opt in to read-only relation managers:
`LinesRelationManager::isReadOnly()` is unconditionally true, because a package must not depend on a
host having set the one flag standing between staff and a shopper's basket.

**The host must delete its own `cart_items` migration** — see
[docs/adoption.md](docs/adoption.md#3-migrate).

## Documentation

- [Adoption and upgrade](docs/adoption.md) — installing, enabling the module, attaching the plugin
  to a panel, the `cart_items` migration the host has to delete, and what a host has to supply
- [Domain and presentation](docs/domain.md) — what staff may and may not do to a shopper's cart and
  why, the surface inventory, authorization including every ability the policy leaves unanswered,
  standing, money, theme integration, accessibility, and the deliberate limitations
- [Runbook](docs/runbook.md) — production failure modes and the operator's response to each
- [Changelog](CHANGELOG.md) — release notes
- [Liberu Main Documentation](https://github.com/liberusoftware/documentation)
- [Architecture & Standards Index](https://github.com/liberusoftware/documentation/tree/main/architecture)

## Related Liberu Projects

| Project | Repository | Purpose |
| --- | --- | --- |
| **Boilerplate** | [liberusoftware/boilerplate-laravel](https://github.com/liberusoftware/project-boilerplate-laravel-filament) | Shared Laravel application foundation and reference composition |
| **CMS** | [liberu-cms/cms-laravel](https://github.com/liberu-cms/cms-laravel) | Structured content, publishing, media, multisite, and headless delivery |
| **CRM** | [liberu-crm/crm-laravel](https://github.com/liberu-crm/crm-laravel) | Customer data, sales, marketing, service, and customer success |
| **Billing** | [liberu-billing/billing-laravel](https://github.com/liberu-billing/billing-laravel) | Products, subscriptions, invoicing, payments, and provisioning |
| **Accounting** | [liberu-accounting/accounting-laravel](https://github.com/liberu-accounting/accounting-laravel) | Ledgers, banking, tax, expenses, close, and financial reporting |
| **Ecommerce** | [liberu-ecommerce/ecommerce-laravel](https://github.com/liberu-ecommerce/ecommerce-laravel) | Catalog, checkout, orders, fulfillment, returns, B2B, and omnichannel commerce |
| **Control Panel** | [liberu-control-panel/control-panel-laravel](https://github.com/liberu-control-panel/control-panel-laravel) | Hosting, infrastructure, DNS, mail, databases, backups, and security operations |
| **Automation** | [liberu-automation/automation-laravel](https://github.com/liberu-automation/automation-laravel) | Governed workflows, provider-neutral AI, approvals, and connectors |

## Security

Please do not report security vulnerabilities through public GitHub issues.
Follow our [Security Policy](https://github.com/liberusoftware/documentation/blob/main/architecture/SECURITY.md) for private reporting and supported versions.

## License

This project is open-source software. You may use, modify, and distribute it
under the terms described in [LICENSE.md](LICENSE.md).

The linked license text is authoritative; this summary is not legal advice.

## Feedback and contributing

Feedback and contributions are welcome. You can help by reporting reproducible
bugs, proposing focused enhancements, improving documentation or translations,
and submitting tested code changes.

Before contributing, please read [CONTRIBUTING.md](https://github.com/liberusoftware/documentation/blob/main/standards/CONTRIBUTING.md) and our
[Code of Conduct](https://github.com/liberusoftware/documentation/blob/main/architecture/CODE_OF_CONDUCT.md). Search existing issues first, then use
the appropriate issue template. Pull requests should explain the problem and
approach, remain focused, include or update tests, pass the required workflows,
and document user-visible or breaking changes.

## Contributors

Thank you to everyone who helps improve Liberu.

<a href="https://github.com/liberusoftware/module-ecommerce-cart-filament/graphs/contributors">
  <img src="https://contrib.rocks/image?repo=liberusoftware/module-ecommerce-cart-filament" alt="Contributors to liberusoftware/module-ecommerce-cart-filament">
</a>

[View the full contributors graph](https://github.com/liberusoftware/module-ecommerce-cart-filament/graphs/contributors).

<!-- module-presentation-guide:begin -->
## Presentation architecture and project hosts

- Capability/source key: `planned:ecommerce:cart`.
- Actual Composer identity: `liberusoftware/ecommerce-cart-filament`.
- [Filament capability plan](https://github.com/liberusoftware/documentation/blob/main/projects/ecommerce/filament/cart.md) · [Filament standard](https://github.com/liberusoftware/documentation/blob/main/standards/FILAMENT.md) · [Module architecture](https://github.com/liberusoftware/documentation/blob/main/architecture/MODULES.md).

The application owns panel composition, routing, session integration and deployment. This module owns its focused presentation and delegates domain behavior to reviewed public owner contracts. Keep host App aliases, duplicate records and client-only authorization outside reusable package boundaries.

Documented consumer project families:

- [Ecommerce](https://github.com/liberusoftware/project-ecommerce-laravel-filament): Catalogs, checkout, orders and fulfillment.

Other web, mobile and desktop presentations are listed in the [project catalog](https://github.com/liberusoftware/documentation/blob/main/projects/REPOSITORIES.md). Those skeletons provide starting structures; links do not establish feature parity, compatible releases or completed device/API integrations. Independent presentation packages keep their own Composer, npm or pub metadata.
<!-- module-presentation-guide:end -->

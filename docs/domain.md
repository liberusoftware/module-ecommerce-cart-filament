# The domain, as this panel presents it

What attaching `CartPlugin` to a panel puts in front of an operator, what each surface asks the gate
before it renders, and — the part worth reading first — what staff deliberately may **not** do to a
shopper's cart.

---

## 1. The decision: a cart is the shopper's

Everything else here follows from one line.

A cart is not a record *about* a shopper. It is the shopper's own working note of what they have
picked out, and they are about to be charged from it. Staff have every ordinary reason to look at
one — somebody is on the phone asking why the total says what it says, or why their basket emptied,
or where the thing they added last week went. Staff have almost no defensible reason to write to
one.

So this package is **read, plus two writes that change nothing the shopper chose.**

### What staff may do

| May | Surface | Why it is safe |
| --- | --- | --- |
| See every cart on their team | `ListCarts` | `CartPolicy::viewAny()` is "does this actor work in a team", and every query is narrowed to that team |
| Open one cart, its amounts and its lines | `ViewCart`, `LinesRelationManager` | `CartPolicy::view()` — the cart's owner, or staff on its team |
| **Recalculate the totals** | `recalculate` action | Recomputes stored numbers from lines nobody touched. Idempotent by the domain's own guarantee: run it twice, get the same numbers, per line as well as per cart |
| **Mark a stale cart abandoned** | `abandon` action | Terminal, and **nothing is deleted**. The row and every line stay, which is the point of the status |

### What staff may not do, and why each one is a decision

| May not | The reason |
| --- | --- |
| **Edit a line's quantity** | The one that looks most like a convenience. A quantity box on a staff screen is a write into a basket somebody else is about to pay from; the domain dispatches `LineQuantityChanged` carrying the old number and the new, with nothing anywhere recording that it was not the shopper who did it, and the next thing they see is a total they did not agree to. A shopper who wants three instead of two is a conversation that ends with **them** changing it, or with an order — not with staff typing into their basket |
| **Remove a line** | Worse than editing one. `RemoveLine` is a hard delete: the domain is explicit that a line is not a record of anything that happened, so there is nothing left afterwards to show the line ever existed. The single most common support question about a cart is "where did my thing go", and this would be the panel that causes it |
| **Add a line** | Nothing here knows a price. The domain takes the unit price as an input because the line records what the shopper was shown, and a price typed into a panel is a number the shopper was never shown |
| **Apply or change a discount** | The domain records an amount somebody else worked out; it evaluates no coupon and knows no promotion. A staff-typed discount is therefore a price decision made on a screen with no record of who authorised it, against what, or why. A host that wants staff-issued discounts wants an approval trail, and that belongs wherever the amounts come from |
| **Clear a cart** | Same as removing lines, all at once |
| **Convert a cart** | Checkout's job. `ConvertCart` is terminal and records no order id, so a panel that fired it would end a cart with no order anywhere pointing at it |
| **Create a cart** | A cart is created *for* a shopper by the surface they are shopping on. `CartPolicy::create()` says yes to anybody signed in and is right to — it is answering for a storefront. A staff create form would have to invent an owner: a `user_id` typed into a box, or worse a guest token minted by a panel, and a cart whose owner somebody guessed is a cart reachable by the wrong person |
| **Edit a cart's own fields** | Currency is fixed at creation and there is no exchange rate in this module. Owner is `Values\CartOwner`'s alone to write, and always all three columns at once. Store and team are tenancy. None of the four has an edit form that would not be a way of moving a cart to somebody else |
| **Delete a cart** | Terminal carts are kept on purpose. A merged cart is the only record of what the shopper had before signing in; an abandoned one is what an abandoned-cart job reads. Deleting is the one operation that destroys exactly the evidence somebody opened this screen to look at |
| **See a guest's token** | §4 |

There is no create page and no edit page registered, so those two routes do not exist. The
authorization overrides in `CartResource` refuse them a second time at the class, because a page is
not the only thing that can reach an ability.

### Why the two writes are the two writes

**Recalculate** because the domain makes recalculation explicit on purpose — no observer fires it, so
a cart carries totals from before its last change until something asks. That is the right trade, and
it leaves a support agent looking at a number that was true earlier. This is how they fix that
without touching a single thing the shopper chose. It is offered **only when the totals are actually
out of date**: running it on a current cart writes the same numbers, but a button that reports
success having changed nothing teaches an operator to stop reading the notification.

It passes `EstimateInputs::none()` — no tax, no shipping. Both are inputs the domain refuses to
invent, and this package has no more idea of a jurisdiction or a carrier than the domain does. What
it produces is the cart-page estimate: subtotal, the discount spread pro-rata across every line, and
`total = subtotal - discount`. That is why the button says *Recalculate totals* and its confirmation
says what it does and does not compute, rather than saying "refresh".

**Mark abandoned** because it is the one lifecycle move whose whole purpose is operational, and
because it destroys nothing: the row stays, the lines stay, and the status is what an abandoned-cart
campaign reads. It is offered **only on a cart that is already stale** — stricter than
`CartPolicy::update()`, deliberately. Staleness is the domain's own measure of a shopper having
walked away, read off `last_activity_at`; a cart being filled in another tab right now is not
staff's to end, and a button on one makes ending it a mis-click. An operator who genuinely needs to
end a live cart has `MarkCartAbandoned` and a console.

Both catch `CartNotActive` and show a notification. The gate should make it unreachable — the policy
refuses a terminal cart — but a cart converted in another tab between render and submit lands there,
and losing that race deserves a sentence rather than a 500.

---

## 2. Inventory

Everything is registered by `CartPlugin::register()`. There is no directory scan; see
[Cache and discovery](#7-cache-and-discovery).

| Surface | Class |
| --- | --- |
| Resource | `Resources\CartResource` |
| ↳ page | `…\CartResource\Pages\ListCarts` (`/carts`) |
| ↳ page | `…\CartResource\Pages\ViewCart` (`/carts/{record}`) |
| ↳ relation manager | `…\CartResource\RelationManagers\LinesRelationManager` |
| Widget | `Widgets\CartsOverview` |
| Support | `Support\PanelTeam`, `Support\CartStanding`, `Support\Owner`, `Support\Amount` |

The package ships no standalone pages, no custom Blade views, no CSS and no JavaScript.

### What is deliberately not here

- **No `CartItemResource`.** A line is meaningless without the cart holding it. More concretely,
  `CartPolicy` serves both models and its `view()`, `update()` and `delete()` are typed against
  `Cart` — the domain authorizes a line through `updateLine()` and nothing else. A resource over
  `CartItem` would be a surface whose every Filament default gate call either raises a `TypeError`
  from inside the policy or answers about the wrong model.
- **No bulk actions.** Every write here goes through a domain action one record at a time, and the
  only two writes offered are ones an operator should be making deliberately about one cart.
- **No trashed filter and no restore action.** Nothing in this domain soft-deletes; terminal carts
  are kept as ordinary rows.
- **No `session_id` anywhere.** It is not a column on this table and this package would have nothing
  to do with it if it were. See the domain package's `docs/domain.md`.

---

## 3. Standing — the one thing this UI does that a generated CRUD would not

Three facts, kept apart by the domain on purpose:

| Fact | Where it lives |
| --- | --- |
| The lifecycle | `carts.status` |
| Whether the shopper has been here lately | `Cart::isStale()`, measured on `last_activity_at` |
| Whether the stored totals match the lines | `Cart::totalsAreCurrent()`, a revision counter and not two timestamps |

All three are right to be separate, and all three leave an operator reading a row and working out
what it means. `Support\CartStanding::of($cart)` says it:

| Standing | Means |
| --- | --- |
| **Live** | Active, and the shopper has been here inside the deployment's window |
| **Stale** | Active, but untouched for longer than that, so signing in will not fold it into an account |
| **Merged** | Folded into an account's cart on sign-in. Terminal, and its lines are the record of what they had before |
| **Converted** | Handed to checkout. Terminal — the order is the record now |
| **Abandoned** | Left alone, or refused a merge. Terminal, and kept so somebody can be emailed |

Rendered as a badge column headed **Standing** whose description is the whole sentence, and as the
subheading of `ViewCart`.

**And, whatever the standing, it says out loud when the money is out of date**: *"Its totals were
last computed before the most recent change to a line, so every amount shown here is out of date."*
That sentence is the reason the class exists. A total that was true two clicks ago looks exactly like
one that is true now, and the single worst thing this panel could do is show a support agent a stale
total as though it were the cart's value — because that number then goes to a shopper in an email.

Three notes on why it is shaped this way:

- **It restates rules the domain also states**, which is a real risk.
  `tests/Feature/StandingTest.php` asserts `stale` equals `Cart::isStale()` and `totalsAreCurrent`
  equals `Cart::totalsAreCurrent()` across every status against every combination of the other two,
  so a release that changes either rule and forgets this file fails a build rather than lying on a
  screen.
- **It is pure PHP** over attributes already loaded, so a table of fifty carts costs no extra
  queries.
- **It takes the instant it is asked about**, so a caller reasoning about a moment other than now
  gets an answer about that moment.

---

## 4. The guest token is never rendered

`Support\Owner::describe()` answers `Account #4`, `Company #11` or — for a guest — **`Guest`**, and
nothing else. The token appears on no page, in no column, in no filter and in no infolist entry.
`tests/Feature/SecurityTest.php` asserts it.

The token is the whole of a guest's claim on a cart: anything holding it can be handed that cart by
the surface that issued it. The domain's telemetry logger refuses to write it for a reason it states
plainly — *a log is read by more people than a table is* — and a staff panel is the same argument
with a bigger audience. A screen is shared, screenshotted, pasted into a ticket and left open on a
desk. Rendering the token would turn a support screen into a way of taking over somebody's basket.

An operator identifies a guest cart by its id, its store and its lines, which is what they were
looking at anyway. It is also not searchable by token, for the same reason.

The account and company ids **are** shown. They are foreign keys an operator can act on in the host's
own admin, and neither is a credential: knowing that cart 12 belongs to account 4 does not let
anybody act as account 4.

---

## 5. Authorization

Every surface asks the gate; nothing here is visible because it was registered.

| Surface | Ability | Asked about |
| --- | --- | --- |
| `CartResource` list | `viewAny` → `CartPolicy` | the model |
| `CartResource` view | `view` → `CartPolicy` | the cart |
| `CartResource::recalculateAction()` | `update`, **and** the totals being out of date | the cart |
| `CartResource::abandonAction()` | `update`, **and** the cart being stale and active | the cart |
| `LinesRelationManager` (visible) | `view` | the **cart**, never the line |
| `LinesRelationManager` everything else | nothing — `isReadOnly()` is unconditionally true | — |
| `CartsOverview` | none — `canView()` is `PanelTeam::id() !== null` | — |

### A model with no policy is exposed, not safe — and so is an ability the policy is silent on

`CartPolicy` publishes `viewAny`, `view`, `create`, `update`, `delete` and `updateLine`. Filament asks
for rather more than that, and its `get_authorization_response()` returns **allow** when a policy
exists but has no method for the ability being asked about. The unanswered gate case is permissive in
both directions, and that has produced a live leak twice in this fleet.

So every one of them is answered on `CartResource` rather than left to a default:

| Ability | Policy says | This package says | Why |
| --- | --- | --- | --- |
| `viewAny` | yes, for an actor in a team | **yes**, restated | So the yes is visibly a decision |
| `view` | owner or staff on the team | **yes**, restated | Same |
| `create` | yes, to anybody signed in | **no** | §1 — a cart is created for a shopper by the surface they are shopping on |
| `update` (`canEdit`) | yes, to owner or staff, on a non-terminal cart | **no** | §1 — nothing here edits a cart. The two actions ask for `update` directly instead |
| `delete` | yes, to owner or staff | **no** | §1 — terminal carts are the record |
| `deleteAny` | *silent* | **no** | Permissive without this |
| `forceDelete`, `forceDeleteAny` | *silent* | **no** | Permissive without this. Nothing here soft-deletes |
| `restore`, `restoreAny` | *silent* | **no** | Permissive without this |
| `replicate` | *silent* | **no** | Permissive without this. A replicated cart would be a second live basket for one owner in one store, which is exactly what `CartQuery::activeFor()` assumes cannot happen |
| `reorder` | *silent* | **no** | Permissive without this. Carts have no order to be in |

`tests/Feature/SecurityTest.php` asserts all of it against the actor the policy is most generous to —
staff on the cart's own team, on a live cart — and asserts first that the policy really does say yes
to `update` and `delete` for them, so the overrides are visibly stricter rather than redundant.

The lines are held read-only by `LinesRelationManager::isReadOnly()` returning true
**unconditionally**. That is what Filament consults *before* any policy for `CreateAction`,
`EditAction`, `DeleteAction`, `AttachAction`, `DetachAction`, `AssociateAction`, `DissociateAction`,
`ReplicateAction`, `RestoreAction`, `ForceDeleteAction`, every bulk equivalent and reordering — one
line, denied at the root, rather than an override per ability that a later Filament release could
route round. Filament's own default makes a relation manager read-only on a view page only when the
*panel* opts in, and this package must not depend on a host having opted in. The test asserts the
denial through the resolver Filament actually calls, for ten action classes the table does not even
carry today.

### Team scoping

`CartResource::getEloquentQuery()` narrows to the actor's team via `Support\PanelTeam::scope()` — the
same `team_id` column `CartPolicy::staffFor()` reads, because a list scoped by one rule and
authorized by another shows rows every row action then refuses.

An actor with no team gets an explicit `whereRaw('1 = 0')` — **not** `where('team_id', null)`, which
the query builder turns into `is null`. On this table that is not a rare edge: `team_id` is nullable
and unconstrained, and every cart a storefront created without knowing a team has it null. A scope
written the other way would list precisely the unowned carts the policy denies everything on.

The same trap is live in the owner filter, where it is worse. `user_id`, `guest_token` and
`company_id` are each null on two thirds of the table **by design** — exactly one is ever set. The
filter uses `whereNotNull` on the one column the chosen identity uses; written as
`where('guest_token', null)` it would answer "guest" with every account and company cart in the
deployment.

The scope is read from the actor's `current_team_id`, not from `Filament::getTenant()`, so the plugin
works on a panel with no tenancy configured. `isScopedToTenant()` is `false` for the same reason.

---

## 6. Money

Every amount in the cart schema is an integer count of a currency's smallest unit, and every one of
them is rendered through `Support\Amount::format()`, which goes through the domain's own
`Values\Money`.

That is not ceremony. The exponent is a property of the currency — 2 for GBP, 0 for JPY, 3 for BHD —
and a formatter with a hardcoded `/ 100` renders a yen cart a hundred times too cheap and a dinar
cart ten times too dear. `Money::toDecimal()` is string arithmetic on the way out for the same reason
`fromDecimal()` is on the way in: handing a float to anything re-introduces one layer later exactly
the imprecision integer minor units exist to keep out. `tests/Feature/StandingTest.php` asserts all
three exponents, plus zero and a negative.

No amount on any screen here is editable, so nothing converts *into* minor units at all.

---

## 7. Cache and discovery

`CartPlugin::register()` names its resource and its widget in a literal array. It does not call
`$panel->discoverResources()` or `discoverWidgets()`, and the package has no
`extra.laravel.providers`, so nothing about it is found by scanning.

- **`php artisan filament:cache-components` is safe and always has been.** With an explicit
  registration there is no directory walk to skip, so the command changes nothing about which
  surfaces appear.
- **`php artisan optimize` is likewise safe**, and `optimize:clear` cannot make a surface reappear or
  vanish — there is no discovery state to be stale.
- **`php artisan about` and `filament:upgrade` have nothing to report**, because this package
  contributes through a plugin the application attaches.

The cost of a literal list is that a class added later without being added to it is simply absent
from the panel: no error, no warning, just a resource nobody can reach.
`tests/Feature/PanelTest.php` asserts the registered set is exactly the classes in `src/Resources`
and `src/Widgets`, so that silence fails a build instead of shipping.

---

## 8. Accessibility

- Every action carries a label as well as an icon, so a control Filament renders as an icon button
  still has an accessible name.
- **No state is carried by colour alone.** Standing renders as `Live` / `Stale` / `Merged` /
  `Converted` / `Abandoned` with the whole explanation as its description; a line's tax status renders
  as `Taxed` / `Not taxed`. Badge colour repeats what the text says. This is why neither is an
  `IconColumn::boolean()` — a tick and a cross are indistinguishable to a screen reader and to a
  reader who cannot separate the two colours they are drawn in.
- **The staleness of the totals is a sentence, not a colour.** It is the fact most likely to be acted
  on wrongly and the one a badge cannot carry.
- Computed columns are labelled rather than left to Filament's humanising, so the headings read
  `Shopper last active` and `Standing` rather than `Last activity at` and `Standing`.
- Empty values say what they mean rather than rendering as nothing: `None`, `Never`.
- The one destructive action confirms, and the confirmation says what will happen — including, in
  as many words, that **nothing is deleted**. "Are you sure?" is not a description; the thing worth
  saying is the thing the operator is afraid of.
- Both actions report their outcome as a notification, including the one whose visible effect is a
  row changing. A table redrawing is not feedback an operator who is not watching it can use.
- Sentinels are read rather than printed: `variant_id` is `0` for "no variant", and the column shows
  `None`. Printing the sentinel would be an operator searching a catalogue for variant zero.

Untested and honest about it: focus order, contrast and keyboard traps are Filament's, not this
package's. This package ships no views and no CSS, so it has neither a way to break them nor a way to
prove them.

---

## 9. Theme integration

This package ships **no Blade views, no CSS, no JavaScript and no Vite entry point**. Every surface is
built from Filament's own components, so a host theme reaches all of it with no build step here and
nothing to publish.

| Relies on | What it is |
| --- | --- |
| Colour aliases `success`, `warning`, `danger`, `info`, `gray` | Notifications, and the badge colours on the standing and tax columns. Whatever the panel's `colors()` maps these to is what renders |
| Heroicons (outline) | `heroicon-o-shopping-cart`, `heroicon-o-calculator`, `heroicon-o-archive-box`. Filament's default icon set; nothing extra to install |
| The panel's font, dark mode, spacing and custom theme CSS | Applied by Filament to every component this package renders |

A host **can** override everything visual (`colors()`, `font()`, `darkMode()`, `viteTheme()`), the
navigation group presentation (`navigationGroups([...])` reaches the `Commerce` group), and whether
the widget appears at all.

A host **cannot** override, without subclassing, the icon strings (literal heroicon names, so
`FilamentIcon::register()` does not reach them), the navigation label and group name (`protected
static` properties), or the column sets and action labels. The escape hatch is the ordinary one:
extend the resource, override the property or method, and register your subclass on the panel instead
of attaching the plugin. There is no configuration file and no theming layer here, and adding one to
cover a case nobody has hit would be a second place for these values to live.

---

## 10. Known limitation: a product is a number

`cart_items.product_id` and `variant_id` are rendered as integers, with no name beyond the one on the
line and no link.

That is the wave-4 boundary working. Catalog is a sibling module and deliberately not a dependency of
the cart domain or of this package, so this has to render in a deployment with no catalogue at all.
`CartItem::product()` resolves a class from `config('cart.product_model')` and **throws** when the
host has not named one — which is the state of every deployment running the cart without a catalogue
— so a column that used the relation would crash the panel on install for those deployments.

The line already carries the name, the SKU and the unit price the shopper was shown when they chose
it, copied at that moment and never read back, so the column an operator actually needs is there. A
host running the catalogue can subclass `LinesRelationManager` and add a link; the escape hatch is
the ordinary one above.

`tests/Feature/BoundaryTest.php` renders a cart whose product id nothing in the database has heard of,
and asserts `product()` still throws — so the day somebody adds a product column, the test that fails
is the one explaining why.

---

## 11. Known deviation: recalculation here computes no tax and no shipping

The `recalculate` action passes `EstimateInputs::none()`.

Tax and shipping are inputs the domain refuses to invent — it knows no jurisdiction, looks no rate
up, compounds nothing, and cannot know a carrier or a destination. This package knows none of those
things either, and a form asking an operator to type a tax rate would be putting a tax decision on a
support screen.

What the action produces is therefore the cart-page estimate — subtotal, the discount spread pro-rata
across every line, and `total = subtotal - discount` — which is the same estimate a storefront cart
page shows. A cart that has been through a checkout carrying a real tax amount will, after this
action, show that amount as zero.

That is stated on the confirmation modal in as many words rather than left to be discovered.

**Resolved when:** the host has somewhere for this panel to get a resolved tax amount and a shipping
amount from — a checkout session, or a tax service — at which point the action grows two optional
inputs and passes `EstimateInputs::withTaxAmount()`. It will not grow a rate field: a rate typed into
a panel is a second answer to a question another system owns.

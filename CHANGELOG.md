# Changelog

## 0.1.0 - 2026-08-10

- First release. A Filament 5 presentation adapter for `liberusoftware/ecommerce-cart`, contributing
  one resource and one widget to a panel the application composes.
- **A cart is the shopper's.** This panel reads: there is no quantity field, no line removal, no
  discount form, no create page and no edit page, and the last two routes are not registered at all.
  A staff-side quantity box is a write into a basket somebody else is about to be charged from, and
  the domain would dispatch `LineQuantityChanged` with nothing recording that it was not the shopper.
  Every operation ruled out is listed with its reason in
  [docs/domain.md](docs/domain.md#1-the-decision-a-cart-is-the-shoppers).
- **Two writes, both of which change nothing the shopper chose.** *Recalculate totals* recomputes
  stored numbers from untouched lines through `RecalculateCart`, and is offered only when the totals
  are actually out of date. *Mark abandoned* ends a cart through `MarkCartAbandoned`, keeps every
  row, and is offered only on a cart the shopper has already walked away from — stricter than
  `CartPolicy::update()`, deliberately.
- **Standing.** Status, staleness and whether the stored totals match the revision are three separate
  facts, and every cart list leads with what they add up to — `Live`, `Stale`, `Merged`, `Converted`
  or `Abandoned` — with the whole explanation as the badge's description and as the subheading of the
  view page. Whatever the standing, it says in words when the amounts on screen are out of date,
  because a stale total looks exactly like a current one and that number goes to a shopper in an
  email. `Support\CartStanding` computes it from attributes already loaded, and a test asserts it
  agrees with `Cart::isStale()` and `Cart::totalsAreCurrent()` across every status against every
  combination of the other two.
- **The guest token is never rendered**, in any column, filter or entry, and the table is not
  searchable by it. A guest cart shows as `Guest`. Anything holding the token can be handed the cart
  by the surface that issued it, and a support screen is shared, screenshotted and pasted into
  tickets. Asserted in `tests/Feature/SecurityTest.php`.
- **Every ability `CartPolicy` leaves unanswered is answered here.** Filament's
  `get_authorization_response()` returns *allow* when a present policy has no method for the ability
  asked about, so `deleteAny`, `forceDelete`, `forceDeleteAny`, `restore`, `restoreAny`, `replicate`
  and `reorder` are permissive by default; all seven are forced false. `create`, `update` and
  `delete` the policy answers *yes* and this package refuses anyway, which is its own decision and
  not the domain's — the test asserts the policy's yes first, so the override is visibly stricter
  rather than redundant.
- **The lines are read-only unconditionally.** `LinesRelationManager::isReadOnly()` returns true
  regardless of the panel's `readOnlyRelationManagersOnResourceViewPagesByDefault()`, because a
  package must not depend on a host having opted in to the one flag standing between staff and a
  shopper's basket. Filament consults it before any policy for every mutating default action, and the
  test asserts the denial through the resolver Filament actually calls, for ten action classes the
  table does not even carry.
- Every resource query is scoped to the team the actor is working in, via an explicit
  `whereRaw('1 = 0')` for an actor with no team — `where('team_id', null)` compiles to `is null`, and
  on this table that would list every cart a storefront created without knowing a team. The owner
  filter uses `whereNotNull` for the same reason, where it matters more: each of `user_id`,
  `guest_token` and `company_id` is null on two thirds of the rows by design.
- Amounts are formatted through the domain's `Values\Money`, so a currency's own exponent applies —
  a yen cart is not rendered a hundred times too cheap and a dinar cart is not rendered ten times too
  dear.

### Known limitations

- A product and a variant are numbers. Catalog is a sibling module and deliberately not a dependency,
  so this renders where no catalogue exists at all; `CartItem::product()` throws when the host has
  named no product model, which is every catalogue-less deployment. The line carries the name, SKU
  and unit price the shopper was shown. Recorded in
  [docs/domain.md](docs/domain.md#10-known-limitation-a-product-is-a-number), with a test that renders
  a cart whose product id nothing in the database has heard of.
- *Recalculate totals* computes no tax and no shipping, because both are inputs the domain refuses to
  invent and this package knows no more than it does. What it produces is the cart-page estimate, and
  the confirmation modal says so. Recorded in
  [docs/domain.md](docs/domain.md#11-known-deviation-recalculation-here-computes-no-tax-and-no-shipping)
  with the condition that resolves it.

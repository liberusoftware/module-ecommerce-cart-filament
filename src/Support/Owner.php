<?php

namespace Liberu\Ecommerce\Cart\Filament\Support;

use Liberu\Ecommerce\Cart\Enums\OwnerKind;
use Liberu\Ecommerce\Cart\Models\Cart;

/**
 * Whose cart it is, in words, **without printing the guest token**.
 *
 * The token is the whole of a guest's claim on a cart. The domain's telemetry
 * logger refuses to write it for a reason it states plainly — *a log is read by
 * more people than a table is* — and a staff panel is the same argument with a
 * bigger audience: a screen is shared, screenshotted, pasted into a ticket and
 * left open on a desk. Anything that has the token can be handed the cart by the
 * surface that issued it, so rendering it turns a support screen into a way of
 * taking over somebody's basket.
 *
 * So a guest is "Guest" and nothing else. An operator identifies a guest cart by
 * its id, its store and its lines, which is what they were looking at anyway.
 * `tests/Feature/SecurityTest.php` asserts the token appears on no page.
 *
 * The account and company ids **are** shown. They are foreign keys the operator
 * can act on in the host's own admin, and neither is a credential: knowing that
 * cart 12 belongs to account 4 does not let anybody act as account 4.
 */
final class Owner
{
    public static function describe(Cart $cart): string
    {
        $owner = $cart->owner();

        return match ($owner->kind) {
            OwnerKind::User => 'Account #'.$owner->userId,
            OwnerKind::Company => 'Company #'.$owner->companyId,
            OwnerKind::Guest => 'Guest',
        };
    }

    /** Three words for the kind alone, for a column heading a filter matches. */
    public static function kind(Cart $cart): string
    {
        return match ($cart->owner()->kind) {
            OwnerKind::User => 'Account',
            OwnerKind::Company => 'Company',
            OwnerKind::Guest => 'Guest',
        };
    }
}

<?php

namespace Liberu\Ecommerce\Cart\Filament\Resources\CartResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Liberu\Ecommerce\Cart\Filament\Resources\CartResource;

/**
 * No header actions, and in particular no `CreateAction`.
 *
 * A cart is created for a shopper by the surface they are shopping on — there is
 * no owner a panel could supply that would not be a guess. See
 * `CartResource::canCreate()`.
 */
class ListCarts extends ListRecords
{
    protected static string $resource = CartResource::class;
}

<?php

namespace Liberu\Ecommerce\Cart\Filament;

use Illuminate\Support\ServiceProvider;

/**
 * Named by `module.json` and registered by `ModuleManagerServiceProvider`, never
 * by Composer discovery — the package ships no `extra.laravel.providers`.
 *
 * It has nothing to do. The panels belong to the application, and this package
 * contributes to them through {@see CartPlugin}, which the application attaches.
 * A provider that reached into a panel here would put shoppers' carts in front
 * of every panel that never asked for them.
 */
final class CartFilamentServiceProvider extends ServiceProvider {}

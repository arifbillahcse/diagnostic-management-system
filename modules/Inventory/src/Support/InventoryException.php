<?php

declare(strict_types=1);

namespace Modules\Inventory\Support;

use RuntimeException;

/** An inventory rule was broken; the message is safe to show to staff. */
final class InventoryException extends RuntimeException {}

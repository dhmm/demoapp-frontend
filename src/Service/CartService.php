<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * CartService
 *
 * Manages the shopping cart state in the user's session and synchronises
 * with the backend API when the user is authenticated.
 *
 * Guest carts are stored purely in session. On login, the session cart
 * is merged into the server-side cart via the API.
 */
class CartService
{
    private const SESSION_KEY = 'cart';

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly ApiClient    $apiClient,
        private readonly SessionAuthService $sessionAuth,
    ) {}

    // -------------------------------------------------------------------------
    // Read operations
    // -------------------------------------------------------------------------

    /**
     * Retrieve the current cart (API for logged-in users, session otherwise).
     *
     * @return array  {items: [], coupon: null, item_count: int}
     */
    public function getCart(): array
    {
        if ($this->sessionAuth->isLoggedIn()) {
            try {
                $response = $this->apiClient->authenticatedGet('/cart');
                return $response;
            } catch (\RuntimeException) {
                // Fall through to session cart on API error
            }
        }

        return $this->getSessionCart();
    }

    /**
     * Calculate cart totals including shipping estimate, tax, and discounts.
     *
     * @param  array  $cart
     * @return array  {subtotal, shipping_estimate, discount_amount, tax_amount, total, item_count}
     */
    public function getSummary(array $cart): array
    {
        if ($this->sessionAuth->isLoggedIn()) {
            try {
                $response = $this->apiClient->authenticatedGet('/cart/summary');
                return $response;
            } catch (\RuntimeException) {
                // Fall through
            }
        }

        return $this->calculateLocalSummary($cart);
    }

    // -------------------------------------------------------------------------
    // Mutation operations
    // -------------------------------------------------------------------------

    /**
     * Add a product to the cart.
     *
     * @param  int       $productId
     * @param  int       $quantity
     * @param  int|null  $variantId
     * @return array  Updated cart
     */
    public function addItem(int $productId, int $quantity = 1, ?int $variantId = null): array
    {
        if ($this->sessionAuth->isLoggedIn()) {
            $response = $this->apiClient->authenticatedPost('/cart/items', [
                'product_id' => $productId,
                'quantity'   => $quantity,
                'variant_id' => $variantId,
            ]);
            return $response;
        }

        return $this->addItemToSession($productId, $quantity, $variantId);
    }

    /**
     * Update the quantity of a cart line item.
     *
     * @param  int  $itemId
     * @param  int  $quantity
     * @return array  Updated cart
     */
    public function updateItem(int $itemId, int $quantity): array
    {
        if ($this->sessionAuth->isLoggedIn()) {
            $response = $this->apiClient->authenticatedPut("/cart/items/{$itemId}", [
                'quantity' => $quantity,
            ]);
            return $response;
        }

        return $this->updateSessionItem($itemId, $quantity);
    }

    /**
     * Remove a single line item from the cart.
     *
     * @param  int  $itemId
     * @return void
     */
    public function removeItem(int $itemId): void
    {
        if ($this->sessionAuth->isLoggedIn()) {
            $this->apiClient->authenticatedDelete("/cart/items/{$itemId}");
            return;
        }

        $this->removeSessionItem($itemId);
    }

    /**
     * Remove all items from the cart.
     *
     * @return void
     */
    public function clearCart(): void
    {
        if ($this->sessionAuth->isLoggedIn()) {
            $this->apiClient->authenticatedDelete('/cart');
        }

        $this->getSession()->set(self::SESSION_KEY, ['items' => [], 'coupon' => null]);
    }

    /**
     * Apply a coupon code.
     *
     * @param  string  $code
     * @return array  Coupon details {type, value, code}
     * @throws \RuntimeException if coupon is invalid
     */
    public function applyCoupon(string $code): array
    {
        // Validate via public endpoint first
        $validation = $this->apiClient->post('/coupons/validate', ['code' => $code]);

        if (! ($validation['valid'] ?? false)) {
            throw new \RuntimeException($validation['message'] ?? 'Invalid coupon code.');
        }

        if ($this->sessionAuth->isLoggedIn()) {
            $this->apiClient->authenticatedPost('/cart/apply-coupon', ['coupon_code' => $code]);
        }

        $this->getSession()->set('cart.coupon_code', $code);
        return $validation;
    }

    /**
     * Remove the currently applied coupon.
     *
     * @return void
     */
    public function removeCoupon(): void
    {
        if ($this->sessionAuth->isLoggedIn()) {
            $this->apiClient->authenticatedDelete('/cart/remove-coupon');
        }

        $this->getSession()->remove('cart.coupon_code');
    }

    /**
     * Merge a guest session cart into the authenticated user's server cart on login.
     *
     * @return void
     */
    public function mergeGuestCartOnLogin(): void
    {
        $sessionCart = $this->getSessionCart();

        if (empty($sessionCart['items'])) {
            return;
        }

        foreach ($sessionCart['items'] as $item) {
            try {
                $this->apiClient->authenticatedPost('/cart/items', [
                    'product_id' => $item['product_id'],
                    'quantity'   => $item['quantity'],
                    'variant_id' => $item['variant_id'] ?? null,
                ]);
            } catch (\RuntimeException) {
                // Skip items that fail to merge (e.g., out of stock)
            }
        }

        // Clear local session cart after merge
        $this->getSession()->set(self::SESSION_KEY, ['items' => [], 'coupon' => null]);
    }

    // -------------------------------------------------------------------------
    // Session cart helpers (for guest users)
    // -------------------------------------------------------------------------

    private function getSessionCart(): array
    {
        return $this->getSession()->get(self::SESSION_KEY, ['items' => [], 'coupon' => null]);
    }

    private function addItemToSession(int $productId, int $quantity, ?int $variantId): array
    {
        $cart = $this->getSessionCart();

        foreach ($cart['items'] as &$item) {
            if ($item['product_id'] === $productId && ($item['variant_id'] ?? null) === $variantId) {
                $item['quantity'] += $quantity;
                $this->getSession()->set(self::SESSION_KEY, $cart);
                return $cart;
            }
        }

        $cart['items'][] = [
            'id'         => count($cart['items']) + 1,
            'product_id' => $productId,
            'variant_id' => $variantId,
            'quantity'   => $quantity,
        ];

        $this->getSession()->set(self::SESSION_KEY, $cart);
        return $cart;
    }

    private function updateSessionItem(int $itemId, int $quantity): array
    {
        $cart = $this->getSessionCart();

        foreach ($cart['items'] as &$item) {
            if ($item['id'] === $itemId) {
                $item['quantity'] = $quantity;
                break;
            }
        }

        $this->getSession()->set(self::SESSION_KEY, $cart);
        return $cart;
    }

    private function removeSessionItem(int $itemId): void
    {
        $cart = $this->getSessionCart();
        $cart['items'] = array_values(
            array_filter($cart['items'], fn($i) => $i['id'] !== $itemId)
        );
        $this->getSession()->set(self::SESSION_KEY, $cart);
    }

    private function calculateLocalSummary(array $cart): array
    {
        $subtotal   = 0.0;
        $itemCount  = 0;

        foreach ($cart['items'] as $item) {
            $price     = (float) ($item['unit_price'] ?? $item['price'] ?? 0);
            $qty       = (int)   ($item['quantity'] ?? 1);
            $subtotal  += $price * $qty;
            $itemCount += $qty;
        }

        $shipping   = $subtotal >= 50 ? 0.0 : 4.95;
        $tax        = round($subtotal * 0.21, 2);
        $total      = $subtotal + $shipping + $tax;

        return [
            'subtotal'          => $subtotal,
            'shipping_estimate' => $shipping,
            'discount_amount'   => 0.0,
            'tax_amount'        => $tax,
            'total'             => $total,
            'item_count'        => $itemCount,
        ];
    }

    private function getSession(): SessionInterface
    {
        return $this->requestStack->getSession();
    }
}

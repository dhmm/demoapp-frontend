<?php

namespace App\Controller;

use App\Service\ApiClient;
use App\Service\CartService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * CartController
 *
 * Handles the shopping cart views and operations. Both full-page
 * and AJAX (partial) responses are supported.
 *
 * Session-based cart state is managed through CartService which
 * synchronises with the backend API when the user is logged in.
 */
#[Route('/cart', name: 'cart_')]
class CartController extends AbstractController
{
    public function __construct(
        private readonly CartService $cartService,
        private readonly ApiClient   $apiClient,
    ) {}

    /**
     * Display the full shopping cart page.
     *
     * GET /cart
     */
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $cart = $this->cartService->getCart();

        return $this->render('cart/index.html.twig', [
            'cart'    => $cart,
            'summary' => $this->cartService->getSummary($cart),
        ]);
    }

    /**
     * Render the mini-cart partial for the header (AJAX).
     *
     * GET /cart/mini
     */
    #[Route('/mini', name: 'mini', methods: ['GET'])]
    public function mini(): Response
    {
        $cart = $this->cartService->getCart();

        return $this->render('cart/_mini.html.twig', [
            'cart' => $cart,
        ]);
    }

    /**
     * Get cart summary (totals) as JSON or partial HTML.
     *
     * GET /cart/summary
     */
    #[Route('/summary', name: 'summary', methods: ['GET'])]
    public function summary(): Response
    {
        $cart    = $this->cartService->getCart();
        $summary = $this->cartService->getSummary($cart);

        if ($this->isAjax()) {
            return new JsonResponse(['summary' => $summary]);
        }

        return $this->render('cart/_summary.html.twig', ['summary' => $summary]);
    }

    /**
     * Add a product to the cart.
     *
     * POST /cart/add/{productId}
     * Body: quantity (int), variant_id (optional int)
     */
    #[Route('/add/{productId}', name: 'add', methods: ['POST'], requirements: ['productId' => '\d+'])]
    public function add(int $productId, Request $request): Response
    {
        $quantity  = max(1, (int) $request->request->get('quantity', 1));
        $variantId = $request->request->get('variant_id');

        try {
            $cart = $this->cartService->addItem($productId, $quantity, $variantId);
            $summary = $this->cartService->getSummary($cart);

            $this->addFlash('success', 'Item added to your cart.');

            if ($this->isAjax()) {
                return new JsonResponse([
                    'success'    => true,
                    'cart_count' => $summary['item_count'],
                    'subtotal'   => $summary['subtotal'],
                    'message'    => 'Item added to cart.',
                ]);
            }
        } catch (\RuntimeException $e) {
            $this->addFlash('error', $e->getMessage());

            if ($this->isAjax()) {
                return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
            }
        }

        return $this->redirectToRoute('cart_index');
    }

    /**
     * Update the quantity of a cart line item.
     *
     * POST /cart/update/{itemId}
     * Body: quantity (int, min 1)
     */
    #[Route('/update/{itemId}', name: 'update', methods: ['POST'], requirements: ['itemId' => '\d+'])]
    public function update(int $itemId, Request $request): Response
    {
        $quantity = max(1, (int) $request->request->get('quantity', 1));

        try {
            $cart    = $this->cartService->updateItem($itemId, $quantity);
            $summary = $this->cartService->getSummary($cart);

            if ($this->isAjax()) {
                return new JsonResponse([
                    'success'    => true,
                    'cart_count' => $summary['item_count'],
                    'subtotal'   => $summary['subtotal'],
                ]);
            }

            $this->addFlash('success', 'Cart updated.');
        } catch (\RuntimeException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('cart_index');
    }

    /**
     * Remove a single line item from the cart.
     *
     * POST /cart/remove/{itemId}
     */
    #[Route('/remove/{itemId}', name: 'remove', methods: ['POST'], requirements: ['itemId' => '\d+'])]
    public function remove(int $itemId, Request $request): Response
    {
        $this->cartService->removeItem($itemId);

        if ($this->isAjax()) {
            $cart    = $this->cartService->getCart();
            $summary = $this->cartService->getSummary($cart);

            return new JsonResponse([
                'success'    => true,
                'cart_count' => $summary['item_count'],
                'subtotal'   => $summary['subtotal'],
            ]);
        }

        $this->addFlash('success', 'Item removed from cart.');
        return $this->redirectToRoute('cart_index');
    }

    /**
     * Clear the entire cart.
     *
     * POST /cart/clear
     */
    #[Route('/clear', name: 'clear', methods: ['POST'])]
    public function clear(): Response
    {
        $this->cartService->clearCart();
        $this->addFlash('success', 'Your cart has been cleared.');

        return $this->redirectToRoute('cart_index');
    }

    /**
     * Apply a coupon code to the cart.
     *
     * POST /cart/coupon/apply
     * Body: coupon_code (string)
     */
    #[Route('/coupon/apply', name: 'apply_coupon', methods: ['POST'])]
    public function applyCoupon(Request $request): Response
    {
        $code = trim((string) $request->request->get('coupon_code', ''));

        try {
            $result = $this->cartService->applyCoupon($code);

            $message = sprintf(
                'Coupon applied! You save %s.',
                $this->formatDiscount($result)
            );
            $this->addFlash('success', $message);

            if ($this->isAjax()) {
                return new JsonResponse(['success' => true, 'discount' => $result]);
            }
        } catch (\RuntimeException $e) {
            $this->addFlash('error', $e->getMessage());

            if ($this->isAjax()) {
                return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 422);
            }
        }

        return $this->redirectToRoute('cart_index');
    }

    /**
     * Remove the currently applied coupon from the cart.
     *
     * POST /cart/coupon/remove
     */
    #[Route('/coupon/remove', name: 'remove_coupon', methods: ['POST'])]
    public function removeCoupon(): Response
    {
        $this->cartService->removeCoupon();
        $this->addFlash('success', 'Coupon removed.');

        return $this->redirectToRoute('cart_index');
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function isAjax(): bool
    {
        return isset($_SERVER['HTTP_X_REQUESTED_WITH'])
            && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }

    private function formatDiscount(array $couponResult): string
    {
        if ($couponResult['type'] === 'percentage') {
            return $couponResult['value'] . '%';
        }
        return '€' . number_format($couponResult['value'], 2);
    }
}

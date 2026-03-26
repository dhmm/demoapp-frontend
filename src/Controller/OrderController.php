<?php

namespace App\Controller;

use App\Service\ApiClient;
use App\Service\CartService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * OrderController
 *
 * Handles multi-step checkout, order history, tracking,
 * cancellations, and return requests for the customer.
 */
class OrderController extends AbstractController
{
    public function __construct(
        private readonly ApiClient   $apiClient,
        private readonly CartService $cartService,
    ) {}

    // =========================================================================
    // Checkout flow (multi-step)
    // =========================================================================

    /**
     * Checkout entry point — redirects to first incomplete step.
     *
     * GET /checkout
     */
    #[Route('/checkout', name: 'checkout_index', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function checkout(): Response
    {
        $cart = $this->cartService->getCart();

        if (empty($cart['items'])) {
            $this->addFlash('warning', 'Your cart is empty.');
            return $this->redirectToRoute('cart_index');
        }

        return $this->redirectToRoute('checkout_address');
    }

    /**
     * Step 1 — Select or create a delivery address.
     *
     * GET|POST /checkout/address
     */
    #[Route('/checkout/address', name: 'checkout_address', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function selectAddress(Request $request): Response
    {
        $addresses = $this->apiClient->authenticatedGet('/addresses');

        if ($request->isMethod('POST')) {
            $addressId = $request->request->getInt('shipping_address_id');

            if (! $addressId) {
                $this->addFlash('error', 'Please select a delivery address.');
                return $this->redirectToRoute('checkout_address');
            }

            $request->getSession()->set('checkout.shipping_address_id', $addressId);
            return $this->redirectToRoute('checkout_shipping');
        }

        return $this->render('checkout/address.html.twig', [
            'addresses' => $addresses['data'] ?? [],
            'step'      => 1,
        ]);
    }

    /**
     * Step 2 — Select a shipping method.
     *
     * GET|POST /checkout/shipping
     */
    #[Route('/checkout/shipping', name: 'checkout_shipping', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function selectShipping(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $method = $request->request->get('shipping_method');
            $request->getSession()->set('checkout.shipping_method', $method);
            return $this->redirectToRoute('checkout_payment');
        }

        return $this->render('checkout/shipping.html.twig', [
            'shippingMethods' => $this->getShippingMethods(),
            'step'            => 2,
        ]);
    }

    /**
     * Step 3 — Select payment method and enter details.
     *
     * GET|POST /checkout/payment
     */
    #[Route('/checkout/payment', name: 'checkout_payment', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function payment(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $method = $request->request->get('payment_method');
            $request->getSession()->set('checkout.payment_method', $method);
            return $this->redirectToRoute('checkout_review');
        }

        $savedMethods = $this->apiClient->authenticatedGet('/payments/methods');

        return $this->render('checkout/payment.html.twig', [
            'savedMethods' => $savedMethods['data'] ?? [],
            'step'         => 3,
        ]);
    }

    /**
     * Step 4 — Review order before final submission.
     *
     * GET /checkout/review
     */
    #[Route('/checkout/review', name: 'checkout_review', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function review(Request $request): Response
    {
        $session = $request->getSession();
        $cart    = $this->cartService->getCart();
        $address = $this->apiClient->authenticatedGet('/addresses/' . $session->get('checkout.shipping_address_id'));

        return $this->render('checkout/review.html.twig', [
            'cart'            => $cart,
            'summary'         => $this->cartService->getSummary($cart),
            'shippingAddress' => $address['data'] ?? null,
            'shippingMethod'  => $session->get('checkout.shipping_method'),
            'paymentMethod'   => $session->get('checkout.payment_method'),
            'step'            => 4,
        ]);
    }

    /**
     * Final order placement — calls backend API.
     *
     * POST /checkout/place-order
     */
    #[Route('/checkout/place-order', name: 'checkout_place_order', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function placeOrder(Request $request): Response
    {
        $session = $request->getSession();

        try {
            $result = $this->apiClient->authenticatedPost('/orders', [
                'shipping_address_id' => $session->get('checkout.shipping_address_id'),
                'shipping_method'     => $session->get('checkout.shipping_method'),
                'payment_method'      => $session->get('checkout.payment_method'),
                'coupon_code'         => $session->get('cart.coupon_code'),
            ]);

            // Clear checkout session data
            foreach (['checkout.shipping_address_id', 'checkout.shipping_method', 'checkout.payment_method'] as $key) {
                $session->remove($key);
            }

            $orderNumber = $result['order']['order_number'];
            return $this->redirectToRoute('checkout_confirmation', ['orderNumber' => $orderNumber]);

        } catch (\RuntimeException $e) {
            $this->addFlash('error', 'Could not place your order: ' . $e->getMessage());
            return $this->redirectToRoute('checkout_review');
        }
    }

    /**
     * Order confirmation page shown after successful payment.
     *
     * GET /checkout/confirmation/{orderNumber}
     */
    #[Route('/checkout/confirmation/{orderNumber}', name: 'checkout_confirmation', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function confirmation(string $orderNumber): Response
    {
        // Fetch the order; customer can only access their own via API auth
        $orders = $this->apiClient->authenticatedGet('/orders');
        $order  = collect($orders['data'] ?? [])->firstWhere('order_number', $orderNumber);

        if (! $order) {
            throw $this->createNotFoundException("Order {$orderNumber} not found.");
        }

        return $this->render('checkout/confirmation.html.twig', [
            'order' => $order,
        ]);
    }

    /**
     * Stripe payment success redirect page.
     *
     * GET /checkout/payment/success
     */
    #[Route('/checkout/payment/success', name: 'checkout_payment_success', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function paymentSuccess(Request $request): Response
    {
        $orderNumber = $request->query->get('order_number');
        $this->addFlash('success', 'Payment received successfully!');

        return $this->render('checkout/payment_success.html.twig', [
            'orderNumber' => $orderNumber,
        ]);
    }

    /**
     * Stripe/PayPal payment cancel redirect page.
     *
     * GET /checkout/payment/cancel
     */
    #[Route('/checkout/payment/cancel', name: 'checkout_payment_cancel', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function paymentCancel(): Response
    {
        $this->addFlash('warning', 'Payment was cancelled. Your order has not been placed.');
        return $this->render('checkout/payment_cancel.html.twig');
    }

    // =========================================================================
    // Customer order history
    // =========================================================================

    /**
     * List all orders belonging to the authenticated user.
     *
     * GET /account/orders
     */
    #[Route('/account/orders', name: 'order_index', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function index(Request $request): Response
    {
        $page   = $request->query->getInt('page', 1);
        $orders = $this->apiClient->authenticatedGet('/orders', ['page' => $page, 'per_page' => 10]);

        return $this->render('account/orders/index.html.twig', [
            'orders' => $orders['data'] ?? [],
            'meta'   => $orders['meta'] ?? [],
        ]);
    }

    /**
     * Display details for a single order.
     *
     * GET /account/orders/{id}
     */
    #[Route('/account/orders/{id}', name: 'order_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function show(int $id): Response
    {
        $order = $this->apiClient->authenticatedGet("/orders/{$id}");

        if (! isset($order['order'])) {
            throw $this->createNotFoundException("Order #{$id} not found.");
        }

        return $this->render('account/orders/show.html.twig', [
            'order' => $order['order'],
        ]);
    }

    /**
     * Cancel an order.
     *
     * POST /account/orders/{id}/cancel
     */
    #[Route('/account/orders/{id}/cancel', name: 'order_cancel', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function cancel(int $id, Request $request): Response
    {
        $reason = $request->request->get('reason', 'Customer requested cancellation.');

        try {
            $this->apiClient->authenticatedPost("/orders/{$id}/cancel", ['reason' => $reason]);
            $this->addFlash('success', 'Your cancellation request has been submitted.');
        } catch (\RuntimeException $e) {
            $this->addFlash('error', 'Unable to cancel order: ' . $e->getMessage());
        }

        return $this->redirectToRoute('order_show', ['id' => $id]);
    }

    /**
     * Display and submit a return request form for a delivered order.
     *
     * GET|POST /account/orders/{id}/return
     */
    #[Route('/account/orders/{id}/return', name: 'order_return', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function requestReturn(int $id, Request $request): Response
    {
        $order = $this->apiClient->authenticatedGet("/orders/{$id}");

        if ($request->isMethod('POST')) {
            try {
                $this->apiClient->authenticatedPost("/orders/{$id}/return", [
                    'items'  => $request->request->all('items'),
                    'reason' => $request->request->get('reason'),
                ]);
                $this->addFlash('success', 'Return request submitted successfully.');
                return $this->redirectToRoute('order_show', ['id' => $id]);
            } catch (\RuntimeException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->render('account/orders/return.html.twig', [
            'order' => $order['order'],
        ]);
    }

    /**
     * Download the invoice PDF for an order.
     *
     * GET /account/orders/{id}/invoice
     */
    #[Route('/account/orders/{id}/invoice', name: 'order_invoice', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function downloadInvoice(int $id): Response
    {
        $pdfContent = $this->apiClient->authenticatedGetRaw("/orders/{$id}/invoice");

        return new Response($pdfContent, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => "attachment; filename=invoice-order-{$id}.pdf",
        ]);
    }

    /**
     * View live shipment tracking information for an order.
     *
     * GET /account/orders/{id}/tracking
     */
    #[Route('/account/orders/{id}/tracking', name: 'order_tracking', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function tracking(int $id): Response
    {
        $order    = $this->apiClient->authenticatedGet("/orders/{$id}");
        $tracking = $this->apiClient->authenticatedGet("/orders/{$id}/tracking");

        return $this->render('account/orders/tracking.html.twig', [
            'order'    => $order['order'],
            'tracking' => $tracking['tracking'],
        ]);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function getShippingMethods(): array
    {
        return [
            ['id' => 'standard', 'name' => 'Standard Shipping',   'price' => 4.95,  'days' => '3–5'],
            ['id' => 'express',  'name' => 'Express Shipping',     'price' => 9.95,  'days' => '1–2'],
            ['id' => 'overnight','name' => 'Overnight Delivery',   'price' => 19.95, 'days' => '1'],
            ['id' => 'free',     'name' => 'Free Shipping (€50+)', 'price' => 0.00,  'days' => '5–7'],
        ];
    }
}

<?php

namespace App\Controller;

use App\Service\ApiClient;
use App\Service\CartService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * ProductController
 *
 * Handles public product browsing: catalogue index, category filtering,
 * product detail pages, search results, quick-view modals, and
 * the product comparison feature.
 */
#[Route('/products', name: 'product_')]
class ProductController extends AbstractController
{
    public function __construct(
        private readonly ApiClient  $apiClient,
        private readonly CartService $cartService,
    ) {}

    /**
     * Main product catalogue with filtering, sorting, and pagination.
     *
     * GET /products
     *
     * Query: category_id, brand, min_price, max_price, in_stock, sort, page, per_page
     */
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $filters = $this->extractFilters($request);
        $products = $this->apiClient->get('/products', $filters);
        $categories = $this->apiClient->get('/categories/tree');

        return $this->render('product/index.html.twig', [
            'products'    => $products['data'] ?? [],
            'meta'        => $products['meta'] ?? [],
            'categories'  => $categories['data'] ?? [],
            'filters'     => $filters,
            'sortOptions' => $this->getSortOptions(),
        ]);
    }

    /**
     * Product detail page.
     *
     * GET /products/{slug}
     */
    #[Route('/{slug}', name: 'show', methods: ['GET'], requirements: ['slug' => '[a-z0-9\-]+'])]
    public function show(string $slug): Response
    {
        // Resolve slug to ID via search, then fetch full detail
        $searchResult = $this->apiClient->get('/products', ['filter[slug]' => $slug, 'per_page' => 1]);

        if (empty($searchResult['data'])) {
            throw $this->createNotFoundException("Product '{$slug}' not found.");
        }

        $productId = $searchResult['data'][0]['id'];
        $product   = $this->apiClient->get("/products/{$productId}");
        $reviews   = $this->apiClient->get("/products/{$productId}/reviews", ['per_page' => 5, 'sort' => 'newest']);
        $related   = $this->apiClient->get("/products/{$productId}/related");

        return $this->render('product/show.html.twig', [
            'product' => $product['product'],
            'reviews' => $reviews,
            'related' => $related['products'] ?? [],
        ]);
    }

    /**
     * Product listing filtered to a specific category.
     *
     * GET /products/category/{slug}
     */
    #[Route('/category/{slug}', name: 'category', methods: ['GET'], requirements: ['slug' => '[a-z0-9\-]+'])]
    public function byCategory(string $slug, Request $request): Response
    {
        // Find category by slug
        $categoriesResponse = $this->apiClient->get('/categories');
        $category = collect($categoriesResponse['data'] ?? [])
            ->firstWhere('slug', $slug);

        if (! $category) {
            throw $this->createNotFoundException("Category '{$slug}' not found.");
        }

        $filters = $this->extractFilters($request);
        $filters['filter[category_id]'] = $category['id'];

        $products = $this->apiClient->get('/products', $filters);

        return $this->render('product/category.html.twig', [
            'category'    => $category,
            'products'    => $products['data'] ?? [],
            'meta'        => $products['meta'] ?? [],
            'filters'     => $filters,
            'sortOptions' => $this->getSortOptions(),
        ]);
    }

    /**
     * Product search results page.
     *
     * GET /products/search?q=keyword
     */
    #[Route('/search', name: 'search', methods: ['GET'])]
    public function search(Request $request): Response
    {
        $query = trim($request->query->get('q', ''));

        if (strlen($query) < 2) {
            return $this->redirectToRoute('product_index');
        }

        $results = $this->apiClient->get('/products/search', [
            'q'        => $query,
            'per_page' => $request->query->getInt('per_page', 24),
            'page'     => $request->query->getInt('page', 1),
        ]);

        return $this->render('product/search.html.twig', [
            'query'   => $query,
            'results' => $results['data'] ?? [],
            'meta'    => $results['meta'] ?? [],
        ]);
    }

    /**
     * Featured products showcase.
     *
     * GET /products/featured
     */
    #[Route('/featured', name: 'featured', methods: ['GET'])]
    public function featured(): Response
    {
        $products = $this->apiClient->get('/products/featured', ['limit' => 24]);

        return $this->render('product/featured.html.twig', [
            'products' => $products['products'] ?? [],
        ]);
    }

    /**
     * New arrivals listing.
     *
     * GET /products/new-arrivals
     */
    #[Route('/new-arrivals', name: 'new_arrivals', methods: ['GET'])]
    public function newArrivals(): Response
    {
        $products = $this->apiClient->get('/products/new-arrivals', ['limit' => 24]);

        return $this->render('product/new_arrivals.html.twig', [
            'products' => $products['products'] ?? [],
        ]);
    }

    /**
     * On-sale / clearance products listing.
     *
     * GET /products/on-sale
     */
    #[Route('/on-sale', name: 'on_sale', methods: ['GET'])]
    public function onSale(Request $request): Response
    {
        $products = $this->apiClient->get('/products/on-sale', [
            'limit' => $request->query->getInt('limit', 24),
        ]);

        return $this->render('product/on_sale.html.twig', [
            'products' => $products['products'] ?? [],
        ]);
    }

    /**
     * Bestsellers listing.
     *
     * GET /products/bestsellers
     */
    #[Route('/bestsellers', name: 'bestsellers', methods: ['GET'])]
    public function bestsellers(): Response
    {
        $products = $this->apiClient->get('/products/bestsellers', ['limit' => 24]);

        return $this->render('product/bestsellers.html.twig', [
            'products' => $products['products'] ?? [],
        ]);
    }

    /**
     * Quick-view modal content for a product (AJAX).
     *
     * GET /products/{id}/quickview
     */
    #[Route('/{id}/quickview', name: 'quickview', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function quickview(int $id): Response
    {
        $product = $this->apiClient->get("/products/{$id}");

        if ($this->isXmlHttpRequest()) {
            return $this->render('product/_quickview.html.twig', [
                'product' => $product['product'],
            ]);
        }

        return $this->redirectToRoute('product_show', ['slug' => $product['product']['slug']]);
    }

    /**
     * Product comparison page.
     *
     * GET /products/compare
     */
    #[Route('/compare', name: 'compare', methods: ['GET'])]
    public function compare(Request $request, SessionInterface $session): Response
    {
        $compareIds = $session->get('compare_ids', []);
        $products   = [];

        foreach ($compareIds as $id) {
            $result = $this->apiClient->get("/products/{$id}");
            if (isset($result['product'])) {
                $products[] = $result['product'];
            }
        }

        return $this->render('product/compare.html.twig', [
            'products' => $products,
        ]);
    }

    /**
     * Add a product to the comparison session list (AJAX POST).
     *
     * POST /products/{id}/compare/add
     */
    #[Route('/{id}/compare/add', name: 'compare_add', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function addToCompare(int $id, SessionInterface $session): JsonResponse
    {
        $ids = $session->get('compare_ids', []);

        if (count($ids) >= 4) {
            return new JsonResponse(['success' => false, 'message' => 'You can compare up to 4 products.'], 400);
        }

        if (! in_array($id, $ids, true)) {
            $ids[] = $id;
            $session->set('compare_ids', $ids);
        }

        return new JsonResponse(['success' => true, 'count' => count($ids)]);
    }

    /**
     * Remove a product from the comparison session list (AJAX POST).
     *
     * POST /products/{id}/compare/remove
     */
    #[Route('/{id}/compare/remove', name: 'compare_remove', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function removeFromCompare(int $id, SessionInterface $session): JsonResponse
    {
        $ids = array_filter($session->get('compare_ids', []), fn($i) => $i !== $id);
        $ids = array_values($ids);
        $session->set('compare_ids', $ids);

        return new JsonResponse(['success' => true, 'count' => count($ids)]);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Extract and normalise product filter params from the request.
     */
    private function extractFilters(Request $request): array
    {
        return array_filter([
            'filter[category_id]' => $request->query->get('category_id'),
            'filter[brand]'       => $request->query->get('brand'),
            'filter[min_price]'   => $request->query->get('min_price'),
            'filter[max_price]'   => $request->query->get('max_price'),
            'filter[in_stock]'    => $request->query->get('in_stock'),
            'filter[rating_min]'  => $request->query->get('rating_min'),
            'sort'                => $request->query->get('sort', 'newest'),
            'page'                => $request->query->getInt('page', 1),
            'per_page'            => $request->query->getInt('per_page', 24),
        ]);
    }

    private function getSortOptions(): array
    {
        return [
            'newest'   => 'Newest First',
            'price'    => 'Price: Low to High',
            '-price'   => 'Price: High to Low',
            'rating'   => 'Best Rated',
            'sold_count' => 'Bestsellers',
        ];
    }

    private function isXmlHttpRequest(): bool
    {
        return isset($_SERVER['HTTP_X_REQUESTED_WITH'])
            && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }
}

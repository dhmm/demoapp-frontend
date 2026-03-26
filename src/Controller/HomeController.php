<?php

namespace App\Controller;

use App\Service\ApiClient;
use App\Service\CacheService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * HomeController
 *
 * Renders the landing page, static content pages, global search,
 * and the sitemap XML for SEO.
 */
#[Route('/', name: 'home_')]
class HomeController extends AbstractController
{
    public function __construct(
        private readonly ApiClient    $apiClient,
        private readonly CacheService $cacheService,
    ) {}

    /**
     * Homepage: featured products, bestsellers, category spotlight, banner.
     *
     * GET /
     */
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $data = $this->cacheService->remember('homepage_data', 300, function () {
            return [
                'featured'    => $this->apiClient->get('/products/featured', ['limit' => 8]),
                'bestsellers' => $this->apiClient->get('/products/bestsellers', ['limit' => 8]),
                'newArrivals' => $this->apiClient->get('/products/new-arrivals', ['limit' => 8]),
                'onSale'      => $this->apiClient->get('/products/on-sale', ['limit' => 8]),
                'categories'  => $this->apiClient->get('/categories', ['per_page' => 12]),
            ];
        });

        return $this->render('home/index.html.twig', [
            'featured'    => $data['featured']['products'] ?? [],
            'bestsellers' => $data['bestsellers']['products'] ?? [],
            'newArrivals' => $data['newArrivals']['products'] ?? [],
            'onSale'      => $data['onSale']['products'] ?? [],
            'categories'  => $data['categories']['data'] ?? [],
        ]);
    }

    /**
     * About page.
     *
     * GET /about
     */
    #[Route('about', name: 'about', methods: ['GET'])]
    public function about(): Response
    {
        return $this->render('home/about.html.twig');
    }

    /**
     * Contact form display and submission.
     *
     * GET|POST /contact
     */
    #[Route('contact', name: 'contact', methods: ['GET', 'POST'])]
    public function contact(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $data = $request->request->all();

            // Basic server-side validation
            $errors = $this->validateContactForm($data);

            if (empty($errors)) {
                // In a real app: dispatch a ContactFormSubmittedEvent
                $this->addFlash('success', 'Your message has been sent. We will get back to you shortly.');
                return $this->redirectToRoute('home_contact_success');
            }

            return $this->render('home/contact.html.twig', [
                'errors' => $errors,
                'data'   => $data,
            ]);
        }

        return $this->render('home/contact.html.twig', ['errors' => [], 'data' => []]);
    }

    /**
     * Contact success confirmation page.
     *
     * GET /contact/success
     */
    #[Route('contact/success', name: 'contact_success', methods: ['GET'])]
    public function contactSuccess(): Response
    {
        return $this->render('home/contact_success.html.twig');
    }

    /**
     * Site-wide search results page.
     *
     * GET /search?q=keyword
     */
    #[Route('search', name: 'search', methods: ['GET'])]
    public function search(Request $request): Response
    {
        $query   = trim($request->query->get('q', ''));
        $page    = $request->query->getInt('page', 1);
        $perPage = $request->query->getInt('per_page', 24);

        if (strlen($query) < 2) {
            return $this->render('home/search.html.twig', [
                'query'   => $query,
                'results' => [],
                'total'   => 0,
            ]);
        }

        $results = $this->apiClient->get('/products/search', [
            'q'        => $query,
            'page'     => $page,
            'per_page' => $perPage,
        ]);

        return $this->render('home/search.html.twig', [
            'query'       => $query,
            'results'     => $results['data'] ?? [],
            'total'       => $results['meta']['total'] ?? 0,
            'currentPage' => $page,
            'lastPage'    => $results['meta']['last_page'] ?? 1,
        ]);
    }

    /**
     * Generate sitemap.xml for SEO crawlers.
     *
     * GET /sitemap.xml
     */
    #[Route('sitemap.xml', name: 'sitemap', methods: ['GET'])]
    public function sitemap(): Response
    {
        $products   = $this->apiClient->get('/products', ['per_page' => 500])['data'] ?? [];
        $categories = $this->apiClient->get('/categories')['data'] ?? [];

        $response = $this->render('home/sitemap.xml.twig', [
            'products'   => $products,
            'categories' => $categories,
        ]);

        $response->headers->set('Content-Type', 'application/xml');
        return $response;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function validateContactForm(array $data): array
    {
        $errors = [];

        if (empty($data['name'])) {
            $errors['name'] = 'Name is required.';
        }

        if (empty($data['email']) || ! filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'A valid email address is required.';
        }

        if (empty($data['message']) || strlen($data['message']) < 10) {
            $errors['message'] = 'Message must be at least 10 characters.';
        }

        return $errors;
    }
}

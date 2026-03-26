<?php

namespace App\Controller;

use App\Service\ApiClient;
use App\Service\SessionAuthService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * AuthController
 *
 * Handles user-facing authentication pages: registration, login, logout,
 * password reset, email verification, and two-factor authentication.
 *
 * All interactions with the auth API are delegated to SessionAuthService,
 * which manages JWT tokens in the Symfony session.
 */
#[Route('', name: 'auth_')]
class AuthController extends AbstractController
{
    public function __construct(
        private readonly ApiClient          $apiClient,
        private readonly SessionAuthService $sessionAuth,
    ) {}

    /**
     * Display the registration form and handle submission.
     *
     * GET|POST /register
     */
    #[Route('/register', name: 'register', methods: ['GET', 'POST'])]
    public function register(Request $request): Response
    {
        if ($this->sessionAuth->isLoggedIn()) {
            return $this->redirectToRoute('home_index');
        }

        if ($request->isMethod('POST')) {
            $data = $request->request->all();

            // Client-side validation mirror
            $errors = $this->validateRegistration($data);
            if (! empty($errors)) {
                return $this->render('auth/register.html.twig', ['errors' => $errors, 'data' => $data]);
            }

            try {
                $result = $this->apiClient->post('/auth/register', [
                    'name'                  => $data['name'],
                    'email'                 => $data['email'],
                    'password'              => $data['password'],
                    'password_confirmation' => $data['password_confirmation'],
                ]);

                $this->sessionAuth->storeToken($result['token'], $result['user']);
                $this->addFlash('success', 'Account created! Please check your email to verify your address.');

                return $this->redirectToRoute('home_index');

            } catch (\RuntimeException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->render('auth/register.html.twig', ['errors' => [], 'data' => []]);
    }

    /**
     * Display the login form and handle submission.
     *
     * GET|POST /login
     */
    #[Route('/login', name: 'login', methods: ['GET', 'POST'])]
    public function login(Request $request): Response
    {
        if ($this->sessionAuth->isLoggedIn()) {
            return $this->redirectToRoute('home_index');
        }

        if ($request->isMethod('POST')) {
            $email    = $request->request->get('email');
            $password = $request->request->get('password');

            try {
                $result = $this->apiClient->post('/auth/login', [
                    'email'    => $email,
                    'password' => $password,
                ]);

                // Two-factor required
                if ($result['two_factor_required'] ?? false) {
                    $request->getSession()->set('auth.partial_token', $result['partial_token']);
                    return $this->redirectToRoute('auth_two_factor');
                }

                $this->sessionAuth->storeToken($result['access_token'], $result['user']);
                $this->addFlash('success', 'Welcome back, ' . $result['user']['name'] . '!');

                $redirect = $request->query->get('redirect', $this->generateUrl('home_index'));
                return $this->redirect($redirect);

            } catch (\RuntimeException $e) {
                $this->addFlash('error', 'Invalid email or password.');
            }
        }

        return $this->render('auth/login.html.twig', [
            'last_username' => $request->request->get('email', ''),
        ]);
    }

    /**
     * Log out the current user and invalidate the backend token.
     *
     * POST /logout
     */
    #[Route('/logout', name: 'logout', methods: ['POST'])]
    public function logout(Request $request): Response
    {
        if ($this->sessionAuth->isLoggedIn()) {
            try {
                $this->apiClient->authenticatedPost('/auth/logout', []);
            } catch (\RuntimeException) {
                // Token may already be expired — still clear local session
            }
            $this->sessionAuth->clearSession();
        }

        $this->addFlash('success', 'You have been logged out.');
        return $this->redirectToRoute('home_index');
    }

    /**
     * Forgot-password form: request a reset link.
     *
     * GET|POST /forgot-password
     */
    #[Route('/forgot-password', name: 'forgot_password', methods: ['GET', 'POST'])]
    public function forgotPassword(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $email = $request->request->get('email');

            try {
                $this->apiClient->post('/auth/forgot-password', ['email' => $email]);
                // Always show success to avoid email enumeration
                $this->addFlash('success', 'If an account exists for this email, a reset link has been sent.');
            } catch (\RuntimeException) {
                $this->addFlash('success', 'If an account exists for this email, a reset link has been sent.');
            }

            return $this->redirectToRoute('auth_login');
        }

        return $this->render('auth/forgot_password.html.twig');
    }

    /**
     * Reset password via signed token from email link.
     *
     * GET|POST /reset-password/{token}
     */
    #[Route('/reset-password/{token}', name: 'reset_password', methods: ['GET', 'POST'])]
    public function resetPassword(string $token, Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $data = $request->request->all();

            if ($data['password'] !== $data['password_confirmation']) {
                $this->addFlash('error', 'Passwords do not match.');
                return $this->redirectToRoute('auth_reset_password', ['token' => $token]);
            }

            try {
                $this->apiClient->post('/auth/reset-password', [
                    'token'                 => $token,
                    'email'                 => $data['email'],
                    'password'              => $data['password'],
                    'password_confirmation' => $data['password_confirmation'],
                ]);

                $this->addFlash('success', 'Password reset successfully. You can now log in.');
                return $this->redirectToRoute('auth_login');

            } catch (\RuntimeException $e) {
                $this->addFlash('error', 'Invalid or expired reset link.');
            }
        }

        return $this->render('auth/reset_password.html.twig', ['token' => $token]);
    }

    /**
     * Verify email address via the signed link from the verification email.
     *
     * GET /verify-email/{token}
     */
    #[Route('/verify-email/{token}', name: 'verify_email', methods: ['GET'])]
    public function verifyEmail(string $token): Response
    {
        try {
            $this->apiClient->post("/auth/verify-email/{$token}", []);
            $this->addFlash('success', 'Email verified! You can now log in.');
        } catch (\RuntimeException) {
            $this->addFlash('error', 'Verification link is invalid or has expired.');
        }

        return $this->redirectToRoute('auth_login');
    }

    /**
     * Resend email verification link.
     *
     * POST /resend-verification
     */
    #[Route('/resend-verification', name: 'resend_verification', methods: ['POST'])]
    public function resendVerification(Request $request): Response
    {
        $email = $request->request->get('email');

        try {
            $this->apiClient->post('/auth/resend-verification', ['email' => $email]);
            $this->addFlash('success', 'Verification email resent.');
        } catch (\RuntimeException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('auth_login');
    }

    /**
     * Complete the 2FA login step by entering a TOTP code.
     *
     * GET|POST /two-factor
     */
    #[Route('/two-factor', name: 'two_factor', methods: ['GET', 'POST'])]
    public function twoFactor(Request $request): Response
    {
        $partialToken = $request->getSession()->get('auth.partial_token');

        if (! $partialToken) {
            return $this->redirectToRoute('auth_login');
        }

        if ($request->isMethod('POST')) {
            $code = $request->request->get('code');

            try {
                $result = $this->apiClient->post('/auth/two-factor/verify', [
                    'partial_token' => $partialToken,
                    'code'          => $code,
                ]);

                $request->getSession()->remove('auth.partial_token');
                $this->sessionAuth->storeToken($result['access_token'], $result['user']);
                $this->addFlash('success', 'Two-factor verification successful.');

                return $this->redirectToRoute('home_index');

            } catch (\RuntimeException $e) {
                $this->addFlash('error', 'Invalid 2FA code. Please try again.');
            }
        }

        return $this->render('auth/two_factor.html.twig');
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function validateRegistration(array $data): array
    {
        $errors = [];

        if (empty($data['name'])) {
            $errors['name'] = 'Name is required.';
        }

        if (empty($data['email']) || ! filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'A valid email address is required.';
        }

        if (empty($data['password']) || strlen($data['password']) < 8) {
            $errors['password'] = 'Password must be at least 8 characters.';
        }

        if ($data['password'] !== ($data['password_confirmation'] ?? '')) {
            $errors['password_confirmation'] = 'Passwords do not match.';
        }

        return $errors;
    }
}

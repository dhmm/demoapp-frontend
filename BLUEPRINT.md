# DemoShop Frontend — Functional Specification

**Technology**: Symfony 7 (PHP 8.2+), Twig templates, Webpack Encore
**Architecture**: Server-side rendered frontend; consumes Laravel REST API
**Purpose**: E-commerce storefront demonstrating scenario generation for ASUP automation tool

---

## 1. Project Overview

DemoShop Frontend is a Symfony application that renders the customer-facing storefront for the DemoShop e-commerce platform. It consumes the Laravel REST API backend for all data operations. Session-based authentication stores the JWT token obtained from the API. For guest users, cart state is maintained in the session and merged on login.

### Key Design Goals
- Rich, multi-step user journeys for maximum scenario coverage
- Consistent Twig template hierarchy (base layout → page templates → partials)
- AJAX-enhanced pages (mini-cart, quick-view, compare) alongside full-page fallbacks
- Full checkout flow with address, shipping, payment, and review steps
- Customer account area with orders, returns, wishlist, notifications

---

## 2. Functional Domains

### 2.1 Homepage & Navigation

| Feature | Description |
|---------|-------------|
| Homepage | Hero banner, featured products, bestsellers, new arrivals, on-sale sections, category grid |
| Global Search | Search bar in header; results page at /search |
| Category Navigation | Mega-menu from /categories/tree API response |
| About Page | Static company information page |
| Contact Form | Contact form with server-side validation; confirmation redirect |
| Sitemap XML | Auto-generated from product and category lists |
| Responsive Design | Mobile-first layout with TailwindCSS |
| Dark Mode | CSS variable-based dark mode support |

---

### 2.2 Authentication

| Feature | Description |
|---------|-------------|
| Register | Name, email, password form; calls POST /auth/register; stores JWT in session |
| Login | Email/password form; handles 2FA redirect if required |
| Logout | Invalidates backend token; clears Symfony session |
| Forgot Password | Email form; sends reset link via API |
| Reset Password | Token-based form; /reset-password/{token} |
| Email Verification | /verify-email/{token} endpoint processes the link from verification email |
| Resend Verification | Form for users who did not receive the email |
| Two-Factor Auth | TOTP code entry page; uses partial_token from login response |
| Session Token Storage | JWT stored in Symfony session via SessionAuthService |
| Redirect on Login | Preserves intended URL via `?redirect=` query param |

**Acceptance Criteria (auth):**
- After registration, user is redirected to homepage with success flash
- Invalid login credentials show inline error without revealing which field is wrong
- 2FA step is triggered when API returns `two_factor_required: true`
- Logout clears both server token and session; redirect to homepage
- Expired verification links show friendly error message

---

### 2.3 Product Catalogue

| Feature | Description |
|---------|-------------|
| Product Index | Grid view; pagination; sidebar filters |
| Category Page | Products scoped to category; breadcrumb navigation |
| Product Detail | Full page: images, description, attributes, variants, reviews |
| Quick-View Modal | AJAX-loaded partial for preview without leaving the listing page |
| Product Search | Full-text search results page |
| Featured | Dedicated page /products/featured |
| New Arrivals | Dedicated page /products/new-arrivals |
| On Sale | Dedicated page /products/on-sale with discount badges |
| Bestsellers | Dedicated page /products/bestsellers |
| Product Comparison | Up to 4 products; session-stored list; attribute comparison table |
| Add to Wishlist | Authenticated users; icon toggle on product cards |

**Filters (sidebar):**
- Category (from category tree)
- Brand (checkboxes)
- Price range (dual slider + inputs)
- In Stock toggle
- Minimum rating (star selector)

**Sort options:** Newest, Price Low–High, Price High–Low, Best Rated, Bestsellers

---

### 2.4 Shopping Cart

| Feature | Description |
|---------|-------------|
| Cart Page | Line items with quantities, images, subtotals, totals |
| Mini-Cart | Header dropdown partial (AJAX); item count badge |
| Add to Cart | POST from product page; AJAX response updates mini-cart count |
| Update Quantity | Inline +/- controls; AJAX update on change |
| Remove Item | Per-line remove button; AJAX updates totals |
| Clear Cart | Remove all items |
| Coupon | Apply/remove discount codes; inline validation against API |
| Cart Summary | Subtotal, shipping estimate, discount, VAT (21%), grand total |
| Guest Cart | Session-based; merged to server cart on login |
| Persistence | Authenticated carts persist on server; guest carts in session |

---

### 2.5 Checkout (Multi-Step)

| Step | Route | Description |
|------|-------|-------------|
| 1. Address | /checkout/address | Select from saved addresses or create new |
| 2. Shipping | /checkout/shipping | Choose shipping method (standard/express/overnight) |
| 3. Payment | /checkout/payment | Stripe card form or PayPal button; saved methods |
| 4. Review | /checkout/review | Summary before confirmation; back navigation |
| Place Order | POST /checkout/place-order | Calls backend POST /orders; redirects to confirmation |
| Confirmation | /checkout/confirmation/{orderNumber} | Thank-you page with order summary |
| Payment Success | /checkout/payment/success | Stripe redirect success page |
| Payment Cancel | /checkout/payment/cancel | Stripe/PayPal cancel return page |

**Step persistence:** Selected address, shipping method, and payment method stored in session between steps. Progress indicator (step 1/2/3/4) shown throughout.

---

### 2.6 Order Management (Customer)

| Feature | Description |
|---------|-------------|
| Order History | Paginated list with status badges and quick actions |
| Order Detail | Full details: items, addresses, payment info, shipment status |
| Cancel Order | Cancel form with reason; available for pending/confirmed orders |
| Return Request | Multi-item return form; reason required; only for delivered orders |
| Download Invoice | Proxied PDF download from backend API |
| Shipment Tracking | Visual tracking timeline from carrier API data |

---

### 2.7 Customer Account

| Feature | Description |
|---------|-------------|
| Dashboard | Account overview: recent orders, wishlist count, notification count |
| Profile Edit | Name, email, phone; saves via PUT /users/profile |
| Avatar Upload | Image upload; previews new avatar before save |
| Change Password | Current + new password form |
| Delete Account | Confirmation dialog; cascades to API DELETE /users/account |
| Notifications | Paginated list; mark read / mark all read |
| Saved Addresses | List, create, edit, delete, set-as-default |
| Wishlist | Product grid; remove items; one-click move to cart |
| My Reviews | List of submitted reviews; edit or delete |

---

### 2.8 Static / Informational Pages

| Route | Content |
|-------|---------|
| /about | Company story, team, mission |
| /contact | Contact form + office details |
| /contact/success | Confirmation after form submission |
| /sitemap.xml | SEO sitemap generated from live product/category data |

---

## 3. Technical Architecture

### 3.1 Template Hierarchy

```
templates/
├── base.html.twig                  # Master layout: nav, header, footer, flash messages
├── home/
│   ├── index.html.twig             # Homepage
│   ├── about.html.twig
│   ├── contact.html.twig
│   ├── contact_success.html.twig
│   ├── search.html.twig
│   └── sitemap.xml.twig
├── auth/
│   ├── login.html.twig
│   ├── register.html.twig
│   ├── forgot_password.html.twig
│   ├── reset_password.html.twig
│   └── two_factor.html.twig
├── product/
│   ├── index.html.twig             # Catalogue with filters
│   ├── show.html.twig              # Product detail
│   ├── category.html.twig
│   ├── search.html.twig
│   ├── featured.html.twig
│   ├── new_arrivals.html.twig
│   ├── on_sale.html.twig
│   ├── bestsellers.html.twig
│   ├── compare.html.twig
│   └── _quickview.html.twig        # AJAX partial
├── cart/
│   ├── index.html.twig             # Full cart page
│   ├── _mini.html.twig             # Header mini-cart partial
│   └── _summary.html.twig          # Summary sidebar partial
├── checkout/
│   ├── address.html.twig           # Step 1
│   ├── shipping.html.twig          # Step 2
│   ├── payment.html.twig           # Step 3
│   ├── review.html.twig            # Step 4
│   ├── confirmation.html.twig
│   ├── payment_success.html.twig
│   └── payment_cancel.html.twig
└── account/
    ├── dashboard.html.twig
    ├── profile.html.twig
    ├── change_password.html.twig
    ├── notifications.html.twig
    ├── addresses/
    │   ├── index.html.twig
    │   ├── new.html.twig
    │   └── edit.html.twig
    ├── orders/
    │   ├── index.html.twig
    │   ├── show.html.twig
    │   ├── return.html.twig
    │   └── tracking.html.twig
    ├── wishlist.html.twig
    └── reviews/
        ├── index.html.twig
        └── edit.html.twig
```

### 3.2 Services

| Service | Responsibility |
|---------|----------------|
| `ApiClient` | HTTP wrapper for all Laravel API calls; JWT injection; error normalisation |
| `CartService` | Cart CRUD; guest/authenticated cart management; merge on login |
| `SessionAuthService` | Store/retrieve/clear JWT and user info from Symfony session |
| `CacheService` | In-memory TTL cache for expensive public API calls (homepage data) |

### 3.3 Entities (DTOs)

| Entity | Source |
|--------|--------|
| `Product` | fromArray() factory method; typed accessors |
| `Order` | fromArray() factory method; status helpers |
| `User` | fromArray() factory method; role checks; initials helper |

---

## 4. Routing Summary

| Method | Route | Controller | Action |
|--------|-------|------------|--------|
| GET | / | HomeController | index |
| GET | /about | HomeController | about |
| GET, POST | /contact | HomeController | contact |
| GET | /contact/success | HomeController | contactSuccess |
| GET | /search | HomeController | search |
| GET | /sitemap.xml | HomeController | sitemap |
| GET, POST | /register | AuthController | register |
| GET, POST | /login | AuthController | login |
| POST | /logout | AuthController | logout |
| GET, POST | /forgot-password | AuthController | forgotPassword |
| GET, POST | /reset-password/{token} | AuthController | resetPassword |
| GET | /verify-email/{token} | AuthController | verifyEmail |
| POST | /resend-verification | AuthController | resendVerification |
| GET, POST | /two-factor | AuthController | twoFactor |
| GET | /products | ProductController | index |
| GET | /products/{slug} | ProductController | show |
| GET | /products/category/{slug} | ProductController | byCategory |
| GET | /products/search | ProductController | search |
| GET | /products/featured | ProductController | featured |
| GET | /products/new-arrivals | ProductController | newArrivals |
| GET | /products/on-sale | ProductController | onSale |
| GET | /products/bestsellers | ProductController | bestsellers |
| GET | /products/{id}/quickview | ProductController | quickview |
| GET, POST | /products/compare | ProductController | compare |
| POST | /products/{id}/compare/add | ProductController | addToCompare |
| POST | /products/{id}/compare/remove | ProductController | removeFromCompare |
| GET | /cart | CartController | index |
| GET | /cart/mini | CartController | mini |
| GET | /cart/summary | CartController | summary |
| POST | /cart/add/{productId} | CartController | add |
| POST | /cart/update/{itemId} | CartController | update |
| POST | /cart/remove/{itemId} | CartController | remove |
| POST | /cart/clear | CartController | clear |
| POST | /cart/coupon/apply | CartController | applyCoupon |
| POST | /cart/coupon/remove | CartController | removeCoupon |
| GET | /checkout | OrderController | checkout |
| GET, POST | /checkout/address | OrderController | selectAddress |
| GET, POST | /checkout/shipping | OrderController | selectShipping |
| GET, POST | /checkout/payment | OrderController | payment |
| GET | /checkout/review | OrderController | review |
| POST | /checkout/place-order | OrderController | placeOrder |
| GET | /checkout/confirmation/{orderNumber} | OrderController | confirmation |
| GET | /checkout/payment/success | OrderController | paymentSuccess |
| GET | /checkout/payment/cancel | OrderController | paymentCancel |
| GET | /account/orders | OrderController | index |
| GET | /account/orders/{id} | OrderController | show |
| POST | /account/orders/{id}/cancel | OrderController | cancel |
| GET, POST | /account/orders/{id}/return | OrderController | requestReturn |
| GET | /account/orders/{id}/invoice | OrderController | downloadInvoice |
| GET | /account/orders/{id}/tracking | OrderController | tracking |
| GET | /account | AccountController | dashboard |
| GET, POST | /account/profile | AccountController | profile |
| POST | /account/avatar | AccountController | updateAvatar |
| GET, POST | /account/change-password | AccountController | changePassword |
| GET, POST | /account/delete | AccountController | deleteAccount |
| GET | /account/notifications | AccountController | notifications |
| POST | /account/notifications/{id}/read | AccountController | markNotificationRead |
| POST | /account/notifications/read-all | AccountController | markAllNotificationsRead |
| GET | /account/addresses | AccountController | addresses |
| GET, POST | /account/addresses/new | AccountController | newAddress |
| GET, POST | /account/addresses/{id}/edit | AccountController | editAddress |
| POST | /account/addresses/{id}/delete | AccountController | deleteAddress |
| POST | /account/addresses/{id}/set-default | AccountController | setDefaultAddress |
| GET | /account/wishlist | AccountController | wishlist |
| POST | /account/wishlist/add/{productId} | AccountController | addToWishlist |
| POST | /account/wishlist/remove/{productId} | AccountController | removeFromWishlist |
| POST | /account/wishlist/move-to-cart/{productId} | AccountController | moveWishlistToCart |
| GET | /account/reviews | AccountController | reviews |
| GET, POST | /account/reviews/{id}/edit | AccountController | editReview |
| POST | /account/reviews/{id}/delete | AccountController | deleteReview |

Total: **70+ routes / controller actions**

---

## 5. Security

- All `/account/*` and `/checkout/*` routes require `ROLE_USER` (enforced via `#[IsGranted]`)
- JWT tokens stored in server-side Symfony session (never in localStorage)
- CSRF tokens on all POST forms (Symfony form component or `{{ csrf_token() }}`)
- Guest cart uses session ID isolation
- Password reset tokens are single-use and expire in 1 hour (enforced by backend)
- File uploads restricted to image types; size limit 2 MB (frontend validation + backend enforcement)

---

## 6. Integration Points

### Backend API (Laravel)
- Base URL configurable via `BACKEND_API_URL` environment variable
- All authenticated calls inject `Authorization: Bearer {token}` header
- Error responses normalised by `ApiClient`; displayed as flash messages

### Stripe (Embedded via JS)
- Stripe.js loaded on `/checkout/payment` page
- Payment Intent client secret fetched from backend and used to mount Stripe Elements

### PayPal (Redirect flow)
- PayPal button rendered via PayPal JS SDK on `/checkout/payment`
- On approval, frontend calls `/checkout/place-order` with PayPal order ID

---

## 7. Non-Functional Requirements

- **Performance**: Homepage data cached for 5 minutes (CacheService)
- **Accessibility**: Semantic HTML, ARIA labels on interactive components
- **SEO**: Canonical URLs, Open Graph meta tags, XML sitemap
- **Error Handling**: 404 and 500 error pages with consistent layout
- **Internationalisation**: Translation keys in messages.en.yaml (single locale for demo)
- **Currency**: All prices in EUR (€); formatted as European style (1.000,00)

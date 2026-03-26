<?php

namespace App\Entity;

/**
 * Product entity (DTO).
 *
 * Represents a product as returned by the backend API.
 * Used for type-safe access in Twig templates and service layer.
 */
class Product
{
    public function __construct(
        public readonly int     $id,
        public readonly string  $name,
        public readonly string  $slug,
        public readonly string  $description,
        public readonly ?string $shortDescription,
        public readonly float   $price,
        public readonly ?float  $salePrice,
        public readonly int     $stockQuantity,
        public readonly string  $status,
        public readonly int     $categoryId,
        public readonly ?string $brand,
        public readonly ?string $sku,
        public readonly ?float  $weightKg,
        public readonly bool    $isFeatured,
        public readonly int     $soldCount,
        public readonly ?array  $images,
        public readonly ?array  $attributes,
        public readonly ?array  $variants,
        public readonly ?float  $reviewsAvgRating,
        public readonly int     $reviewsCount,
        public readonly string  $createdAt,
    ) {}

    /**
     * Construct a Product from a raw API response array.
     *
     * @param  array  $data
     * @return static
     */
    public static function fromArray(array $data): static
    {
        return new static(
            id:               (int)    ($data['id'] ?? 0),
            name:                       $data['name'] ?? '',
            slug:                       $data['slug'] ?? '',
            description:                $data['description'] ?? '',
            shortDescription:           $data['short_description'] ?? null,
            price:            (float)  ($data['price'] ?? 0),
            salePrice:        isset($data['sale_price']) ? (float) $data['sale_price'] : null,
            stockQuantity:    (int)    ($data['stock_quantity'] ?? 0),
            status:                     $data['status'] ?? 'draft',
            categoryId:       (int)    ($data['category_id'] ?? 0),
            brand:                      $data['brand'] ?? null,
            sku:                        $data['sku'] ?? null,
            weightKg:         isset($data['weight_kg']) ? (float) $data['weight_kg'] : null,
            isFeatured:       (bool)   ($data['is_featured'] ?? false),
            soldCount:        (int)    ($data['sold_count'] ?? 0),
            images:                     $data['images'] ?? [],
            attributes:                 $data['attributes'] ?? [],
            variants:                   $data['variants'] ?? [],
            reviewsAvgRating: isset($data['reviews_avg_rating']) ? (float) $data['reviews_avg_rating'] : null,
            reviewsCount:     (int)    ($data['reviews_count'] ?? 0),
            createdAt:                  $data['created_at'] ?? '',
        );
    }

    /**
     * The effective selling price (sale_price takes priority).
     */
    public function getEffectivePrice(): float
    {
        return $this->salePrice ?? $this->price;
    }

    /**
     * Discount percentage relative to original price, or 0.
     */
    public function getDiscountPercent(): int
    {
        if (! $this->salePrice || $this->salePrice >= $this->price) {
            return 0;
        }
        return (int) round((1 - $this->salePrice / $this->price) * 100);
    }

    public function isInStock(): bool
    {
        return $this->stockQuantity > 0;
    }

    public function isOnSale(): bool
    {
        return $this->salePrice !== null && $this->salePrice < $this->price;
    }

    public function getPrimaryImage(): ?array
    {
        if (empty($this->images)) {
            return null;
        }
        foreach ($this->images as $image) {
            if ($image['is_primary'] ?? false) {
                return $image;
            }
        }
        return $this->images[0];
    }

    public function getPrimaryImageUrl(): ?string
    {
        return $this->getPrimaryImage()['url'] ?? null;
    }
}

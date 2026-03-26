<?php

namespace App\Entity;

/**
 * Order entity (DTO).
 *
 * Represents an order as returned by the backend API.
 */
class Order
{
    public function __construct(
        public readonly int     $id,
        public readonly string  $orderNumber,
        public readonly int     $userId,
        public readonly float   $subtotal,
        public readonly float   $shippingCost,
        public readonly float   $discountAmount,
        public readonly float   $taxAmount,
        public readonly float   $totalAmount,
        public readonly string  $status,
        public readonly string  $paymentStatus,
        public readonly ?string $couponCode,
        public readonly ?string $notes,
        public readonly array   $items,
        public readonly ?array  $shippingAddress,
        public readonly ?array  $billingAddress,
        public readonly ?array  $payment,
        public readonly ?array  $shipment,
        public readonly string  $createdAt,
    ) {}

    /**
     * Construct an Order from a raw API response array.
     */
    public static function fromArray(array $data): static
    {
        return new static(
            id:              (int)   ($data['id'] ?? 0),
            orderNumber:             $data['order_number'] ?? '',
            userId:          (int)   ($data['user_id'] ?? 0),
            subtotal:        (float) ($data['subtotal'] ?? 0),
            shippingCost:    (float) ($data['shipping_cost'] ?? 0),
            discountAmount:  (float) ($data['discount_amount'] ?? 0),
            taxAmount:       (float) ($data['tax_amount'] ?? 0),
            totalAmount:     (float) ($data['total_amount'] ?? 0),
            status:                  $data['status'] ?? 'pending',
            paymentStatus:           $data['payment_status'] ?? 'pending',
            couponCode:              $data['coupon_code'] ?? null,
            notes:                   $data['notes'] ?? null,
            items:                   $data['items'] ?? [],
            shippingAddress:         $data['shipping_address'] ?? null,
            billingAddress:          $data['billing_address'] ?? null,
            payment:                 $data['payment'] ?? null,
            shipment:                $data['shipment'] ?? null,
            createdAt:               $data['created_at'] ?? '',
        );
    }

    public function isCancellable(): bool
    {
        return in_array($this->status, ['pending', 'confirmed'], true);
    }

    public function isReturnable(): bool
    {
        return $this->status === 'delivered';
    }

    public function isPaid(): bool
    {
        return $this->paymentStatus === 'paid';
    }

    public function isRefunded(): bool
    {
        return in_array($this->paymentStatus, ['refunded', 'partial_refund'], true);
    }

    public function getStatusLabel(): string
    {
        return match($this->status) {
            'pending'    => 'Pending',
            'confirmed'  => 'Confirmed',
            'processing' => 'Processing',
            'shipped'    => 'Shipped',
            'delivered'  => 'Delivered',
            'cancelled'  => 'Cancelled',
            'refunded'   => 'Refunded',
            default      => ucfirst($this->status),
        };
    }

    public function getStatusColour(): string
    {
        return match($this->status) {
            'pending'    => 'yellow',
            'confirmed'  => 'blue',
            'processing' => 'indigo',
            'shipped'    => 'purple',
            'delivered'  => 'green',
            'cancelled'  => 'red',
            'refunded'   => 'gray',
            default      => 'gray',
        };
    }

    public function getItemCount(): int
    {
        return array_sum(array_column($this->items, 'quantity'));
    }
}

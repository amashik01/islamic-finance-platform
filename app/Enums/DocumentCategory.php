<?php

namespace App\Enums;

enum DocumentCategory: string
{
    case Kyc = 'KYC';
    case BusinessRegistration = 'BUSINESS_REGISTRATION';
    case Contract = 'CONTRACT';
    case Invoice = 'INVOICE';
    case PurchaseOrder = 'PURCHASE_ORDER';
    case SupplierDocument = 'SUPPLIER_DOCUMENT';
    case SalesDocument = 'SALES_DOCUMENT';
    case DeliveryProof = 'DELIVERY_PROOF';
    case PaymentProof = 'PAYMENT_PROOF';
    case FinancialStatement = 'FINANCIAL_STATEMENT';
    case ShariahReview = 'SHARIAH_REVIEW';
    case Settlement = 'SETTLEMENT';

    public function label(): string
    {
        return match ($this) {
            self::Kyc => 'KYC',
            self::BusinessRegistration => 'Business registration',
            self::Contract => 'Contract',
            self::Invoice => 'Invoice',
            self::PurchaseOrder => 'Purchase order',
            self::SupplierDocument => 'Supplier document',
            self::SalesDocument => 'Sales document',
            self::DeliveryProof => 'Delivery proof',
            self::PaymentProof => 'Payment proof',
            self::FinancialStatement => 'Financial statement',
            self::ShariahReview => 'Shariah review',
            self::Settlement => 'Settlement',
        };
    }

    /** @return array<string, string> value => label */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}

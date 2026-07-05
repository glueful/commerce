<?php

declare(strict_types=1);

namespace Glueful\Extensions\Commerce\Support;

/**
 * Integer minor-unit money helpers. Arithmetic elsewhere stays pure integer;
 * only display/serialization consults the exponent.
 */
final class Money
{
    /** @var array<string, int> */
    private const EXPONENT_EXCEPTIONS = [
        'BIF' => 0,
        'CLP' => 0,
        'DJF' => 0,
        'GNF' => 0,
        'ISK' => 0,
        'JPY' => 0,
        'KMF' => 0,
        'KRW' => 0,
        'PYG' => 0,
        'RWF' => 0,
        'UGX' => 0,
        'VND' => 0,
        'VUV' => 0,
        'XAF' => 0,
        'XOF' => 0,
        'XPF' => 0,
        'BHD' => 3,
        'IQD' => 3,
        'JOD' => 3,
        'KWD' => 3,
        'LYD' => 3,
        'OMR' => 3,
        'TND' => 3,
    ];

    private const TWO_DECIMAL = [
        'AED',
        'AFN',
        'ALL',
        'AMD',
        'ANG',
        'AOA',
        'ARS',
        'AUD',
        'AWG',
        'AZN',
        'BAM',
        'BBD',
        'BDT',
        'BGN',
        'BMD',
        'BND',
        'BOB',
        'BRL',
        'BSD',
        'BWP',
        'BYN',
        'BZD',
        'CAD',
        'CDF',
        'CHF',
        'CNY',
        'COP',
        'CRC',
        'CUP',
        'CVE',
        'CZK',
        'DKK',
        'DOP',
        'DZD',
        'EGP',
        'ERN',
        'ETB',
        'EUR',
        'FJD',
        'GBP',
        'GEL',
        'GHS',
        'GIP',
        'GMD',
        'GTQ',
        'GYD',
        'HKD',
        'HNL',
        'HTG',
        'HUF',
        'IDR',
        'ILS',
        'INR',
        'JMD',
        'KES',
        'KGS',
        'KHR',
        'KYD',
        'KZT',
        'LAK',
        'LBP',
        'LKR',
        'LRD',
        'LSL',
        'MAD',
        'MDL',
        'MGA',
        'MKD',
        'MMK',
        'MNT',
        'MOP',
        'MUR',
        'MVR',
        'MWK',
        'MXN',
        'MYR',
        'MZN',
        'NAD',
        'NGN',
        'NIO',
        'NOK',
        'NPR',
        'NZD',
        'PAB',
        'PEN',
        'PGK',
        'PHP',
        'PKR',
        'PLN',
        'QAR',
        'RON',
        'RSD',
        'RUB',
        'SAR',
        'SBD',
        'SCR',
        'SEK',
        'SGD',
        'SLE',
        'SOS',
        'SRD',
        'SSP',
        'STN',
        'SZL',
        'THB',
        'TJS',
        'TMT',
        'TOP',
        'TRY',
        'TTD',
        'TWD',
        'TZS',
        'UAH',
        'USD',
        'UYU',
        'UZS',
        'WST',
        'XCD',
        'YER',
        'ZAR',
        'ZMW',
    ];

    public static function exponentFor(string $code): ?int
    {
        if (isset(self::EXPONENT_EXCEPTIONS[$code])) {
            return self::EXPONENT_EXCEPTIONS[$code];
        }

        return in_array($code, self::TWO_DECIMAL, true) ? 2 : null;
    }

    public static function assertValidCurrency(string $code): void
    {
        if (self::exponentFor($code) === null) {
            throw new \InvalidArgumentException("Unknown ISO 4217 currency code '{$code}'.");
        }
    }

    public static function format(int $amount, string $code): string
    {
        $exponent = self::exponentFor($code);
        if ($exponent === null) {
            throw new \InvalidArgumentException("Unknown ISO 4217 currency code '{$code}'.");
        }

        if ($exponent === 0) {
            return (string) $amount;
        }

        $sign = $amount < 0 ? '-' : '';
        $absolute = abs($amount);
        $divisor = 10 ** $exponent;

        return sprintf(
            '%s%d.%0' . $exponent . 'd',
            $sign,
            intdiv($absolute, $divisor),
            $absolute % $divisor
        );
    }
}

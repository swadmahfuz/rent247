<?php

namespace App\Services;

class NumberToWords
{
    private array $ones = [
        '', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine',
        'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen',
        'Seventeen', 'Eighteen', 'Nineteen',
    ];

    private array $tens = [
        '', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety',
    ];

    public function convert(float|string $amount, string $currency = 'BDT'): string
    {
        $amount = round((float) $amount, 2);
        $negative = $amount < 0;
        $amount = abs($amount);

        $taka = (int) floor($amount);
        $paisa = (int) round(($amount - $taka) * 100);

        $words = $this->indianNumber($taka);

        if ($currency === 'BDT') {
            $result = trim($words) === '' ? 'Zero Taka' : $words . ' Taka';
            if ($paisa > 0) {
                $result .= ' And ' . $this->indianNumber($paisa) . ' Paisa';
            }
        } else {
            $result = trim($words) === '' ? 'Zero' : $words;
            if ($paisa > 0) {
                $result .= ' And ' . $this->indianNumber($paisa) . '/100';
            }
        }

        return ($negative ? 'Minus ' : '') . $result;
    }

    private function indianNumber(int $number): string
    {
        if ($number === 0) {
            return 'Zero';
        }

        $parts = [];

        $crore = intdiv($number, 10000000);
        $number %= 10000000;
        if ($crore > 0) {
            $parts[] = $this->belowThousand($crore) . ' Crore';
        }

        $lac = intdiv($number, 100000);
        $number %= 100000;
        if ($lac > 0) {
            $parts[] = $this->belowThousand($lac) . ' Lac';
        }

        $thousand = intdiv($number, 1000);
        $number %= 1000;
        if ($thousand > 0) {
            $parts[] = $this->belowThousand($thousand) . ' Thousand';
        }

        if ($number > 0) {
            $parts[] = $this->belowThousand($number);
        }

        return implode(' ', $parts);
    }

    private function belowThousand(int $number): string
    {
        $parts = [];

        $hundred = intdiv($number, 100);
        $number %= 100;
        if ($hundred > 0) {
            $parts[] = $this->ones[$hundred] . ' Hundred';
        }

        if ($number > 0) {
            if ($number < 20) {
                $parts[] = $this->ones[$number];
            } else {
                $ten = intdiv($number, 10);
                $one = $number % 10;
                $parts[] = $this->tens[$ten] . ($one ? ' ' . $this->ones[$one] : '');
            }
        }

        return implode(' ', $parts);
    }
}

<?php
namespace NovaExpress\Tests\Unit;

use NovaExpress\Helpers\Formatting;

class FormattingTest {
    public static function run(): void {
        // Test phone normalization
        $cases = [
            '+38 (067) 123-45-67' => '380671234567',
            '0671234567'          => '380671234567',
            '380671234567'        => '380671234567',
            '80671234567'         => '380671234567',
        ];
        foreach ($cases as $input => $expected) {
            $actual = Formatting::normalize_phone($input);
            if ($actual !== $expected) {
                throw new \RuntimeException("Failed normalize_phone: $input => $actual (expected $expected)");
            }
        }
        echo "FormattingTest passed.\n";
    }
}

<?php

namespace Tests\Unit;

use App\Models\ProductImageTask;
use App\Services\ProductImages\ImageLicensePolicy;
use App\Services\ProductImages\ImageVerificationService;
use PHPUnit\Framework\TestCase;

class ProductImageVerificationTest extends TestCase
{
    public function test_model_and_brand_mismatches_cannot_receive_high_confidence(): void
    {
        $task = new ProductImageTask([
            'name' => 'Galaxy A55',
            'brand' => 'Samsung',
            'model' => 'SM-A556E',
            'category' => 'Smartphone',
        ]);
        $verification = new ImageVerificationService;

        $this->assertGreaterThanOrEqual(
            0.68,
            $verification->confidence($task, 'Samsung Galaxy A55 SM-A556E smartphone', null),
        );
        $this->assertLessThan(
            0.68,
            $verification->confidence($task, 'Samsung Galaxy A54 SM-A546E smartphone', null),
        );
        $this->assertLessThan(
            0.68,
            $verification->confidence($task, 'Apple Galaxy A55 SM-A556E smartphone', null),
        );
    }

    public function test_exact_product_model_is_relevant_and_a_different_model_is_rejected(): void
    {
        $task = [
            'name' => 'Samsung Galaxy A55 5G',
            'brand' => 'Samsung',
            'model' => 'SM-A556',
        ];
        $verification = new ImageVerificationService;

        $this->assertTrue($verification->isRelevant(
            $task,
            'Samsung Galaxy A55 5G SM-A556',
            null,
            0.35,
        ));
        $this->assertFalse($verification->isRelevant(
            $task,
            'Samsung Galaxy A34 5G SM-A346',
            null,
            0.35,
        ));
    }

    public function test_only_supported_reuse_licenses_are_automatically_approved(): void
    {
        $policy = new ImageLicensePolicy;

        $this->assertTrue($policy->allowsAutomaticUse('CC BY 4.0', 'https://creativecommons.org/licenses/by/4.0/', 'Author'));
        $this->assertFalse($policy->allowsAutomaticUse('CC BY 4.0', 'https://creativecommons.org/licenses/by/4.0/'));
        $this->assertFalse($policy->allowsAutomaticUse('CC BY-NC 4.0', 'https://creativecommons.org/licenses/by-nc/4.0/'));
        $this->assertSame('restricted', $policy->classify('CC BY-NC 4.0', null));
        $this->assertSame('restricted', $policy->classify('CC BY-ND 4.0', null));
        $this->assertFalse($policy->allowsAutomaticUse(null, null));
    }

    public function test_duplicate_detector_uses_perceptual_hash_distance(): void
    {
        $verification = new ImageVerificationService;
        $baseHash = str_repeat('0', 64);
        $similarHash = str_repeat('1', 6).str_repeat('0', 58);
        $differentHash = str_repeat('1', 7).str_repeat('0', 57);

        $this->assertTrue($verification->isDuplicate($similarHash, [$baseHash]));
        $this->assertFalse($verification->isDuplicate($differentHash, [$baseHash]));
    }
}

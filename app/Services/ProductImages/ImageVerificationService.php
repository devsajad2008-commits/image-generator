<?php

namespace App\Services\ProductImages;

use App\Models\ProductImageTask;
use Illuminate\Support\Str;

class ImageVerificationService
{
    public function confidence(
        ProductImageTask|array $task,
        string $title,
        ?string $sourcePage,
        bool $allowSimilarProducts = false,
    ): float {
        $name = $task instanceof ProductImageTask ? $task->name : ($task['name'] ?? '');
        $category = $task instanceof ProductImageTask ? $task->category : ($task['category'] ?? '');
        $brandValue = $task instanceof ProductImageTask ? $task->brand : ($task['brand'] ?? null);
        $modelValue = $task instanceof ProductImageTask ? $task->model : ($task['model'] ?? null);
        $candidate = Str::lower($title.' '.($sourcePage ?? ''));
        $candidate = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $candidate) ?? '';
        $candidateWords = array_unique(array_filter(explode(' ', $candidate)));
        $productText = $name.($allowSimilarProducts ? ' '.$category : '');
        $productWords = array_unique(array_filter(explode(' ', preg_replace(
            '/[^\p{L}\p{N}]+/u',
            ' ',
            Str::lower($productText),
        ) ?? '')));
        $productWords = array_values(array_filter($productWords, fn (string $word): bool => mb_strlen($word) > 1));

        if ($productWords === []) {
            return 0.0;
        }

        $matched = count(array_intersect($productWords, $candidateWords));
        $score = 0.65 * ($matched / count($productWords));
        $candidateText = preg_replace('/[^\p{L}\p{N}]+/u', '', $candidate) ?? '';
        $candidateIdentifiers = $this->modelIdentifiers($candidate);
        $productIdentifiers = $this->modelIdentifiers($name.' '.($modelValue ?? ''));
        $modelFamilyMatches = $this->identifiersMatch($productIdentifiers, $candidateIdentifiers);

        if ($brandValue) {
            $brand = preg_replace('/[^\p{L}\p{N}]+/u', '', Str::lower($brandValue)) ?? '';
            if ($brand === '' || ! str_contains(implode('', $candidateWords), $brand)) {
                $score = min(0.4, $score);
            } else {
                $score += 0.2;
            }
        } else {
            $score += 0.1;
        }

        if ($modelValue) {
            $model = preg_replace('/[^\p{L}\p{N}]+/u', '', Str::lower($modelValue)) ?? '';
            if ($model !== '' && str_contains($candidateText, $model)) {
                $score += 0.25;
            } elseif ($modelFamilyMatches) {
                $score += 0.1;
            } else {
                $score = min(0.55, $score);
            }
        } else {
            $score += 0.15;
        }

        return round(min(1, $score), 3);
    }

    public function isRelevant(
        ProductImageTask|array $task,
        string $title,
        ?string $sourcePage,
        float $minimumConfidence,
        bool $allowSimilarProducts = false,
    ): bool {
        $name = $task instanceof ProductImageTask ? $task->name : ($task['name'] ?? '');
        $model = $task instanceof ProductImageTask ? $task->model : ($task['model'] ?? '');
        $candidateText = $title.' '.($sourcePage ?? '');
        $productIdentifiers = $this->modelIdentifiers((string) $name.' '.(string) ($model ?? ''));
        $candidateIdentifiers = $this->modelIdentifiers($candidateText);

        if ($productIdentifiers !== [] && $candidateIdentifiers !== []
            && ! $this->identifiersMatch($productIdentifiers, $candidateIdentifiers)) {
            return false;
        }

        return $this->confidence($task, $title, $sourcePage, $allowSimilarProducts) >= $minimumConfidence;
    }

    public function isDuplicate(string $hash, iterable $existingHashes): bool
    {
        foreach ($existingHashes as $existingHash) {
            if (! is_string($existingHash) || strlen($hash) !== strlen($existingHash)) {
                continue;
            }

            if (substr_count($hash ^ $existingHash, "\x01") <= 6) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function modelIdentifiers(string $text): array
    {
        preg_match_all('/(?<![\p{L}\p{N}])(?:SM[\s-]*)?[A-Z]{1,3}[\s-]?\d{2,5}[A-Z]?(?![\p{L}\p{N}])/iu', $text, $matches);

        return array_values(array_unique(array_map(
            fn (string $identifier): string => preg_replace('/[^\p{L}\p{N}]+/u', '', Str::lower($identifier)) ?? '',
            $matches[0] ?? [],
        )));
    }

    /**
     * @param  list<string>  $productIdentifiers
     * @param  list<string>  $candidateIdentifiers
     */
    private function identifiersMatch(array $productIdentifiers, array $candidateIdentifiers): bool
    {
        foreach ($productIdentifiers as $productIdentifier) {
            foreach ($candidateIdentifiers as $candidateIdentifier) {
                if ($productIdentifier === $candidateIdentifier
                    || str_starts_with($productIdentifier, $candidateIdentifier)
                    || str_starts_with($candidateIdentifier, $productIdentifier)) {
                    return true;
                }
            }
        }

        return false;
    }
}

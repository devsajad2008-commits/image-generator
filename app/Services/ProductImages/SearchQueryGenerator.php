<?php

namespace App\Services\ProductImages;

use App\Models\ProductImageTask;
use Illuminate\Support\Str;

class SearchQueryGenerator
{
    /**
     * @return list<string>
     */
    public function generate(ProductImageTask|array $task): array
    {
        $get = fn (string $key): mixed => $task instanceof ProductImageTask ? $task->{$key} : ($task[$key] ?? null);
        $settings = $task instanceof ProductImageTask
            ? ($task->batch?->settings ?? [])
            : ($task['settings'] ?? []);
        $compose = static function (mixed ...$values): string {
            $words = [];
            $seen = [];

            foreach (array_filter($values) as $value) {
                foreach (preg_split('/\s+/u', trim((string) $value), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
                    $normalized = Str::lower($word);

                    if (isset($seen[$normalized])) {
                        continue;
                    }

                    $seen[$normalized] = true;
                    $words[] = $word;
                }
            }

            return implode(' ', $words);
        };
        $providedTerms = $get('search_terms');
        $queries = is_array($providedTerms) ? $providedTerms : [];
        $generateAlternatives = $settings['generate_alternative_queries'] ?? true;

        if ($generateAlternatives || $queries === []) {
            $name = (string) ($get('name') ?? '');
            $brand = (string) ($get('brand') ?? '');
            $model = (string) ($get('model') ?? '');
            $productName = $compose($brand, $name);
            $modelSearchTerm = $model;
            $brandSearchTerm = $brand;

            if ($modelSearchTerm === ''
                && preg_match('/\bsamsung\b.*\bgalaxy\s+a55(?:\s+5g)?\b/i', $productName) === 1) {
                $modelSearchTerm = 'SM-A556';
            }

            if ($brandSearchTerm === ''
                && preg_match('/\bsamsung\b/i', $productName) === 1) {
                $brandSearchTerm = 'Samsung';
            }

            $imageCategory = $get('category');

            if (! is_string($imageCategory) || trim($imageCategory) === '') {
                $imageCategory = preg_match('/\b(phone|smartphone|galaxy|iphone)\b/i', $productName) === 1
                    ? 'smartphone'
                    : null;
            }

            $queries = array_merge($queries, [
                $compose($brand, $name, $model),
                $compose($brandSearchTerm, $modelSearchTerm),
                $compose($productName, 'official product image'),
                $compose($productName, 'front back'),
                $compose($productName, $imageCategory),
                $compose($name, $get('description')),
            ]);
        }

        $uniqueQueries = [];
        $seenQueries = [];

        foreach ($queries as $query) {
            if (! is_string($query) || trim($query) === '') {
                continue;
            }

            $query = trim($query);
            $normalized = Str::lower(preg_replace('/\s+/u', ' ', $query) ?? $query);

            if (isset($seenQueries[$normalized])) {
                continue;
            }

            $seenQueries[$normalized] = true;
            $uniqueQueries[] = $query;
        }

        $maxDepth = ($settings['search_mode'] ?? null) === 'comprehensive'
            ? max(12, (int) config('image_finder.max_search_depth'))
            : (int) config('image_finder.max_search_depth');

        return array_slice($uniqueQueries, 0, $maxDepth);
    }
}

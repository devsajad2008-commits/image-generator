<?php

namespace App\Services\ProductImages\Contracts;

interface ImageSearchProvider
{
    public function name(): string;

    /**
     * @return list<array{
     *     url: string,
     *     thumbnail_url: ?string,
     *     title: string,
     *     source_page_url: ?string,
     *     license: ?string,
     *     license_url: ?string,
     *     attribution: ?string,
     *     width: ?int,
     *     height: ?int,
     *     source_name: string
     * }>
     */
    public function search(string $query): array;
}

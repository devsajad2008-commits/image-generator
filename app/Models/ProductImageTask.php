<?php

namespace App\Models;

class ProductImageTask extends ImageFinderRecord
{
    /**
     * @var list<ProductImageCandidate>
     */
    public array $candidates = [];

    public ?ImageSearchBatch $batch = null;
}

<?php

namespace App\Models;

class ImageSearchBatch extends ImageFinderRecord
{
    /**
     * @var list<ProductImageTask>
     */
    public array $tasks = [];

    /**
     * @var list<ImageSearchActivity>
     */
    public array $activities = [];
}

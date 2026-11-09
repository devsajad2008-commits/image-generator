<?php

namespace App\Models;

class ProductImageCandidate extends ImageFinderRecord
{
    public ?ProductImageTask $task = null;
}

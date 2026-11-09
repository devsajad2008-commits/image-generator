<?php

namespace App\Services\ProductImages;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Imagick;
use RuntimeException;
use Throwable;

class ImageProcessingService
{
    /**
     * @return array{path: string, disk: string, width: int, height: int, hash: string}
     */
    public function process(string $inputPath, string $disk, string $directory): array
    {
        $image = new Imagick;
        $outputPath = tempnam(sys_get_temp_dir(), 'product-webp-');

        if ($outputPath === false) {
            throw new RuntimeException('Unable to allocate a temporary WebP file.');
        }

        try {
            if (config('image_finder.format') !== 'webp') {
                throw new RuntimeException('Only WebP output is supported.');
            }

            clearstatcache(true, $inputPath);
            $size = filesize($inputPath);

            if (! $size || $size > config('image_finder.max_file_size')) {
                throw new RuntimeException('Image is empty or exceeds the configured file-size limit.');
            }

            $mimeType = (new \finfo(FILEINFO_MIME_TYPE))->file($inputPath);

            if (! in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
                throw new RuntimeException('Downloaded content does not have a supported raster-image signature.');
            }

            Imagick::setResourceLimit(Imagick::RESOURCETYPE_MEMORY, 268435456);
            Imagick::setResourceLimit(Imagick::RESOURCETYPE_MAP, 536870912);
            $probe = new Imagick;
            try {
                $probe->pingImage($inputPath);
                $probeWidth = $probe->getImageWidth();
                $probeHeight = $probe->getImageHeight();
            } finally {
                $probe->clear();
            }

            if ($probeWidth < config('image_finder.min_width')
                || $probeHeight < config('image_finder.min_height')
                || $probeWidth * $probeHeight > 40000000) {
                throw new RuntimeException('Image dimensions do not meet the configured requirements.');
            }

            $image->readImage($inputPath);
            $image->autoOrient();

            if ($image->getNumberImages() !== 1) {
                throw new RuntimeException('Animated or multi-frame images are not accepted.');
            }

            $image->setIteratorIndex(0);
            $format = strtolower($image->getImageFormat());
            $width = $image->getImageWidth();
            $height = $image->getImageHeight();

            if (! in_array($format, ['jpeg', 'jpg', 'png', 'webp', 'gif'], true)
                || $width < config('image_finder.min_width')
                || $height < config('image_finder.min_height')
                || $width * $height > 40000000) {
                throw new RuntimeException('Image format or dimensions do not meet the configured requirements.');
            }

            $hash = $this->perceptualHash($image);
            $image->stripImage();
            $image->setImageFormat('webp');
            $image->setImageCompressionQuality(90);

            if (! $image->writeImage($outputPath)) {
                throw new RuntimeException('Unable to write the processed WebP image.');
            }

            $image->clear();

            if (filesize($outputPath) > config('image_finder.max_file_size')) {
                throw new RuntimeException('Processed image exceeds the configured file-size limit.');
            }

            $relativePath = trim($directory, '/').'/'.Str::uuid().'.webp';
            $stream = fopen($outputPath, 'rb');

            if ($stream === false || ! Storage::disk($disk)->put($relativePath, $stream)) {
                if (is_resource($stream)) {
                    fclose($stream);
                }

                throw new RuntimeException('Unable to save the processed WebP image.');
            }

            fclose($stream);

            return [
                'path' => $relativePath,
                'disk' => $disk,
                'width' => $width,
                'height' => $height,
                'hash' => $hash,
            ];
        } catch (Throwable $exception) {
            $image->clear();

            throw $exception instanceof RuntimeException
                ? $exception
                : new RuntimeException('Image could not be safely decoded or processed.', previous: $exception);
        } finally {
            if (is_file($outputPath)) {
                unlink($outputPath);
            }
        }
    }

    private function perceptualHash(Imagick $image): string
    {
        $sample = clone $image;
        $sample->setImageColorspace(Imagick::COLORSPACE_GRAY);
        $sample->resizeImage(8, 8, Imagick::FILTER_LANCZOS, 1);
        $pixels = [];

        for ($y = 0; $y < 8; $y++) {
            for ($x = 0; $x < 8; $x++) {
                $pixels[] = $sample->getImagePixelColor($x, $y)->getColorValue(Imagick::COLOR_RED);
            }
        }

        $sample->clear();
        $average = array_sum($pixels) / count($pixels);

        return implode('', array_map(fn (float $pixel): string => $pixel >= $average ? '1' : '0', $pixels));
    }
}

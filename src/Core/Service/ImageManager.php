<?php

namespace Core\Service;

use Core\Entity\Image;
use League\Flysystem\Filesystem;

/**
 * Class ImageManager
 * @package Core\Service
 */
class ImageManager
{
    /**
     * @var Filesystem
     */
    protected $filesystem;

    /**
     * @var array
     */
    protected $params;

    /** @var  Image */
    protected $image;

    /**
     * ImageManager constructor.
     *
     * @param array $params
     * @param Filesystem $filesystem
     */
    public function __construct($params, Filesystem $filesystem)
    {
        $this->params = $params;
        $this->filesystem = $filesystem;
    }

    /**
     * Process give source file with given options
     *
     * @param Image $image
     * @return string
     * @throws \Exception
     */
    public function process(Image $image)
    {
        //check restricted_domains is enabled
        if ($this->params['restricted_domains'] &&
            is_array($this->params['whitelist_domains']) &&
            !in_array(parse_url($image->getSourceFile(), PHP_URL_HOST), $this->params['whitelist_domains'])
        ) {
            throw  new \Exception('Restricted domains enabled, the domain your fetching from is not allowed: ' . parse_url($image->getSourceFile(), PHP_URL_HOST));

        }

        if ($this->filesystem->has($image->getNewFileName()) && $image->getOptions()['refresh']) {
            $this->filesystem->delete($image->getNewFileName());
        }
        if (!$this->filesystem->has($image->getNewFileName())) {
            $this->saveNewFile($image);
        }

        return $this->filesystem->read($image->getNewFileName());
    }

    /**
     * Save new FileName based on source file and list of options
     *
     * @param Image $image
     * @throws \Exception
     */
    public function saveNewFile(Image $image)
    {
        $faceCrop = $image->extractByKey('face-crop');
        $faceCropPosition = $image->extractByKey('face-crop-position');
        $faceBlur = $image->extractByKey('face-blur');

        $image->saveToTemporaryFile();

        $this->generateCmdString($image);


        if ($faceBlur) {
            $this->processBlurringFaces($image);
        }

        if ($faceCrop) {
            $this->processCroppingFaces($image, $faceCropPosition);
        }

        $this->execute($image->getFinalCommandStr());

        if ($this->filesystem->has($image->getNewFileName())) {
            $this->filesystem->delete($image->getNewFileName());
        }

        $this->filesystem->write($image->getNewFileName(), stream_get_contents(fopen($image->getNewFilePath(), 'r')));
    }

    /**
     * Face detection cropping
     *
     * @param Image $image
     * @param int $faceCropPosition
     */
    public function processCroppingFaces(Image $image, $faceCropPosition = 0)
    {
        $commandStr = "facedetect '{$image->getTemporaryFile()}'";
        $output = $this->execute($commandStr);
        if (empty($output[$faceCropPosition])) {
            return;
        }
        $geometry = explode(" ", $output[$faceCropPosition]);
        if (count($geometry) == 4) {
            list($geometryX, $geometryY, $geometryW, $geometryH) = $geometry;
            $cropCmdStr = "/usr/bin/convert '{$image->getTemporaryFile()}' -crop ${geometryW}x${geometryH}+${geometryX}+${geometryY} {$image->getTemporaryFile()}";
            $this->execute($cropCmdStr);
        }
    }

    /**
     * Blurring Faces
     *
     * @param Image $image
     */
    public function processBlurringFaces(Image $image)
    {
        $commandStr = "facedetect '{$image->getTemporaryFile()}'";
        $output = $this->execute($commandStr);
        if (empty($output)) {
            return;
        }
        foreach ((array)$output as $outputLine) {
            $geometry = explode(" ", $outputLine);
            if (count($geometry) == 4) {
                list($geometryX, $geometryY, $geometryW, $geometryH) = $geometry;
                $cropCmdStr = "/usr/bin/mogrify -gravity NorthWest -region ${geometryW}x${geometryH}+${geometryX}+${geometryY} -scale '10%' -scale '1000%' {$image->getTemporaryFile()}";
                $this->execute($cropCmdStr);
            }
        }
    }

    /**
     * Generate Command string bases on options
     *
     * @param Image $image
     */
    public function generateCmdString(Image $image)
    {
        $strip = $image->extractByKey('strip');
        $thread = $image->extractByKey('thread');
        $resize = $image->extractByKey('resize');

        list($size, $extent, $gravity) = $this->generateSize($image);

        // we default to thumbnail
        $resizeOperator = $resize ? 'resize' : 'thumbnail';
        $command = [];
        $command[] = "/usr/bin/convert " . $image->getTemporaryFile() . ' -' . $resizeOperator . ' ' . $size . $gravity . $extent . ' -colorspace sRGB';

        if (!empty($thread)) {
            $command[] = "-limit thread " . escapeshellarg($thread);
        }

        // strip is added internally by ImageMagick when using -thumbnail
        if (!empty($strip)) {
            $command[] = "-strip ";
        }

        foreach ($image->getOptions() as $key => $value) {
            if (!empty($value) && !in_array($key, ['quality', 'mozjpeg', 'refresh'])) {
                $command[] = "-{$key} " . escapeshellarg($value);
            }
        }

        $command = $this->checkMozJpeg($image, $command);
        $commandStr = implode(' ', $command);
        $image->setFinalCommandStr($commandStr);
    }

    /**
     * Check MozJpeg configuration if it's enabled and append it to main convert command
     *
     * @param Image $image
     * @param $command
     * @return array
     */
    private function checkMozJpeg(Image $image, $command)
    {
        $quality = $image->extractByKey('quality');
        if (is_executable($this->params['mozjpeg_path']) && $image->extractByKey('mozjpeg') == 1) {
            $command[] = "TGA:- | " . escapeshellarg($this->params['mozjpeg_path']) . " -quality " . escapeshellarg($quality) . " -outfile " . escapeshellarg($image->getNewFilePath()) . " -targa";
        } else {
            $command[] = "-quality " . escapeshellarg($quality) . " " . escapeshellarg($image->getNewFilePath());
        }
        return $command;
    }

    /**
     * Size and Crop logic
     *
     * @param Image $image
     * @return array
     */
    private function generateSize(Image $image)
    {
        $targetWidth = $image->extractByKey('width');
        $targetHeight = $image->extractByKey('height');
        $crop = $image->extractByKey('crop');

        $size = '';

        if ($targetWidth) {
            $size .= (string)$targetWidth;
        }
        if ($targetHeight) {
            $size .= (string)'x' . $targetHeight;
        }

        // When width and height a whole bunch of special cases must be taken into consideration.
        // resizing constraints (< > ^ !) can only be applied to geometry with both width AND height
        $preserveNaturalSize = $image->extractByKey('preserve-natural-size');
        $preserveAspectRatio = $image->extractByKey('preserve-aspect-ratio');
        $gravityValue = $image->extractByKey('gravity');
        $extent = '';
        $gravity = '';

        if ($targetWidth && $targetHeight) {
            $extent = ' -extent ' . $size;
            $gravity = ' -gravity ' . $gravityValue;
            $resizingConstraints = '';
            $resizingConstraints .= $preserveNaturalSize ? '\>' : '';
            if ($crop) {
                $resizingConstraints .= '^';
                //$extent .= '+repage';// still need to solve the combination of ^ , -extent and +repage . Will need to do calculations with the original image dimentions vs. the target dimentions.
            } else {
                $extent .= '+repage ';
            }
            $resizingConstraints .= $preserveAspectRatio ? '' : '!';
            $size .= $resizingConstraints;
        } else {
            $size .= $preserveNaturalSize ? '\>' : '';
        }

        return [$size, $extent, $gravity];
    }


    /**
     * Get the image Identity information
     * @param Image $image
     * @return string
     */
    public function getImageIdentity(Image $image)
    {
        $output = $this->execute('/usr/bin/identify ' . $image->getNewFilePath());
        return !empty($output[0]) ? $output[0] : "";
    }

    /**
     * @param $commandStr
     * @return string
     * @throws \Exception
     */
    private function execute($commandStr)
    {
        exec($commandStr, $output, $code);
        if (count($output) === 0) {
            $outputError = $code;
        } else {
            $outputError = implode(PHP_EOL, $output);
        }

        if ($code !== 0) {
            throw new \Exception("Command failed. The exit code: " . $outputError . "<br>The last line of output: " . $commandStr);
        }
        return $output;
    }
}

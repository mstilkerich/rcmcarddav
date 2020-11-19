<?php

declare(strict_types=1);

namespace MStilkerich\CardDavAddressbook4Roundcube;

use Psr\Log\LoggerInterface;
use Sabre\VObject;
use Sabre\VObject\Component\VCard;
use MStilkerich\CardDavClient\AddressbookCollection;

/**
 * This class is intended to delay the processing of photos until first use, and cache for later use.
 */
class DelayedPhotoLoader
{
    /**
     * @var int MAX_PHOTO_SIZE Maximum size of a photo dimension in pixels.
     *   Used when a photo is cropped for the X-ABCROP-RECTANGLE extension.
     */
    private const MAX_PHOTO_SIZE = 256;

    /** @var ?string $photoData The cached photo after having determined the first time. */
    private $photoData;

    /** @var VCard $vcard The VCard the photo belongs to */
    private $vcard;

    /** @var AddressbookCollection $davAbook Access to the CardDAV addressbook */
    private $davAbook;

    /** @var LoggerInterface $logger */
    private $logger;

    public function __construct(VCard $vcard, AddressbookCollection $davAbook, LoggerInterface $logger)
    {
        $this->vcard = $vcard;
        $this->davAbook = $davAbook;
        $this->logger = $logger;
    }

    /**
     * Retrieves the picture data.
     *
     * This is done as the implicit string conversion to allow triggering the retrieval in roundcube's code when the
     * photo data is actually requested.
     */
    public function __toString(): string
    {
        $this->logger->info("Unwrapping photo");
        return $this->photoData ?? $this->fetchFromRoundcubeCache() ?? $this->computePhotoFromProperty() ?? "";
    }

    /**
     * Computes the photo data from the PHOTO property.
     */
    private function computePhotoFromProperty(): ?string
    {
        $this->logger->info("Creating photo data from PHOTO property");
        $vcard = $this->vcard;
        $photo = $vcard->PHOTO;
        if (!isset($photo)) {
            return null;
        }

        // check if photo needs to be downloaded
        $kind = $photo['VALUE'];
        if (($kind instanceof VObject\Parameter) && strcasecmp('uri', (string) $kind) == 0) {
            $photoData = $this->downloadPhoto((string) $photo);
        } else {
            $photoData = (string) $photo;
        }

        if (isset($photoData)) {
            $photoData = $this->xabcropphoto($photoData);
        }

        if (isset($photoData)) {
            $this->photoData = $photoData;
            $this->storeToRoundcubeCache();
        }

        return $photoData;
    }

    private function downloadPhoto(string $uri): ?string
    {
        try {
            $this->logger->info("downloadPhoto: Attempt to download photo from $uri");
            $response = $this->davAbook->downloadResource($uri);
            return $response['body'];
        } catch (\Exception $e) {
            $this->logger->warning("downloadPhoto: Attempt to download photo from $uri failed: $e");
        }

        return null;
    }

    /**
     * Fetches the photo for this property from the roundcube cache (if available).
     *
     * The function checks that the cache object still matches the photo property, otherwise the cache object is pruned
     * and this function returns null to trigger a recomputation.
     *
     * @return ?string Returns the photo data from the roundcube cache, null if not present or outdated.
     */
    private function fetchFromRoundcubeCache(): ?string
    {
        // TODO
        //$this->photoData = ...;
        return null;
    }

    /**
     * Stores the photo data to the roundcube cache.
     *
     * The cache object includes a checksum that allows to check whether the stored object matches a possibly changed
     * PHOTO property on future retrieval.
     */
    private function storeToRoundcubeCache(): void
    {
        // TODO
    }

    /**
     * Compute the key for this photo property in the roundcube cache.
     */
    private function determineCacheKey(): string
    {
        // TODO
        return "carddav_" . $_SESSION['user_id'] . "_" ;
    }

    /******************************************************************************************************************
     ************                                   +         +         +                                  ************
     ************                               X-ABCROP-RECTANGLE Extension                               ************
     ************                                   +         +         +                                  ************
     *****************************************************************************************************************/

    /**
     * Crops the given photo if the PHOTO property contains an X-ABCROP-RECTANGLE parameter.
     *
     * The parameter looks like this:
     * X-ABCROP-RECTANGLE=ABClipRect_1&60&179&181&181&qZ54yqewvBZj2mycxrnqsA==
     *
     *  - The 1st number is the horizontal offset (X) from the left
     *  - The 2nd number is the vertical offset (Y) from the bottom
     *  - The 3rd number is the crop width
     *  - The 4th number is the crop height
     *
     * The meaning of the base64 encoded last part of the parameter is unknown and ignored.
     *
     * The resulting cropped photo is returned as binary string. In case the given photo lacks the X-ABCROP-RECTANGLE
     * parameter or the GD library is not available, the original data is returned unmodified.
     */
    private function xabcropphoto(string $photoData): string
    {
        if (!function_exists('gd_info')) {
            return $photoData;
        }

        $photo = $this->vcard->PHOTO;
        if (!isset($photo)) {
            return $photoData;
        }

        $abcrop = $photo['X-ABCROP-RECTANGLE'];
        if (!($abcrop instanceof VObject\Parameter)) {
            return $photoData;
        }

        $parts = explode('&', (string) $abcrop);
        $x = intval($parts[1]);
        $y = intval($parts[2]);
        $w = intval($parts[3]);
        $h = intval($parts[4]);
        $dw = min($w, self::MAX_PHOTO_SIZE);
        $dh = min($h, self::MAX_PHOTO_SIZE);

        $src = imagecreatefromstring($photoData);
        $dst = imagecreatetruecolor($dw, $dh);
        imagecopyresampled($dst, $src, 0, 0, $x, imagesy($src) - $y - $h, $dw, $dh, $w, $h);

        ob_start();
        imagepng($dst);
        $photoData = ob_get_contents();
        ob_end_clean();

        return $photoData;
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120

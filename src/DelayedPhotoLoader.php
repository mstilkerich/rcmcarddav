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

    /** @var VCard $vcard The VCard the photo belongs to */
    private $vcard;

    /** @var AddressbookCollection $davAbook Access to the CardDAV addressbook */
    private $davAbook;

    /** @var \rcube_cache $cache */
    private $cache;

    /** @var LoggerInterface $logger */
    private $logger;

    public function __construct(
        VCard $vcard,
        AddressbookCollection $davAbook,
        \rcube_cache $cache,
        LoggerInterface $logger
    ) {
        $this->vcard = $vcard;
        $this->davAbook = $davAbook;
        $this->cache = $cache;
        $this->logger = $logger;

        $logger->debug("Wrapping photo");
    }

    /**
     * Retrieves the picture data.
     *
     * This is done as the implicit string conversion to allow triggering the retrieval in roundcube's code when the
     * photo data is actually requested.
     */
    public function __toString(): string
    {
        $this->logger->debug("Unwrapping photo");
        return $this->fetchFromRoundcubeCache() ?? $this->computePhotoFromProperty() ?? "";
    }

    /**
     * Computes the photo data from the PHOTO property.
     */
    private function computePhotoFromProperty(): ?string
    {
        // true if the photo must be processed (downloaded/cropped) and the result should be cached
        // Photo that are stored inline in the VCard and provided as is will not be put in the cache
        $cachePhoto = false;

        $vcard = $this->vcard;
        $photo = $vcard->PHOTO;
        if (!isset($photo)) {
            return null;
        }

        $this->logger->debug("Creating photo data from PHOTO property");

        // check if photo needs to be downloaded
        $kind = $photo['VALUE'];
        if (($kind instanceof VObject\Parameter) && strcasecmp('uri', (string) $kind) == 0) {
            $cachePhoto = true;
            $photoData = $this->downloadPhoto((string) $photo);
        } else {
            $photoData = (string) $photo;
        }

        if (isset($photoData)) {
            $photoDataCrop = $this->xabcropphoto($photoData);
            if (isset($photoDataCrop)) {
                $cachePhoto = true;
                $photoData = $photoDataCrop;
            }
        }

        if (isset($photoData)) {
            if ($cachePhoto) {
                $this->storeToRoundcubeCache($photoData, $photo);
            } else {
                $this->logger->debug("Skip cache - PHOTO is stored in VCard");
            }
        }

        return $photoData;
    }

    private function downloadPhoto(string $uri): ?string
    {
        try {
            $this->logger->debug("downloadPhoto: Attempt to download photo from $uri");
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
        $photo = $this->vcard->PHOTO;
        if (!isset($photo)) {
            return null;
        }

        $key = $this->determineCacheKey();
        $cacheObject = $this->cache->get($key);

        if (!isset($cacheObject)) {
            $this->logger->debug("Roundcube cache miss $key");
            return null;
        }

        if (md5($photo->serialize()) !== $cacheObject["photoPropMd5"]) {
            $this->logger->debug("Roundcube cached photo outdated - removing $key");
            $this->cache->remove($key);
            return null;
        }

        $this->logger->debug("Roundcube cache hit $key");
        return $cacheObject["photo"];
    }

    /**
     * Stores the photo data to the roundcube cache.
     *
     * The cache object includes a checksum that allows to check whether the stored object matches a possibly changed
     * PHOTO property on future retrieval.
     */
    private function storeToRoundcubeCache(string $photoData, VObject\Property $photoProp): void
    {
        $photoPropMd5 = md5($photoProp->serialize());
        $cacheObject = [
            'photoPropMd5' => $photoPropMd5,
            'photo' => $photoData
        ];

        $key = $this->determineCacheKey();
        $this->logger->debug("Storing to roundcube cache $key");
        $this->cache->set($key, $cacheObject);
    }

    /**
     * Compute the key for this photo property in the roundcube cache.
     *
     * The key is composed of the following components separated by _:
     *   - a prefix "photo" (namespace to separate from other uses by the plugin)
     *   - a component including the user id of the roundcube user (only keys of logged in user can be retrieved);
     *     probably not needed as the cache itself is user-specific, but just in case.
     *   - a component containing the MD5 of the card UID (to find a photo cached for a VCard)
     */
    private function determineCacheKey(): string
    {
        $uid = (string) $this->vcard->UID;

        $key  = "photo_";
        $key .= $_SESSION['user_id'] . "_" ;
        $key .= md5($uid);

        return $key;
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
     * @return ?string The resulting cropped photo as binary string. Null in case the given photo was not modified,
     *                 e.g. for lack of the X-ABCROP-RECTANGLE parameter or GD is not available.
     */
    private function xabcropphoto(string $photoData): ?string
    {
        if (!function_exists('gd_info')) {
            return null;
        }

        $photo = $this->vcard->PHOTO;
        if (!isset($photo)) {
            return null;
        }

        $abcrop = $photo['X-ABCROP-RECTANGLE'];
        if (!($abcrop instanceof VObject\Parameter)) {
            return null;
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

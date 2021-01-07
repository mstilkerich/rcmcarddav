<?php

declare(strict_types=1);

namespace MStilkerich\CardDavAddressbook4Roundcube;

use Psr\Log\LoggerInterface;
use Sabre\VObject;
use Sabre\VObject\Component\VCard;
use MStilkerich\CardDavClient\AddressbookCollection;

/**
 * This class is intended to delay the processing of photos until first use, and cache for later use.
 *
 * Generally, photos that can be used as stored in the VCard will not be put to the cache. Only photos that are
 * processed are stored to roundcube cache to avoid reprocessing in the future. Currently, this processing can be:
 *   - Photo is referenced as external URI from the VCard and must be downloaded
 *   - Photo is cropped because of a set X-ABCROP-RECTANGLE parameter
 *
 * Instead of storing the final photo data in the data set returned to roundcube, an object of this class is stored.
 * When roundcube actually requires the data, an implicit conversion to string of the proxy object is triggered, which
 * then causes the photo to be either retrieved from the VCard, from the roundcube cache, or otherwise the processing is
 * done at this point in time.
 *
 * The goal of this mechanism is to perform the potentially expensive operation of photo processing only when the photo
 * is actually needed, and particularly not during the synchronization operation (for all photos that are part of the
 * synced vcards).
 *
 * @psalm-type PhotoCacheObject array{photoPropMd5: string, photo: string}
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
        return $this->computePhotoFromProperty();
    }

    /**
     * Computes the photo data from the PHOTO property.
     *
     * Processing and cache retrieval/update are performed as necessary.
     *
     * @return string The processed photo data. An empty string in case of error or no photo available.
     */
    private function computePhotoFromProperty(): string
    {
        $vcard = $this->vcard;
        $photoProp = $vcard->PHOTO;
        if (!isset($photoProp)) {
            return "";
        }

        $this->logger->debug("Creating photo data from PHOTO property");

        // First we determine whether the photo needs processing (download/crop)
        $cropProp = $photoProp['X-ABCROP-RECTANGLE'];

        // check if photo needs to be downloaded
        $kind = $photoProp['VALUE'];
        if (($kind instanceof VObject\Parameter) && strcasecmp('uri', (string) $kind) == 0) {
            $photoUri = (string) $photoProp;
        } else {
            $photoUri = null;
        }

        // true if the photo must be processed (downloaded/cropped) and the result should be cached
        // Photo that are stored inline in the VCard and provided as is will not be put in the cache
        $cachePhoto = ($cropProp instanceof VObject\Parameter) || isset($photoUri);

        // check roundcube cache
        if ($cachePhoto) {
            $photoData = $this->fetchFromRoundcubeCache($photoProp);
            if (isset($photoData)) {
                return $photoData;
            }
        }

        // retrieve PHOTO data
        if (isset($photoUri)) {
            $photoData = $this->downloadPhoto($photoUri);
        } else {
            $photoData = (string) $photoProp;
        }

        // crop photo if needed
        if (isset($photoData) && ($cropProp instanceof VObject\Parameter)) {
            $photoData = $this->xabcropphoto($photoData, $cropProp) ?? $photoData;
        }

        // store to cache if requested
        if (isset($photoData) && $cachePhoto) {
            $this->storeToRoundcubeCache($photoData, $photoProp);
        }

        return $photoData ?? "";
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
    private function fetchFromRoundcubeCache(VObject\Property $photoProp): ?string
    {
        $key = $this->determineCacheKey();
        /** @var ?PhotoCacheObject $cacheObject */
        $cacheObject = $this->cache->get($key);

        if (!isset($cacheObject)) {
            $this->logger->debug("Roundcube cache miss $key");
            return null;
        }

        if (md5($photoProp->serialize()) !== $cacheObject["photoPropMd5"]) {
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
        $userid = $_SESSION['user_id'];
        assert(is_string($userid), "user must be logged on to use photo cache");

        $key  = "photo_";
        $key .= $userid . "_" ;
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
     * For tests, this operation can be done using imagemagick, geometry is width x height +OffsetX +OffsetY
     *  convert raven.jpg -gravity SouthWest -crop '181x181+60+179' ravencrop.png
     *
     * The meaning of the base64 encoded last part of the parameter is unknown and ignored.
     *
     * @return ?string The resulting cropped photo as binary string. Null in case the given photo was not modified,
     *                 e.g. for lack of the X-ABCROP-RECTANGLE parameter or GD is not available.
     */
    private function xabcropphoto(string $photoData, VObject\Parameter $cropProp): ?string
    {
        if (!function_exists('gd_info')) {
            // @codeCoverageIgnoreStart
            return null;
            // @codeCoverageIgnoreEnd
        }

        $parts = explode('&', (string) $cropProp);
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

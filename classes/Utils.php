<?php

namespace Grav\Plugin\Mde;

use Grav\Common\Grav;

class Utils
{
    /**
     * Search for a file in multiple locations
     *
     * @param string $file Filename.
     * @param array $locations List of folders.
     *
     * @return string
     */
    public static function fileFinder(string $file, array $locations)
    {
        $return = false;
        foreach ($locations as $location) {
            if (file_exists($location . '/' . $file)) {
                $return = $location . '/' . $file;
                break;
            }
        }
        return $return;
    }


    /**
    * @return bool
    */
    public static function isProduct()
    {
        $paths = Grav::instance()['uri']->paths();

        if (isset($paths[0]) && $paths[0] === 'products') {
            return true;
        }

        return false;
    }

    /**
    * @return bool
    */
    public static function isAdvertorial()
    {
        $paths = Grav::instance()['uri']->paths();

        if (isset($paths[2]) && strpos($paths[2], 'advertorial') !== false) {
            return true;
        }

        return false;
    }

    /**
    * @return bool
    */
    public static function isPromotional()
    {
        $paths = Grav::instance()['uri']->paths();

        if (isset($paths[2]) && strpos($paths[2], 'promotional') !== false) {
            return true;
        }

        return false;
    }
}

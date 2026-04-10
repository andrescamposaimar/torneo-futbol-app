<?php

/**
 * ======================================================================
 * LICENSE: This file is subject to the terms and conditions defined in *
 * file 'license.txt', which is part of this source code package.       *
 * ======================================================================
 */

if (defined('AAM_KEY') && !defined('AAM_UTILITIES')) {
    //define extension constant as it's version #
    define('AAM_UTILITIES', '1.3.3');

    //register activate and extension classes
    $basedir = dirname(__FILE__);
    AAM_Autoloader::add('AAM_Utilities', $basedir . '/Utilities.php');

    AAM_Utilities::bootstrap();
}